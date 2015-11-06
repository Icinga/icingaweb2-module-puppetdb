<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Data\Filter\Filter;
use Icinga\Data\Filter\FilterAnd;
use Icinga\Data\Filter\FilterOr;
use Icinga\Data\Filter\FilterNot;
use Icinga\Exception\QueryException;

class FilterRenderer
{
    protected $filter;

    public function __construct(Filter $filter)
    {
        $this->filter = $filter;
    }

    public static function forFilter(Filter $filter)
    {
        return new FilterRenderer($filter);
    }

    public function toJson()
    {
        return json_encode($this->toArray());
    }

    public function toQueryString()
    {
        return 'query=' . rawurlencode($this->toJson());
        // return 'query=' . str_replace('%5B', '[', str_replace('%5D', ']', urlencode($this->toJson())));
    }

    public function toArray()
    {
        return $this->filterToArray($this->filter);
    }

    protected function filterToArray(Filter $filter)
    {
        if ($filter->isChain()) {
            return $this->filterChainToArray($filter);
        } else {
            return $this->filterExpressionToArray($filter);
        }

        return $array;
    }

    protected function filterExpressionToArray($filter)
    {
        return array($filter->getSign(), $filter->getColumn(), $filter->getExpression());
    }

    protected function filterChainToArray(Filter $filter)
    {
        if ($filter instanceof FilterAnd) {
            $op = 'and';
        } elseif ($filter instanceof FilterOr) {
            $op = 'or';
        } elseif ($filter instanceof FilterNot) {
            $op = 'not';
        } else {
            throw new QueryException('Cannot render filter: %s', $filter);
        }

        $parts = array($op);
        if (! $filter->isEmpty()) {
            foreach ($filter->filters() as $f) {
                $parts[] = $this->filterToArray($f);
            }
        }

        return $parts;
    }
}
