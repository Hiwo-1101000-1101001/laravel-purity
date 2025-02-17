<?php

namespace Abbasudo\Purity\Traits;

use Abbasudo\Purity\Filters\FilterList;
use Abbasudo\Purity\Filters\FilterProcessor;
use Abbasudo\Purity\Filters\Resolve;
use Exception;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * The List of available filters can be set on the model otherwise it will be read from config.
 *
 * @property array $filters
 *
 * List of available fields, if not declared, will accept everything.
 * @property array $filterFields
 *
 * Fields will restrict to defined filters.
 * @property array $restrictedFilters
 * @property array $renamedFilterFields
 * @property array $userDefinedFilterFields;
 * @property array $sanitizedRestrictedFilters;
 */
trait Filterable
{
    use getColumns;

    private string $defaultFilterResolverClass = Resolve::class;

    /**
     * Returns full class name of the filter resolver.
     * Can be overridden in the model.
     *
     * @return string
     */
    protected function getFilterResolver(): string
    {
        return $this->defaultFilterResolverClass;
    }

    /**
     * Apply filters to the query builder instance.
     *
     * @param Builder    $query
     * @param array|null $params
     *
     * @throws Exception
     *
     * @return Builder
     */
    public function scopeFilter(Builder $query, array|null $params = null): Builder
    {
        $this->bootFilter();

        if ($params === null) {
            // Retrieve the filters from the request query
            $params = request()->query('filters', []);
        }

        // Apply each filter to the query builder instance
        $query = (new FilterProcessor($this))->apply($query, $params);

        return $query;
    }


    private function isAllowedFilter(string $field): bool
    {
        $allowedOperators = app(FilterList::class)->keys();

        if (in_array($field, $allowedOperators, true)) {
            return true;
        }

        return (isset($this->filterFields) && in_array($field, $this->filterFields, true))
            || (isset($this->filterRelations) && in_array($field, $this->filterRelations, true));
    }

    /**
     * boots filter bindings.
     *
     * @return void
     */
    private function bootFilter(): void
    {
        app()->singleton(FilterList::class, fn () => (new FilterList())->only($this->getFilters()));
        app()->bind(Resolve::class, fn () => new ($this->getFilterResolver())(app(FilterList::class), $this));
    }

    /**
     * @return array
     */
    private function getFilters(): array
    {
        return $this->filters ?? config('purity.filters', []);
    }

    /**
     * @param Builder      $query
     * @param array|string $filters
     *
     * @return Builder
     */
    public function scopeFilterBy(Builder $query, array|string $filters): Builder
    {
        $this->filters = is_array($filters) ? $filters : array_slice(func_get_args(), 1);
        return $query;
    }

    /**
     * @param string $field
     *
     * @return string
     */
    public function getField(string $field): string
    {
        return $this->realName(($this->renamedFilterFields ?? []) + $this->availableFields(), $field);
    }

    /**
     * @return array
     */
    public function availableFields(): array
    {
        return isset($this->filterFields) || isset($this->renamedFilterFields)
            ? $this->getUserDefinedFilterFields()
            : $this->getDefaultFields();
    }

    private function getDefaultFields(): array
    {
        return array_merge($this->getTableColumns(), $this->relations());
    }

    /**
     * Get formatted fields from filterFields.
     *
     * @return array
     */
    private function getUserDefinedFilterFields(): array
    {
        if (isset($this->userDefinedFilterFields)) {
            return $this->userDefinedFilterFields;
        }

        $fields = $this->filterFields ?? [];
        $renamed = $this->renamedFilterFields ?? [];
        return $this->userDefinedFilterFields = array_map(fn ($f) => $renamed[$f] ?? $f, $fields);
    }

    /**
     * @return array<int, string>
     */
    public function getRestrictedFilters(): array
    {
        return $this->sanitizedRestrictedFilters ??= $this->parseRestrictedFilters();
    }

    private function parseRestrictedFilters(): array
    {
        $filters = $this->restrictedFilters ?? [];

        return collect($filters)->mapWithKeys(function ($value, $key) {
            if (is_int($key) && Str::contains($value, ':')) {
                $field = Str::before($value, ':');
                $values = explode(',', Str::after($value, ':'));

                return [$field => $values];
            }

            return [$key => Arr::wrap($value)];
        })->all();
    }

    /**
     * @param string $field
     *
     * @return array<int, string>|null
     */
    public function getAvailableFiltersFor(string $field): array|null
    {
        return Arr::get($this->getRestrictedFilters(), $field);
    }

    /**
     *  list models relations.
     *
     * @return array
     */
    private function relations(): array
    {
        $methods = (new ReflectionClass(get_called_class()))->getMethods();
        return collect($methods)
            ->filter(fn($method) => !empty($method->getReturnType()) &&
                str_contains($method->getReturnType(), 'Illuminate\Database\Eloquent\Relations'))
            ->map(fn($method) => $method->name)
            ->values()
            ->all();
    }

    /**
     * @param Builder      $query
     * @param array|string $fields
     *
     * @return Builder
     */
    public function scopeFilterFields(Builder $query, array|string $fields): Builder
    {
        $this->filterFields = Arr::wrap($fields);
        return $query;
    }

    /**
     * Задает фильтруемые связи.
     *
     * @param Builder    $query
     * @param array|string $relations
     *
     * @return Builder
     */
    public function scopeFilterRelations(Builder $query, array|string $relations): Builder
    {
        $this->filterRelations = Arr::wrap($relations);
        return $query;
    }

    /**
     * @param Builder      $query
     * @param array|string $restrictedFilters
     *
     * @return Builder
     */
    public function scopeRestrictedFilters(Builder $query, array|string $restrictedFilters): Builder
    {
        $this->restrictedFilters = Arr::wrap($restrictedFilters);
        return $query;
    }

    /**
     * @param Builder $query
     * @param array   $renamedFilterFields
     *
     * @return Builder
     */
    public function scopeRenamedFilterFields(Builder $query, array $renamedFilterFields): Builder
    {
        $this->renamedFilterFields = $renamedFilterFields;
        return $query;
    }

    public function getFilterFields(): array
    {
        return array_map(fn($field) => $this->getTable() . '.' . $field, $this->getFilterFields());
    }

    public function getFilterRelations(): array
    {
        return $this->filterRelations;
    }
}

