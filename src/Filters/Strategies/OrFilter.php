<?php

namespace Abbasudo\Purity\Filters\Strategies;

use Abbasudo\Purity\Filters\Filter;
use Abbasudo\Purity\Filters\FilterProcessor;
use Abbasudo\Purity\Filters\Resolve;
use Closure;

class OrFilter extends Filter
{
    /**
     * Operator string to detect in the query params.
     *
     * @var string
     */
    protected static string $operator = '$or';

    /**
     * Apply filter logic to $query.
     *
     * @return Closure
     */
    public function apply(): Closure
    {
        return function ($query) {
            $query->where(function ($query) {
                foreach ($this->values as $filterGroup) {
                    $query->orWhere(function ($subQuery) use ($filterGroup) {
                        $model = $subQuery->getModel();
                        $processor = new FilterProcessor($model);
                        $processor->apply($subQuery, $filterGroup);
                    });
                }
            });
        };
    }
}
