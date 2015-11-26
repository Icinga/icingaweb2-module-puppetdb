<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Application\Config;
use Icinga\Data\Filter\Filter;
use Icinga\Exception\IcingaException;
use Icinga\Exception\ProgrammingError;
use Icinga\Module\Puppetdb\FilterRenderer;

class PuppetDbApi
{
    protected static $baseUrls = array(
        'v1' => '',
        'v2' => '/v2',
        'v3' => '/v3',
        'v4' => '/pdb/query/v4'
    );

    protected $version;

    protected $baseUrl;

    protected $pdbHost;

    protected $pdbPort;

    protected $configDir;

    protected $certname;

    public function __construct($version, $certname, $host, $port = 8081)
    {
        $this->setVersion($version);
        $this->pdbHost  = $host;
        $this->pdbPort  = $port;
        $this->certname = $certname;
    }

    public function setVersion($version)
    {
        $this->version = $version;
        if (! array_key_exists($version, self::$baseUrls)) {
            throw new ProgrammingError('Got unknown PuppetDB API version: %s', $version);
        }

        $this->baseUrl = self::$baseUrls[$version];
        return $this;
    }

    public function listFactNames()
    {
        // Min version: v2
        if ($this->version === 'v1') {
            return array();
        }

        return json_decode($this->get('fact-names'));
    }

    protected function encodeParameter($key, $value)
    {
        return $key . '=' . rawurlencode(json_encode($value));
    } 

    public function classes()
    {
        $classes = array();
        $order = array(
            array('field' => 'certname', 'order' => 'asc'),
            array('field' => 'title',    'order' => 'asc')
        );

        $remaining = true;
        $step      = 3000;
        $offset    = 0;
        $cnt       = 0;

        $url = 'resources?'
             . $this->encodeParameter('query', array('=', 'type', 'Class'))
             . '&' . $this->encodeParameter('order-by', $order)
             . '&limit=' . ($step + 1) . '&offset='
             ;

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
                if (! array_key_exists($entry->certname, $classes)) {
                    $classes[$entry->certname] = array();
                }

                $classes[$entry->certname][] = $entry->title;
            }
        }

        return $classes;
    }

    public function fetchFacts(Filter $filter = null)
    {
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
            $result[$row->certname]->{$row->name} = $row->value;
        }

        ksort($result);
        return $result;
    }

    public function fetchHosts()
    {
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
                'cafile'           => $this->ssldir('certs/ca.pem'),
                'verify_depth'     => 5,
                'verify_expiry'    => true,
                'CN_match'         => $this->pdbHost, // != peer?,
                'local_cert'       => $this->ssldir('private_keys/' . $this->certname . '_combined.pem'),
            )
        );
        $context = stream_context_create($opts);

        $res = file_get_contents($this->url($url), false, $context);
        if (substr(array_shift($http_response_header), 0, 10) !== 'HTTP/1.1 2') {
            throw new IcingaException(implode("\n", $http_response_header) . var_export($res));
        }
        if ($raw) {
            return $res;
        } else {
            return $res;
            // return RestApiResponse::fromJsonResult($res);
        }
    }

    public function get($url, $body = null)
    {
        return $this->request('get', $url, $body);
    }

    public function getRaw($url, $body = null)
    {
        return $this->request('get', $url, $body, true);
    }

    public function post($url, $body = null)
    {
        return $this->request('post', $url, $body);
    }

    protected function ssldir($sub = null)
    {
        return $this->getConfigDir($sub);
    }

    protected function getConfigDir($sub = null)
    {
        if ($this->configDir === null) {
            $pdb = new PuppetDb();
            $this->configDir = $pdb->ssldir($this->pdbHost);
        }

        return $this->configDir . ($sub === null ? '' :  '/' . $sub);
    }
}
