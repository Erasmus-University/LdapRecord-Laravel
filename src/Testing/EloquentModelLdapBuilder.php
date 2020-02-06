<?php

namespace LdapRecord\Laravel\Testing;

use Closure;
use Illuminate\Support\Arr;
use LdapRecord\Connection;
use LdapRecord\Models\BatchModification;
use LdapRecord\Query\Model\Builder;
use Ramsey\Uuid\Uuid;

class EloquentModelLdapBuilder extends Builder
{
    /**
     * The underlying database query.
     *
     * @var \Illuminate\Database\Eloquent\Builder
     */
    protected $query;

    /**
     * The nested query state.
     *
     * @var string|null
     */
    protected $nestedState;

    /**
     * Constructor.
     *
     * @param Connection $connection
     */
    public function __construct(Connection $connection)
    {
        parent::__construct($connection);

        $this->query = $this->newEloquentQuery();
    }

    /**
     * Create a new instance of the configured model.
     *
     * @return LdapObject
     */
    public function newEloquentModel()
    {
        return EloquentFactory::createModel();
    }

    /**
     * Create a new Eloquent query from the configured model.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function newEloquentQuery()
    {
        return $this->newEloquentModel()->query();
    }

    /**
     * {@inheritdoc}
     */
    public function newInstance($baseDn = null)
    {
        return (new self($this->connection))
            ->in($baseDn)
            ->setModel($this->model);
    }

    /**
     * Set the underlying Eloquent query builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     *
     * @return $this
     */
    public function setEloquentQuery($query)
    {
        $this->query = $query;

        return $this;
    }

    /**
     * Get the underlying Eloquent query builder.
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function getEloquentQuery()
    {
        return $this->query;
    }

    /**
     * Create a new nested query builder with the given state.
     *
     * @param Closure|null $closure
     * @param string       $state
     *
     * @return EloquentModelLdapBuilder|Builder
     */
    public function newNestedInstance(Closure $closure = null, $state = 'and')
    {
        $query = $this->newInstance()->nested()->setNestedQueryState($state);

        if ($closure) {
            $closure($query);
        }

        // Here we will merge the constraints from the nested
        // query instance to make sure any bindings are
        // carried over that were applied to it.
        $this->query->mergeConstraintsFrom(
            $query->getEloquentQuery()
        );

        return $query;
    }

    /**
     * {@inheritDoc}
     */
    public function orFilter(Closure $closure)
    {
        $query = $this->newNestedInstance($closure, 'or');

        return $this->rawFilter(
            $this->grammar->compileOr($query->getQuery())
        );
    }

    /**
     * Set the nested query state.
     *
     * @param string $state
     *
     * @return $this
     */
    public function setNestedQueryState($state)
    {
        $this->nestedState = $state;

        return $this;
    }

    /**
     * Find the Eloquent model by distinguished name.
     *
     * @param string $dn
     *
     * @return LdapObject|null
     */
    public function findEloquentModelByDn($dn)
    {
        return $this->query->where('dn', '=', $dn)->first();
    }

    /**
     * {@inheritdoc}
     */
    public function findOrFail($dn, $columns = ['*'])
    {
        if ($database = $this->findEloquentModelByDn($dn)) {
            return $this->transformDatabaseAttributesToLdapModel($database->toArray());
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addFilter($type, array $bindings)
    {
        $relationMethod = 'whereHas';

        // If the filter operator is "not has", we will flip it to
        // a "has" filter and change the relation method so
        // database results are retrieved properly.
        if ($bindings['operator'] == '!*') {
            $bindings['operator'] = '*';
            $relationMethod = 'whereDoesntHave';
        }

        // We're doing some trickery here for compatibility with nested LDAP filters. The
        // nested state is used to determine if the query being nested is an "and" or
        // "or" which will give us proper results when changing the relation method.
        if (
            $this->nested &&
            $this->nestedState = 'or' &&
            $this->fieldIsUsedMultipleTimes($type, $bindings['field'])
        ) {
            $relationMethod = $relationMethod == 'whereDoesntHave' ? 'orWhereDoesntHave' : 'orWhereHas';
        }

        $this->query->{$relationMethod}('attributes', function ($query) use ($bindings) {
            $this->addFilterToQuery($query, $bindings['field'], $bindings['operator'], $bindings['value']);
        });

        return parent::addFilter($type, $bindings);
    }

    /**
     * Determine if a certain field is used multiple times in a query.
     *
     * @param string $type
     * @param string $field
     *
     * @return bool
     */
    protected function fieldIsUsedMultipleTimes($type, $field)
    {
        return collect($this->filters[$type])->where('field', '=', $field)->isNotEmpty();
    }

    /**
     * {@inheritdoc}
     */
    public function insert($dn, array $attributes)
    {
        if (Arr::get($attributes, 'objectclass') == null) {
            throw new \Exception('LDAP objects must have the object classes to be created.');
        }

        $model = tap($this->newEloquentModel(), function ($model) use ($dn) {
            $model->dn = $dn;
            $model->name = $this->model->getCreatableRdn();
            $model->guid = Uuid::uuid4()->toString();
            $model->domain = $this->model->getConnectionName();
            $model->save();
        });

        foreach ($attributes as $name => $values) {
            $attribute = $model->attributes()->create(['name' => $name]);

            foreach ($values as $value) {
                $attribute->values()->create(['value' => $value]);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function update($dn, array $modifications)
    {
        /** @var LdapObject $model */
        $model = $this->findEloquentModelByDn($dn);

        if (! $model) {
            return false;
        }

        foreach ($modifications as $modification) {
            $this->applyBatchModificationToModel($model, $modification);
        }

        return true;
    }

    /**
     * Applies the batch modification to the given model.
     *
     * @param \Illuminate\Database\Eloquent\Model $model
     * @param array                               $modification
     *
     * @return void
     */
    protected function applyBatchModificationToModel($model, array $modification)
    {
        $name = $modification[BatchModification::KEY_ATTRIB];
        $type = $modification[BatchModification::KEY_MODTYPE];
        $values = $modification[BatchModification::KEY_VALUES] ?? [];

        $attribute = $model->attributes()->firstOrCreate(['name' => $name]);

        if ($type == LDAP_MODIFY_BATCH_REMOVE_ALL) {
            $attribute->delete();

            return;
        } elseif ($type == LDAP_MODIFY_BATCH_REMOVE) {
            $attribute->values()->whereIn('value', $values)->delete();
        } elseif ($type == LDAP_MODIFY_BATCH_ADD) {
            foreach ($values as $value) {
                $attribute->values()->create(['value' => $value]);
            }
        } elseif ($type == LDAP_MODIFY_BATCH_REPLACE) {
            $attribute->values()->delete();

            foreach ($values as $value) {
                $attribute->values()->create(['value' => $value]);
            }
        }

        $attribute->save();
    }

    /**
     * {@inheritdoc}
     */
    public function rename($dn, $rdn, $newParentDn, $deleteOldRdn = true)
    {
        $database = $this->findEloquentModelByDn($dn);

        if ($database) {
            $database->name = $rdn;
            $database->dn = implode(',', [$rdn, $newParentDn]);

            return $database->save();
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($dn)
    {
        $database = $this->findEloquentModelByDn($dn);

        return $database ? $database->delete() : false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAttributes($dn, array $attributes)
    {
        if ($database = $this->findEloquentModelByDn($dn)) {
            $database->attributes()->whereIn('name', $attributes)->delete();

            return true;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function escape($value, $ignore = '', $flags = 0)
    {
        // Don't escape values.
        return $value;
    }

    /**
     * {@inheritdoc}
     */
    public function paginate($pageSize = 1000, $isCritical = false)
    {
        return $this->get();
    }

    /**
     * {@inheritdoc}
     */
    public function run($query)
    {
        if ($this->limit > 0) {
            $this->query->limit($this->limit);
        }

        if ($this->dn) {
            // Here we'll apply the distinguished name scope to
            // ensure the proper results are returned when
            // searching "inside" of LdapRecord models.
            $this->query->where('dn', 'like', "%{$this->dn}");
        }

        return $this->query->get();
    }

    /**
     * Adds an LDAP "Where" filter to the underlying Eloquent builder.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string      $field
     * @param string      $operator
     * @param string|null $value
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    protected function addFilterToQuery($query, $field, $operator, $value)
    {
        switch ($operator) {
            case '*':
                return $query->where('name', '=', $field);
            case '!*':
                return $query->where('name', '!=', $field);
            case '=':
                return $query->where('name', $operator, $field)
                        ->whereHas('values', function ($q) use ($operator, $value) {
                            $q->where('value', $operator, $value);
                        });
            case '!':
                // Fallthrough.
            case '!=':
                return $query->where('name', '=', $field)
                        ->whereHas('values', function ($q) use ($operator, $value) {
                            $q->where('value', '!=', $value);
                        });
            case'>=':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', '>=', $value);
                    });
            case'<=':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', '<=', $value);
                    });
            case'~=':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', 'like', "%$value%");
                    });
            case'starts_with':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', 'like', "$value%");
                    });
            case'not_starts_with':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', 'not like', "$value%");
                    });
            case'ends_with':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', 'like', "%$value");
                    });
            case'not_ends_with':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', 'not like', "%$value");
                    });
            case'contains':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', 'like', "%$value%");
                    });
            case'not_contains':
                return $query->where('name', '=', $field)
                    ->whereHas('values', function ($q) use ($operator, $value) {
                        $q->where('value', 'not like', "%$value%");
                    });
        }
    }

    /**
     * {@inheritdoc}
     */
    protected function parse($resource)
    {
        return $resource->toArray();
    }

    /**
     * {@inheritdoc}
     */
    protected function process(array $results)
    {
        return $this->model->newCollection($results)->transform(function ($attributes) {
            return $this->transformDatabaseAttributesToLdapModel($attributes);
        });
    }

    /**
     * Transforms an array of database attributes into an LdapRecord model.
     *
     * @param array $attributes
     *
     * @return \LdapRecord\Models\Model
     */
    protected function transformDatabaseAttributesToLdapModel(array $attributes)
    {
        $dn = Arr::pull($attributes, 'dn');

        $transformedAttributes = collect(Arr::pull($attributes, 'attributes'))->mapWithKeys(function ($attribute) {
            $values = collect($attribute['values'])->map->value->toArray();

            return [$attribute['name'] => $values];
        })->toArray();

        return $this->model->newInstance()
            ->setDn($dn)
            ->setRawAttributes($transformedAttributes);
    }
}
