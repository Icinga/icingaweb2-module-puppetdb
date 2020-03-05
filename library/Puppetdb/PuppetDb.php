<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Application\Config;
use DirectoryIterator;

/**
 * Class PuppetDb
 * @package Icinga\Module\Puppetdb
 */
class PuppetDb
{
    /** @var string */
    protected $sslDir;

    /**
     * Return a list of available servers
     *
     * @return array
     */
    public function listServers()
    {
        $servers = [];

        foreach (new DirectoryIterator($this->sslDir()) as $file) {
            if ($file->isDot()) {
                continue;
            }
            if ($file->isDir()) {
                $servers[$file->getFilename()] = $file->getFilename();
            }
        }
        \ksort($servers);

        return $servers;
    }

    /**
     * Return a list of available client certificates for a given server
     * @param $serverName
     * @return array
     */
    public function listClientCerts($serverName)
    {
        $certs = [];

        foreach ($this->listServers() as $server) {
            if ($server !== $serverName) {
                continue;
            }

            foreach (new DirectoryIterator($this->sslDir($server) . '/private_keys') as $file) {
                if ($file->isDot()) {
                    continue;
                }
                $filename = $file->getFilename();
                if (\substr($filename, -13) === '_combined.pem') {
                    $certName = \substr($filename, 0, -13);
                    $certs[$certName] = $certName;
                }
            }
        }
        \ksort($certs);

        return $certs;
    }

    public function sslDir($subDir = null)
    {
        if ($this->sslDir === null) {
            $this->sslDir = \dirname(Config::module('puppetdb')->getConfigFile()) . '/ssl';
        }
        if ($subDir === null) {
            return $this->sslDir;
        } else {
            return $this->sslDir . '/' . $subDir;
        }
    }
}
