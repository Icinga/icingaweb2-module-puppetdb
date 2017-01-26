<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Data\Filter\Filter;
use Icinga\Data\Filter\FilterAnd;
use Icinga\Data\Filter\FilterChain;
use Icinga\Data\Filter\FilterExpression;
use Icinga\Data\Filter\FilterOr;
use Icinga\Data\Filter\FilterNot;
use Icinga\Exception\QueryException;

/**
 * FilterRenderer
 *
 * @package Icinga\Module\Puppetdb
 */
class FilterRenderer
{
    /** @var Filter */
    protected $filter;

    /**
     * FilterRenderer constructor.
     *
     * @param Filter $filter
     */
    public function __construct(Filter $filter)
    {
        $this->filter = $filter;
    }

    /**
     * Factory helper
     *
     * @param Filter $filter
     *
     * @return static
     */
    public static function forFilter(Filter $filter)
    {
        return new static($filter);
    }

    /**
     * @return string
     */
    public function toJson()
    {
        return json_encode($this->toArray());
    }

    /**
     * Transform this filter to a PuppetDB query string
     *
     * @return string
     */
    public function toQueryString()
    {
        return 'query=' . rawurlencode($this->toJson());
        // return 'query=' . str_replace('%5B', '[', str_replace('%5D', ']', urlencode($this->toJson())));
    }

    /**
     * Transform this filter to an Array structure
     *
     * @return array
     */
    public function toArray()
    {
        return $this->filterToArray($this->filter);
    }

    /**
     * Transform a given Filter to an Array structure
     *
     * @param Filter $filter
     * @return array
     */
    protected function filterToArray(Filter $filter)
    {
        if ($filter instanceof FilterChain) {
            return $this->filterChainToArray($filter);
        } else {
            /** @var $filter FilterExpression */
            return $this->filterExpressionToArray($filter);
        }
    }

    /**
     * Transform a given FilterExpression to an Array structure
     *
     * @param FilterExpression $filter
     * @return array
     */
    protected function filterExpressionToArray(FilterExpression $filter)
    {
        return array($filter->getSign(), $filter->getColumn(), $filter->getExpression());
    }

    /**
     * Transform a given FilterExpression to an Array structure
     *
     * @param FilterChain $filter
     * @return array
     * @throws QueryException
     */
    protected function filterChainToArray(FilterChain $filter)
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
