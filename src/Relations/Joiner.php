<?php

namespace Sofa\Eloquence\Relations;

use LogicException;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\JoinClause as Join;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\MorphByMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphOneOrMany;
use Sofa\Eloquence\Contracts\Relations\Joiner as JoinerContract;

class Joiner implements JoinerContract
{
    /**
     * Processed query instance.
     *
     * @var \Illuminate\Database\Query\Builder
     */
    protected $query;

    /**
     * Parent model.
     *
     * @var \Illuminate\Database\Eloquent\Model
     */
    protected $model;

    /**
     * Create new joiner instance.
     *
     * @param \Illuminate\Database\Query\Builder
     * @param \Illuminate\Database\Eloquent\Model
     */
    public function __construct(Builder $query, Model $model)
    {
        $this->query = $query;
        $this->model = $model;
    }

    /**
     * Join related tables.
     *
     * @param  string $target
     * @param  string $type
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function join($target, $type = 'inner')
    {
        $related = $this->model;

        foreach (explode('.', $target) as $segment) {
            $related = $this->joinSegment($related, $segment, $type);
        }
        return $related;
    }

    /**
     * Left join related tables.
     *
     * @param  string $target
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function leftJoin($target)
    {
        return $this->join($target, 'left');
    }

    /**
     * Right join related tables.
     *
     * @param  string $target
     * @return \Illuminate\Database\Eloquent\Model
     */
    public function rightJoin($target)
    {
        return $this->join($target, 'right');
    }

    /**
     * Join relation's table accordingly.
     *
     * @param  \Illuminate\Database\Eloquent\Model $parent
     * @param  string $segment
     * @param  string $type
     * @return \Illuminate\Database\Eloquent\Model
     */
    protected function joinSegment(Model $parent, $segment, $type)
    {
        $relation = $parent->{$segment}();
        $related  = $relation->getRelated();
        $table    = $related->getTable() . (isset($relation->getParent()->relationsAliases[$segment]) ? ' as ' . $relation->getParent()->relationsAliases[$segment] : '');

        if ($relation instanceof BelongsToMany || $relation instanceof HasManyThrough) {
            $this->joinIntermediate($parent, $relation, $type);
        }
        if (!$this->alreadyJoined($join = $this->getJoinClause($parent, $relation, $table, $type, $segment))) {
            $this->query->joins[] = $join;
        }

        return $related;
    }

    /**
     * Determine whether the related table has been already joined.
     *
     * @param  \Illuminate\Database\Query\JoinClause $join
     * @return boolean
     */
    protected function alreadyJoined(Join $join)
    {
        return in_array($join, (array) $this->query->joins);
    }

    /**
     * Get the join clause for related table.
     *
     * @param  \Illuminate\Database\Eloquent\Model $parent
     * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @param  string $type
     * @param  string $table
     * @return \Illuminate\Database\Query\JoinClause
     */
    protected function getJoinClause(Model $parent, Relation $relation, $table, $type, $segment = null)
    {
        list($fk, $pk) = $this->getJoinKeys($relation, $segment);

        if (is_array($fk) && is_array($pk)) {
            $join = (new Join($this->query, $type, $table));
            foreach ($fk as $index => $key) {
                $join->on($key, '=', $pk[$index]);
            }
        } else {
            $join = (new Join($this->query, $type, $table))->on($fk, '=', $pk);
        }

        if (in_array(SoftDeletes::class, class_uses_recursive($relation->getRelated()))) {
            $join->whereNull($relation->getRelated()->getQualifiedDeletedAtColumn());
        }

        if ($relation instanceof MorphOneOrMany) {
            $join->where($relation->getQualifiedMorphType(), '=', $parent->getMorphClass());
        } elseif ($relation instanceof MorphToMany || $relation instanceof MorphByMany) {
            $join->where($relation->getMorphType(), '=', $parent->getMorphClass());
        }

        return $join;
    }

    /**
     * Join pivot or 'through' table.
     *
     * @param  \Illuminate\Database\Eloquent\Model $parent
     * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @param  string $type
     * @return void
     */
    protected function joinIntermediate(Model $parent, Relation $relation, $type)
    {
        if ($relation instanceof BelongsToMany) {
            $table = $relation->getTable();
            $fk = $relation->getQualifiedForeignPivotKeyName();
        } else {
            $table = $relation->getParent()->getTable();
            $fk = $relation->getQualifiedFirstKeyName();
        }

        $pk = $parent->getQualifiedKeyName();

        if (!$this->alreadyJoined($join = (new Join($this->query, $type, $table))->on($fk, '=', $pk))) {
            $this->query->joins[] = $join;
        }
    }

    /**
     * Get pair of the keys from relation in order to join the table.
     *
     * @param  \Illuminate\Database\Eloquent\Relations\Relation $relation
     * @return array
     *
     * @throws \LogicException
     */
    protected function getJoinKeys(Relation $relation, $segment = null)
    {

        if ($relation instanceof MorphTo) {
            throw new LogicException("MorphTo relation cannot be joined.");
        }

        if ($relation instanceof HasOneOrMany) {
            $foreignKey = [];

            if(is_array($relation->getQualifiedOwnerKeyName())) {
                foreach ($relation->getQualifiedForeignKeyName() as $key) {
                    $foreignKey[] = (isset($relation->getParent()->relationsAliases[$segment]) ? str_replace($relation->getRelated()->getTable(), $relation->getParent()->relationsAliases[$segment], $key) : $key);
                }
            } else {
                $foreignKey[] = (isset($relation->getParent()->relationsAliases[$segment]) ? str_replace($relation->getRelated()->getTable(), $relation->getParent()->relationsAliases[$segment], $relation->getQualifiedOwnerKeyName()) : $relation->getQualifiedOwnerKeyName());
            }

            $primaryKeys = $relation->getQualifiedParentKeyName();
            $pk = [];

            if(is_array($primaryKeys)) {
                foreach ($primaryKeys as $primaryKey) {
                    $table = explode('.', $primaryKey)[0];
                    $pk[] = (isset($relation->getParent()->alias) ? str_replace($relation->getParent()->getTable() . '.', $relation->getParent()->alias . '.', $primaryKey) : $primaryKey);
                }
            } else {
                $table = explode('.', $primaryKeys)[0];
                $pk[] = (isset($relation->getParent()->alias) ? str_replace($relation->getParent()->getTable() . '.', $relation->getParent()->alias . '.', $primaryKeys) : $primaryKeys);
            }

            return [$foreignKey, $pk];
        }

        if ($relation instanceof BelongsTo) {
            $foreignKey = [];

            if(is_array($relation->getQualifiedOwnerKeyName())) {
                foreach ($relation->getQualifiedOwnerKeyName() as $key) {
                    $foreignKey[] = (isset($relation->getParent()->relationsAliases[$segment]) ? str_replace($relation->getRelated()->getTable(), $relation->getParent()->relationsAliases[$segment], $key) : $key);
                }
            } else {
                $foreignKey[] = (isset($relation->getParent()->relationsAliases[$segment]) ? str_replace($relation->getRelated()->getTable(), $relation->getParent()->relationsAliases[$segment], $relation->getQualifiedOwnerKeyName()) : $relation->getQualifiedOwnerKeyName());
            }

            $primaryKeys = $relation->getQualifiedForeignKeyName();
            $pk = [];
            if(is_array($primaryKeys)) {
                foreach ($primaryKeys as $primaryKey) {
                    $table = explode('.', $primaryKey)[0];
                    $pk[] = (isset($relation->getRelated()->alias) ? str_replace($relation->getRelated()->getTable() . '.', $relation->getRelated()->alias . '.', $primaryKey) : $primaryKey);
                }
            } else {
                $table = explode('.', $primaryKeys)[0];
                $pk[] = (isset($relation->getRelated()->alias) ? str_replace($relation->getRelated()->getTable() . '.', $relation->getRelated()->alias . '.', $primaryKeys) : $primaryKeys);
            }
            return [$pk, $foreignKey];
        }

        if ($relation instanceof BelongsToMany) {
            return [$relation->getQualifiedRelatedPivotKeyName(), $relation->getParent()->getQualifiedKeyName()];
        }

        if ($relation instanceof HasManyThrough) {
            $fk = $relation->getQualifiedFarKeyName();

            return [$fk, $relation->getParent()->getQualifiedKeyName()];
        }
    }
}
