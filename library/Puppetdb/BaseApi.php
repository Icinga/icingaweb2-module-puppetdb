<?php

namespace Icinga\Module\Puppetdb;

use InvalidArgumentException;
use RuntimeException;

class BaseApi
{
    /** @var array */
    protected $baseUrls = [];

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

    /**
     * BaseApi constructor.
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
    }

    /**
     * @param $version
     * @return $this
     */
    public function setVersion($version)
    {
        $this->version = $version;
        if (! \array_key_exists($version, $this->baseUrls)) {
            throw new InvalidArgumentException(\sprintf('Got unknown PuppetDB API version: %s', $version));
        }

        $this->baseUrl = $this->baseUrls[$version];

        return $this;
    }

    protected function query($query = null)
    {
        if ($query === null) {
            return '';
        } else {
            return $this->encodeParameter('query', $query);
        }
    }

    protected function encodeParameter($key, $value)
    {
        return $key . '=' . \rawurlencode(json_encode($value));
    }

    protected function url($url)
    {
        if (\substr($url, 0, 1) === '?') {
            $slash = '';
        } else {
            $slash = '/';
        }

        return \sprintf(
            'https://%s:%d%s%s%s',
            $this->pdbHost,
            $this->pdbPort,
            $this->baseUrl,
            $slash,
            $url
        );
    }

    protected function prepareStreamContext($method, $body = null)
    {
        $headers = [
            'Host: ' . $this->pdbHost . ':8081',
            'Connection: close'
        ];
        if ($body !== null) {
            $body = \json_encode($body);
            $headers[] = 'Accept: application/json';
            $headers[] = 'Content-Type: application/json';
        }

        return [
            'http' => [
                'protocol_version' => '1.1',
                'user_agent'       => 'Icinga Web 2.0 - Director',
                'method'           => \strtoupper($method),
                'content'          => $body,
                'header'           => $headers,
                'ignore_errors'    => true
            ],
            'ssl' => [
                'peer_name'        => $this->pdbHost,
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'cafile'           => $this->sslDir('certs/ca.pem'),
                'verify_depth'     => 5,
                'verify_expiry'    => true,
                // TODO: re-enable once configurable: 'CN_match'         => $this->pdbHost, // != peer?,
                'local_cert'       => $this->sslDir('private_keys/' . $this->certname . '_combined.pem'),
            ]
        ];
    }

    protected function request($method, $url, $body = null, $raw = false)
    {
        $opts = $this->prepareStreamContext($method, $body);
        $context = \stream_context_create($opts);
        $res = \file_get_contents($this->url($url), false, $context);
        if (\substr(\array_shift($http_response_header), 0, 10) !== 'HTTP/1.1 2') {
            throw new RuntimeException(\sprintf(
                'Headers: %s, Response: %s',
                \implode("\n", $http_response_header),
                \var_export($res, 1)
            ));
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
