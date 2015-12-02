<?php

namespace Icinga\Module\Puppetdb\ProvidedHook\Director;

use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Web\Hook\ImportSourceHook;
use Icinga\Module\Puppetdb\PuppetDbApi;
use Icinga\Module\Puppetdb\PuppetDb;
use Icinga\Application\Benchmark;

class ImportSource extends ImportSourceHook
{
    protected $db;

    public function fetchData()
    {
        if ($this->getSetting('query_type') === 'resource') {
            return $this->fetchResourceData();
        }

        $result = array();
        $db    = $this->db();
        Benchmark::measure('Pdb, going to fetch classes');
        $data  = $db->classes();
        Benchmark::measure('Pdb, got classes, going to fetch facts');
        $facts = $db->fetchFacts();
        Benchmark::measure('Pdb, got facts, preparing result');

        foreach ($facts as $host => $f) {

            $f = $facts[$host];
            if (array_key_exists($host, $data)) {
                $classes = $data[$host];
            } else {
                $classes = array();
            }
            foreach (array_keys((array) $f) as $key) {
                if (preg_match('/(?:memoryfree|swapfree|uptime)/', $key)) {
                    unset($f->$key);
                }
            }
            $result[] = (object) array(
                'certname' => $host,
                'classes'  => $classes,
                'facts'    => $f,
            );

        }

        Benchmark::measure('Pdb result ready');

        return $result;
    }

    public function listColumns()
    {
        if ($this->getSetting('query_type') === 'resource') {
            return array(
                'certname',
                'type',
                'title',
                'exported',
                'parameters',
                'environment',
            );
        }

        $columns = array(
            'certname',
            'classes',
            'facts'
        );

        foreach ($this->db()->listFactNames() as $name) {
            $columns[] = 'facts.' . $name;
        }

        return $columns;
    }

    protected function fetchResourceData()
    {
        return $this->db()->fetchResourcesByType($this->getSetting('resource_type'));
    }

    public static function getDefaultKeyColumnName()
    {
        return 'certname';
    }

    public static function addSettingsFormFields(QuickForm $form)
    {
        $pdb = new PuppetDb();
        $form->addElement('select', 'api_version', array(
            'label'        => 'API version',
            'required'     => true,
            'multiOptions' => array(
                'v4' => 'v4',
                'v3' => 'v3',
                'v2' => 'v2',
                'v1' => 'v1',
            ),
        ));

        $form->addElement('select', 'server', array(
            'label'        => 'PuppetDB Server',
            'required'     => true,
            'multiOptions' => $form->optionalEnum($pdb->listServers()),
            'class'        => 'autosubmit',
        ));

        if (! ($server = $form->getSentOrObjectValue('server'))) {
            return $form;
        }

        $form->addElement('select', 'client_cert', array(
            'label'        => 'Client Certificate',
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum($pdb->listClientCerts($server)),
        ));

        if (! ($cert = $form->getSentOrObjectValue('client_cert'))) {
            return;
        }

        $form->addElement('select', 'query_type', array(
            'label'        => 'Query type',
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum(array(
                'resource' => $form->translate('Resources'),
                'node'     => $form->translate('Nodes'),
            )),
        ));

        if (! ($queryType = $form->getSentOrObjectValue('query_type'))) {
            return;
        }

        $db = new PuppetDbApi(
            $form->getSentOrObjectValue('api_version'),
            $cert,
            $server
        );

        $resourceTypes = $db->enumResourceTypes();
        if (empty($resourceTypes)) {
            $form->addElement('text', 'resource_type', array(
                'label'        => 'Resource type',
                'required'     => true,
            ));
        } else {
            $form->addElement('select', 'resource_type', array(
                'label'        => 'Resource type',
                'required'     => true,
                'class'        => 'autosubmit',
                'multiOptions' => $form->optionalEnum($resourceTypes)
            ));
        }
    }

    protected function db()
    {
        if ($this->db === null) {
            $this->db = new PuppetDbApi(
                $this->getSetting('api_version'),
                $this->getSetting('client_cert'),
                $this->getSetting('server')
            );
        }

        return $this->db;
    }
}
