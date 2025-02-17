<?php

namespace Abbasudo\Purity\Filters;

use Abbasudo\Purity\Exceptions\FieldNotSupported;
use Abbasudo\Purity\Exceptions\NoOperatorMatch;
use Abbasudo\Purity\Exceptions\OperatorNotSupported;
use Closure;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Resolve
{
    /**
     * List of fields
     *
     * @var array
     */
    private array $fields = [];

    /**
     * List of relations
     *
     * @var array
     */
    private array $relations = [];

    private array $previousModels = [];

    /**
     * @param FilterList $filterList
     * @param Model      $model
     */
    public function __construct(private FilterList $filterList, private Model $model)
    {}

    /**
     * @param Builder      $query
     * @param string       $field
     * @param array|string $values
     *
     * @throws Exception
     * @throws Exception
     *
     * @return void
     */
    public function apply(Builder $query, string $field, array|string $values, string $table): void
    {
        if (!$this->safe(fn () => $this->validate([$field => $values]))) {
            return;
        }

        $this->filter($query, $field, $table, $values);
    }

    /**
     * run functions with or without exception.
     *
     * @param Closure $closure
     *
     * @throws Exception
     * @throws Exception
     *
     * @return bool
     */
    private function safe(Closure $closure): bool
    {
        try {
            $closure();
            return true;
        } catch (Exception $exception) {
            if (config('purity.silent')) {
                return false;
            }

            throw $exception;
        }
    }

    /**
     * @param array|string $values
     *
     * @return void
     */
    private function validate(array|string $values = [])
    {
        if (empty($values) || is_string($values)) {
            throw NoOperatorMatch::create($this->filterList->keys());
        }

        if (!in_array(key($values), $this->filterList->keys())) {
            $this->validate(array_values($values)[0]);
        }
    }

    /**
     * Apply a single filter to the query builder instance.
     *
     * @param Builder           $query
     * @param string            $field
     * @param string            $table
     * @param array|string|null $filters
     *
     * @throws Exception
     * @throws Exception
     *
     * @return void
     */
    private function filter(Builder $query, string $field, string $table, array|string|null $filters): void
    {
        // Ensure that the filter is an array
        $filters = is_array($filters) ? $filters : [$filters];

        if ($this->filterList->get($field) !== null) {
            $this->safe(fn () => $this->applyFilterStrategy($query, $field, $filters));
        } else {
            $this->safe(fn () => $this->applyRelationFilter($query, $field, $filters, $table));
        }
    }

    /**
     * @param Builder $query
     * @param string  $operator
     * @param array   $filters
     *
     * @return void
     */
    private function applyFilterStrategy(Builder $query, string $operator, array $filters): void
    {
        $filter = $this->filterList->get($operator);
        $field = end($this->fields);
        $callback = (new $filter($query, $field, $filters))->apply();
        $this->filterRelations($query, $callback);
    }

    /**
     * @param Builder $query
     * @param Closure $callback
     *
     * @return void
     */
    private function filterRelations(Builder $query, Closure $callback): void
    {
        array_pop($this->fields);
        $this->applyRelations($query, $callback);
    }

    /**
     * Resolve nested relations if any.
     *
     * @param Builder $query
     * @param Closure $callback
     *
     * @return void
     */
    private function applyRelations(Builder $query, Closure $callback): void
    {
        if (empty($this->fields)) {
            $callback($query);
        } else {
            $this->relation($query, $callback);
        }
    }

    /**
     * @param Builder $query
     * @param Closure $callback
     *
     * @return void
     */
    private function relation(Builder $query, Closure $callback)
    {
        // remove the last field until its empty
        $relation = array_shift($this->relations);
        $query->whereHas($relation, fn ($subQuery) => $this->applyRelations($subQuery, $callback));
    }

    /**
     * @param Builder $query
     * @param string  $field
     * @param array   $filters
     * @param string  $table
     *
     * @throws Exception
     *
     * @return void
     */
    private function applyRelationFilter(
        Builder $query,
        string $field,
        array $filters,
        string $table
    ): void
    {
        foreach ($filters as $subField => $subFilter) {
            $this->prepareModelForRelation($field);
            // $this->validateField($field);
            $this->validateOperator($field, $subField);

            if ($this->isRelationField($field)) {
                $this->relations[] = $field;
            } else {
                $this->fields[] = "{$table}.{$this->model->getField($field)}";
            }

            $this->filter($query, $subField, $table, $subFilter);
        }

        $this->restorePreviousModel();
    }

    private function isRelationField(string $field): bool
    {
        return in_array($field, $this->model->getFilterRelations(), true);
    }

    private function prepareModelForRelation(string $field): void
    {
        $relation = end($this->relations);
        if ($relation !== false) {
            $this->previousModels[] = $this->model;
            $this->model = $this->model->$relation()->getRelated();
        }
    }

    private function restorePreviousModel(): void
    {
        array_pop($this->relations);
        if (!empty($this->previousModels)) {
            $this->model = array_pop($this->previousModels);
        }
    }

    /**
     * @param string $field
     *
     * @return void
     */
    private function validateField(string $field): void
    {
        $availableFields = $this->model->availableFields();
        if (!in_array($field, $availableFields)) {
            throw FieldNotSupported::create($field, $this->model::class, $availableFields);
        }
    }

    /**
     * @param string $field
     * @param string $operator
     *
     * @return void
     */
    private function validateOperator(string $field, string $operator): void
    {
        $availableFilters = $this->model->getAvailableFiltersFor($field);
        if (!$availableFilters || in_array($operator, $availableFilters)) {
            return;
        }

        throw OperatorNotSupported::create($field, $operator, $availableFilters);
    }
}
