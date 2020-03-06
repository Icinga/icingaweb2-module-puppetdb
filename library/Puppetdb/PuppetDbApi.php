<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Data\Filter\Filter;

class PuppetDbApi extends BaseApi
{
    protected $baseUrls = [
        'v1' => '',
        'v2' => '/v2',
        'v3' => '/v3',
        'v4' => '/pdb/query/v4'
    ];

    /** @var string */
    protected $orderBy;

    /**
     * PuppetDbApi constructor.
     * @param $version
     * @param $certname
     * @param $host
     * @param int $port
     */
    public function __construct($version, $certname, $host, $port = 8081)
    {
        parent::__construct($version, $certname, $host, $port);
        if ($version === 'v4') {
            $this->orderBy = 'order_by';
        } else {
            $this->orderBy = 'order-by';
        }
    }

    /**
     * @return array
     */
    public function listFactNames()
    {
        // Min version: v2
        if ($this->version === 'v1') {
            return [];
        }

        return \json_decode($this->get('fact-names'));
    }

    /**
     * @return array
     */
    public function enumResourceTypes()
    {
        if ($this->version !== 'v4') {
            return [];
        }

        $order = [
            ['field' => 'exported', 'order' => 'desc'],
            ['field' => 'type', 'order' => 'asc']
        ];

        $url = 'resources?' . $this->query([
            'extract',
            [['function', 'count'], 'type', 'exported'],
            ['~', 'type', '.'],
            ['group_by', 'type', 'exported']
            ]) . '&' . $this->orderBy($order);

        $enum = [];
        foreach (json_decode($this->get($url)) as $res) {
            if ($res->exported) {
                $name = '@@' . $res->type;
            } else {
                $name = $res->type;
            }
            $enum[$name] = \sprintf('%s (%d)', $name, $res->count);
        }

        return $enum;
    }

    protected function query($query = null)
    {
        if ($query === null) {
            return '';
        } else {
            return $this->encodeParameter('query', $query);
        }
    }

    protected function orderBy($order)
    {
        return $this->encodeParameter($this->orderBy, $order);
    }

    /**
     * @return array
     */
    public function classes()
    {
        $classes = [];
        $order = [
            ['field' => 'certname', 'order' => 'asc'],
            ['field' => 'title',    'order' => 'asc']
        ];

        $url = 'resources?'
             . $this->encodeParameter('query', ['=', 'type', 'Class'])
             . '&' . $this->encodeParameter($this->orderBy, $order)
             ;

        foreach ($this->fetchLimited($url) as $entry) {
            if (! \array_key_exists($entry->certname, $classes)) {
                $classes[$entry->certname] = [];
            }

            $classes[$entry->certname][] = $entry->title;
        }

        return $classes;
    }

    protected function fetchLimited($url)
    {
        $remaining = true;
        $step      = 3000;
        $offset    = 0;
        $cnt       = 0;
        $result = [];
        $url .= '&limit=' . ($step + 1) . '&offset=';

        while ($remaining) {
            $remaining = false;
            foreach (\json_decode($this->get($url . $offset)) as $entry) {
                $cnt++;
                if ($cnt > $step) {
                    $cnt = 0;
                    $offset += $step;
                    $remaining = true;
                    break;
                }

                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param Filter $filter
     * @param bool $exported
     * @return array
     * @throws \Icinga\Exception\QueryException
     */
    public function fetchResources(Filter $filter = null, $exported = null)
    {
        if ($filter === null || $filter->isEmpty()) {
            $query = null;
        } else {
            $query = FilterRenderer::forFilter($filter)->toArray();
        }

        if ($exported !== null) {
            if ($query === null) {
                $query = ['=', 'exported', $exported];
            } else {
                $query = [
                    'and',
                    ['=', 'exported', $exported],
                    $query
                ];
            }
        }

        $url = 'resources';
        $columns = [
            'certname',
            'type',
            'title',
            'exported',
            'parameters',
            'environment',
            // 'tags' -> on demand?
        ];
        if ($query !== null) {
            if ($this->version === 'v4') {
                $query = ['extract', $columns, $query];
            }
            $url .= '?' . $this->encodeParameter('query', $query);
        }

        if (\in_array('type', $filter->listFilteredColumns())) {
            $order = [['field' => 'title', 'order' => 'asc']];
        } else {
            $order = [
                ['field' => 'type', 'order' => 'asc'],
                ['field' => 'title', 'order' => 'asc']
            ];
        }

        if ($exported === null) {
            \array_unshift(
                $order,
                ['field' => 'exported', 'order' => 'desc']
            );
        }

        $url .= '&' . $this->orderBy($order);

        return $this->fetchLimited($url);
    }

    /**
     * @param string $type
     * @param Filter $filter
     * @return array
     * @throws \Icinga\Exception\QueryException
     */
    public function fetchResourcesByType($type, Filter $filter = null)
    {
        if (\substr($type, 0, 2) === '@@') {
            $exported = true;
            $type = \substr($type, 2);
        } else {
            $exported = false;
        }
        if ($filter === null) {
            $filter = Filter::fromQueryString('type=' . $type);
        } else {
            $filter = $filter->andFilter(Filter::fromQueryString('type=' . $type));
        }

        return $this->fetchResources($filter, $exported);
    }

    /**
     * @param Filter $filter
     * @return array
     * @throws \Icinga\Exception\QueryException
     */
    public function fetchFacts(Filter $filter = null)
    {
        $unStringify = true;

        if ($filter === null) {
            $facts = $this->get('facts');
        } else {
            $facts = $this->get('facts?' . $this->renderFilter($filter));
        }

        $result = [];
        foreach (\json_decode($facts) as $row) {
            if (! \array_key_exists($row->certname, $result)) {
                $result[$row->certname] = (object) [];
            }
            // What to do with row->environment on newer versions?
            if ($unStringify && \is_string($row->value)) {
                $first = \substr($row->value, 0, 1);
                $last  = \substr($row->value, -1);
                if (($first === '{' && $last === '}')
                    || ($first === '[' && $last === ']')
                    || ($first === '"' && $last === '"')
                ) {
                    $result[$row->certname]->{$row->name} = \json_decode($row->value);
                } else {
                    $result[$row->certname]->{$row->name} = $row->value;
                }
            } else {
                $result[$row->certname]->{$row->name} = $row->value;
            }
        }
        \ksort($result);

        return $result;
    }

    /**
     * @param Filter $filter
     * @return string
     * @throws \Icinga\Exception\QueryException
     */
    protected function renderFilter(Filter $filter)
    {
        return FilterRenderer::forFilter($filter)->toQueryString();
    }
}
