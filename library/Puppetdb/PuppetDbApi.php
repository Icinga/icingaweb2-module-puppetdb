<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Data\Filter\Filter;
use Icinga\Exception\IcingaException;
use Icinga\Exception\ProgrammingError;

/**
 * Class PuppetDbApi
 * @package Icinga\Module\Puppetdb
 */
class PuppetDbApi
{
    /** @var array */
    protected static $baseUrls = array(
        'v1' => '',
        'v2' => '/v2',
        'v3' => '/v3',
        'v4' => '/pdb/query/v4'
    );

    /** @var string */
    protected $version;

    /** @var string */
    protected $baseUrl;

    /** @var string */
    protected $pdbHost;

    /** @var string|int */
    protected $pdbPort;

    /** @var string */
    protected $configDir;

    /** @var string */
    protected $certname;

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
        $this->setVersion($version);
        $this->pdbHost  = $host;
        $this->pdbPort  = $port;
        $this->certname = $certname;
        if ($version === 'v4') {
            $this->orderBy = 'order_by';
        } else {
            $this->orderBy = 'order-by';
        }
    }

    /**
     * @param $version
     * @return $this
     * @throws ProgrammingError
     */
    public function setVersion($version)
    {
        $this->version = $version;
        if (! array_key_exists($version, self::$baseUrls)) {
            throw new ProgrammingError('Got unknown PuppetDB API version: %s', $version);
        }

        $this->baseUrl = self::$baseUrls[$version];
        return $this;
    }

    /**
     * @return array
     */
    public function listFactNames()
    {
        // Min version: v2
        if ($this->version === 'v1') {
            return array();
        }

        return json_decode($this->get('fact-names'));
    }

    /**
     * @return array
     */
    public function enumResourceTypes()
    {
        if ($this->version !== 'v4') {
            return array();
        }

        $order = array(
            array('field' => 'exported', 'order' => 'desc'),
            array('field' => 'type', 'order' => 'asc')
        );

        $url = 'resources?' . $this->query(array(
            'extract',
            array(array('function', 'count'), 'type', 'exported'),
            array('~', 'type', '.'),
            array('group_by', 'type', 'exported')
        )) . '&' . $this->orderBy($order);

        $enum = array();
        foreach (json_decode($this->get($url)) as $res) {
            if ($res->exported) {
                $name = '@@' . $res->type;
            } else {
                $name = $res->type;
            }
            $enum[$name] = sprintf('%s (%d)', $name, $res->count);
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

    protected function encodeParameter($key, $value)
    {
        return $key . '=' . rawurlencode(json_encode($value));
    }

    /**
     * @return array
     */
    public function classes()
    {
        $classes = array();
        $order = array(
            array('field' => 'certname', 'order' => 'asc'),
            array('field' => 'title',    'order' => 'asc')
        );

        $url = 'resources?'
             . $this->encodeParameter('query', array('=', 'type', 'Class'))
             . '&' . $this->encodeParameter($this->orderBy, $order)
             ;

        foreach ($this->fetchLimited($url) as $entry) {
            if (! array_key_exists($entry->certname, $classes)) {
                $classes[$entry->certname] = array();
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
        $result = array();
        $url .= '&limit=' . ($step + 1) . '&offset=';

        while ($remaining) {
            $remaining = false;
            foreach (json_decode($this->get($url . $offset)) as $entry) {
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
     * @param  Filter $filter
     * @param  bool $exported
     * @return array
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
                $query = array('=', 'exported', $exported);
            } else {
                $query = array(
                    'and',
                    array('=', 'exported', $exported),
                    $query
                );
            }
        }

        $url = 'resources';
        $columns = array(
            'certname',
            'type',
            'title',
            'exported',
            'parameters',
            'environment',
            // 'tags' -> on demand?
        );
        if ($query !== null) {
            if ($this->version === 'v4') {
                $query = array('extract', $columns, $query);
            }
            $url .= '?' . $this->encodeParameter('query', $query);
        }

        $order = array(
            array('field' => 'type', 'order' => 'asc'),
            array('field' => 'title', 'order' => 'asc')
        );

        if ($exported === null) {
            array_unshift(
                $order,
                array('field' => 'exported', 'order' => 'desc')
            );
        }

        $url .= '&' . $this->orderBy($order);

        return $this->fetchLimited($url);
    }

    /**
     * @param string $type
     * @param Filter $filter
     * @return array
     */
    public function fetchResourcesByType($type, Filter $filter = null)
    {
        if (substr($type, 0, 2) === '@@') {
            $exported = true;
            $type = substr($type, 2);
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
     */
    public function fetchFacts(Filter $filter = null)
    {
        $unStringify = true;

        if ($filter === null) {
            $facts = $this->get('facts');
        } else {
            $facts = $this->get('facts?' . $this->renderFilter($filter));
        }

        $result = array();
        foreach (json_decode($facts) as $row) {
            if (! array_key_exists($row->certname, $result)) {
                $result[$row->certname] = (object) array();
            }
            // What to do with row->environment on newer versions?
            if ($unStringify && is_string($row->value)) {
                $first = substr($row->value, 0, 1);
                $last  = substr($row->value, -1);
                if (($first === '{' && $last === '}')
                    || ($first === '[' && $last === ']')
                    || ($first === '"' && $last === '"')
                ) {
                    $result[$row->certname]->{$row->name} = json_decode($row->value);
                } else {
                    $result[$row->certname]->{$row->name} = $row->value;
                }
            } else {
                $result[$row->certname]->{$row->name} = $row->value;
            }
        }

        ksort($result);
        return $result;
    }

    protected function renderFilter(Filter $filter)
    {
        return FilterRenderer::forFilter($filter)->toQueryString();
    }

    protected function url($url)
    {
        return sprintf('https://%s:%d%s/%s', $this->pdbHost, $this->pdbPort, $this->baseUrl, $url);
    }

    protected function request($method, $url, $body = null, $raw = false)
    {
        $headers = array(
            'Host: ' . $this->pdbHost . ':8081',
            'Connection: close'
        );
        if ($body !== null) {
            $body = json_encode($body);
            $headers[] = 'Content-Type: application/json';
        }

        $opts = array(
            'http' => array(
                'protocol_version' => '1.1',
                'user_agent'       => 'Icinga Web 2.0 - Director',
                'method'           => strtoupper($method),
                'content'          => $body,
                'header'           => $headers,
                'ignore_errors'    => true
            ),
            'ssl' => array(
                'peer_name'        => $this->pdbHost,
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'cafile'           => $this->sslDir('certs/ca.pem'),
                'verify_depth'     => 5,
                'verify_expiry'    => true,
                // TODO: re-enable once configurable: 'CN_match'         => $this->pdbHost, // != peer?,
                'local_cert'       => $this->sslDir('private_keys/' . $this->certname . '_combined.pem'),
            )
        );
        $context = stream_context_create($opts);
        $res = file_get_contents($this->url($url), false, $context);
        if (substr(array_shift($http_response_header), 0, 10) !== 'HTTP/1.1 2') {
            throw new IcingaException(
                'Headers: %s, Response: %s',
                implode("\n", $http_response_header),
                var_export($res, 1)
            );
        }
        if ($raw) {
            return $res;
        } else {
            return $res;
            // return RestApiResponse::fromJsonResult($res);
        }
    }

    /**
     * @param  string $url
     * @param  string $body
     * @return string
     */
    public function get($url, $body = null)
    {
        return $this->request('get', $url, $body);
    }

    /**
     * @param  string $url
     * @param  string $body
     * @return string
     */
    public function getRaw($url, $body = null)
    {
        return $this->request('get', $url, $body, true);
    }

    /**
     * @param  string $url
     * @param  string $body
     * @return string
     */
    public function post($url, $body = null)
    {
        return $this->request('post', $url, $body);
    }

    /**
     * @param  string $sub
     * @return string
     */
    protected function sslDir($sub = null)
    {
        return $this->getConfigDir($sub);
    }

    /**
     * @param  string $sub
     * @return string
     */
    protected function getConfigDir($sub = null)
    {
        if ($this->configDir === null) {
            $pdb = new PuppetDb();
            $this->configDir = $pdb->sslDir($this->pdbHost);
        }

        return $this->configDir . ($sub === null ? '' :  '/' . $sub);
    }
}
