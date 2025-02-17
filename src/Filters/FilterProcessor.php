<?php

namespace Abbasudo\Purity\Filters;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;

class FilterProcessor
{
    public function __construct(
        protected Model $model
    ) {
    }

    public function apply(Builder $query, array $filters): Builder
    {
        return $this->processFilters($query, $filters, $this->model);
    }

    protected function processFilters(Builder $query, array $filters, Model $model): Builder
    {
        // Собираем маппинг операторов из конфига
        $strategiesMapping = $this->getStrategiesMapping();

        foreach ($filters as $key => $value) {
            // Если ключ соответствует названию связи (relation)
            if ($this->isRelation($model, $key)) {
                if (!$this->isAllowedRelation($model, $key)) {
                    continue; // Связь не разрешена для фильтрации
                }

                $query->whereHas($key, function (Builder $q) use ($value, $model, $key) {
                    $relatedModel = $this->getRelatedModel($model, $key);
                    $this->processFilters($q, $value, $relatedModel);
                });
            }
            // Если ключ соответствует полю модели
            elseif ($this->isField($model, $key)) {
                // Если значение не массив — приводим к массиву с оператором по умолчанию $eq
                if (!is_array($value)) {
                    $value = ['$eq' => $value];
                }
                // Для каждого оператора применяем соответствующую стратегию
                foreach ($value as $operator => $operand) {
                    // Если значение – массив с одним элементом, извлекаем этот элемент
                    if (is_array($operand) && count($operand) === 1) {
                        $operand = array_shift($operand);
                    }
                    if (!isset($strategiesMapping[$operator])) {
                        throw new \Exception("Unsupported operator {$operator} for field {$key}");
                    }
                    $strategyClass = $strategiesMapping[$operator];
                    // Создаём стратегию с нужными аргументами: $query, имя колонки, массив значений
                    $filter = new $strategyClass($query, $key, [$key => $operand]);
                    $closure = $filter->apply();
                    $query->where($closure);
                }
            }
            // Если ключ — логический оператор (например, $or, $and)
            elseif ($this->isLogicalOperator($key)) {
                if (!isset($strategiesMapping[$key])) {
                    throw new \Exception("Unsupported logical operator: {$key}");
                }
                $strategyClass = $strategiesMapping[$key];
                // Передаём в конструктор: $query, пустую строку (так как колонка не нужна), и значения оператора
                $filter = new $strategyClass($query, '', $value);
                $closure = $filter->apply();
                $query->where($closure);
            }
            // Если ключ не является ни связью, ни полем, ни логическим оператором
            else {
                throw new \Exception("Filter key '{$key}' is not a valid field or relation on model " . get_class($model));
            }
        }

        return $query;
    }



    protected function isLogicalOperator(string $key): bool
    {
        return in_array($key, ['$or', '$and'], true);
    }

    protected function getFilters(): array
    {
        return config('purity.filters', []);
    }

    protected function isRelation(Model $model, string $relation): bool
    {
        return property_exists($model, 'filterRelations') && in_array($relation, $model->filterRelations, true);
    }

    protected function isField(Model $model, string $field): bool
    {
        return property_exists($model, 'filterFields') && in_array($field, $model->filterFields, true);
    }

    protected function isAllowedRelation(Model $model, string $relation): bool
    {
        return $this->isRelation($model, $relation);
    }

    protected function getRelatedModel(Model $model, string $relation): Model
    {
        $relationObj = $model->$relation();
        return $relationObj->getRelated();
    }

    protected function getStrategiesMapping(): array
    {
        $strategies = config('purity.filters', []);
        $mapping = [];

        foreach ($strategies as $strategyClass) {
            if (property_exists($strategyClass, 'operator')) {
                $operator = $strategyClass::operator();
                $mapping[$operator] = $strategyClass;
            }
        }

        return $mapping;
    }
}
