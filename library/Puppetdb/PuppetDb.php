<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Application\Config;
use DirectoryIterator;

class PuppetDb
{
    protected $ssldir;

    protected $servers;

    public function listServers()
    {
        $servers = array();

        foreach (new DirectoryIterator($this->ssldir()) as $file) {
            if ($file->isDot()) continue;
            if ($file->isDir()) {
                $servers[$file->getFilename()] = $file->getFilename();
            }
        }
        ksort($servers);

        return $servers; 
    }

    public function listClientCerts($serverName)
    {
        $certs = array();

        foreach ($this->listServers() as $server) {
            if ($server !== $serverName) continue;

            foreach (new DirectoryIterator($this->ssldir($server) . '/private_keys') as $file) {
                if ($file->isDot()) continue;
                $filename = $file->getFilename();
                if (substr($filename, -13) === '_combined.pem') {
                    $certname = substr($filename, 0, -13);
                    $certs[$certname] = $certname;
                }
            }
        }

        ksort($certs);

        return $certs;
    }

    public function ssldir($subdir = null)
    {
        if ($this->ssldir === null) {
            $this->ssldir = dirname(Config::module('puppetdb')->getConfigFile()) . '/ssl';
        }
        if ($subdir === null) {
            return $this->ssldir;
        } else {
            return $this->ssldir . '/' . $subdir;
        }
    }
}
