<?php

namespace Abbasudo\Purity\Filters\Strategies;

use Abbasudo\Purity\Filters\Filter;
use Abbasudo\Purity\Filters\FilterProcessor;
use Abbasudo\Purity\Filters\Resolve;
use Closure;

class AndFilter extends Filter
{
    /**
     * Operator string to detect in the query params.
     *
     * @var string
     */
    protected static string $operator = '$and';

    /**
     * Apply filter logic to $query.
     *
     * @return Closure
     */
    public function apply(): Closure
    {
        return function ($query) {
            foreach ($this->values as $filterGroup) {
                $query->where(function ($subQuery) use ($filterGroup) {
                    $model = $subQuery->getModel();
                    $processor = new FilterProcessor($model);
                    $processor->apply($subQuery, $filterGroup);
                });
            }
        };
    }
}
