<?php

namespace Icinga\Module\Puppetdb\ProvidedHook\Director;

use Icinga\Module\Director\Hook\ImportSourceHook;
use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Puppetdb\PuppetDbApi;
use Icinga\Module\Puppetdb\PuppetDb;
use Icinga\Application\Benchmark;
use Exception;

/**
 * Class ImportSource
 * @package Icinga\Module\Puppetdb\ProvidedHook\Director
 */
class ImportSource extends ImportSourceHook
{
    /** @var PuppetDbApi */
    protected $db;

    /**
     * @inheritdoc
     * @throws \Icinga\Exception\QueryException
     */
    public function fetchData()
    {
        if ($this->getSetting('query_type') === 'resource') {
            return $this->fetchResourceData();
        }

        $result = [];
        $db    = $this->db();
        Benchmark::measure('Pdb, going to fetch classes');
        $data  = $db->classes();
        Benchmark::measure('Pdb, got classes, going to fetch facts');
        $facts = $db->fetchFacts();
        Benchmark::measure('Pdb, got facts, preparing result');

        foreach ($facts as $host => $f) {

            $f = $facts[$host];
            if (\array_key_exists($host, $data)) {
                $classes = $data[$host];
            } else {
                $classes = [];
            }
            foreach (\array_keys((array) $f) as $key) {
                if (\preg_match('/(?:memoryfree|swapfree|uptime)/', $key)) {
                    unset($f->$key);
                }
            }
            $result[] = (object) [
                'certname' => $host,
                'classes'  => $classes,
                'facts'    => $f,
            ];

        }
        Benchmark::measure('Pdb result ready');

        return $result;
    }

    /**
     * @inheritdoc
     */
    public function listColumns()
    {
        if ($this->getSetting('query_type') === 'resource') {
            return [
                'certname',
                'type',
                'title',
                'exported',
                'parameters',
                'environment',
            ];
        }

        $columns = [
            'certname',
            'classes',
            'facts'
        ];

        foreach ($this->db()->listFactNames() as $name) {
            $columns[] = 'facts.' . $name;
        }

        return $columns;
    }

    /**
     * @return \stdClass[]
     * @throws \Icinga\Exception\QueryException
     */
    protected function fetchResourceData()
    {
        return $this->db()->fetchResourcesByType($this->getSetting('resource_type'));
    }

    /**
     * @inheritdoc
     */
    public static function getDefaultKeyColumnName()
    {
        return 'certname';
    }

    /**
     * @inheritdoc
     * @throws \Zend_Form_Exception
     */
    public static function addSettingsFormFields(QuickForm $form)
    {
        /** @var $form \Icinga\Module\Director\Forms\ImportSourceForm */
        $pdb = new PuppetDb();
        $form->addElement('select', 'api_version', [
            'label'        => 'API version',
            'required'     => true,
            'multiOptions' => [
                'v4' => 'v4: PuppetDB 2.3 (experimental), 3.0, 3.1, 3.2, 4.0 (PE 3.8 (experimental), 2015.2, 2015.3)',
                'v3' => 'v3: PuppetDB 1.5, 1.6, 2.3 (PE 3.1, 3.2, 3.3, 3.8)',
                'v2' => 'v2: PuppetDB 1.1, 1.2, 1.3, 1.4',
                'v1' => 'v1: PuppetDB 1.0',
            ],
        ]);

        $form->addElement('select', 'server', [
            'label'        => 'PuppetDB Server',
            'required'     => true,
            'multiOptions' => $form->optionalEnum($pdb->listServers()),
            'class'        => 'autosubmit',
        ]);

        if (! ($server = $form->getSentOrObjectSetting('server'))) {
            return;
        }

        $form->addElement('select', 'client_cert', [
            'label'        => 'Client Certificate',
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum($pdb->listClientCerts($server)),
        ]);

        if (! ($cert = $form->getSentOrObjectSetting('client_cert'))) {
            return;
        }

        $form->addElement('select', 'query_type', [
            'label'        => 'Query type',
            'required'     => true,
            'class'        => 'autosubmit',
            'multiOptions' => $form->optionalEnum([
                'resource' => $form->translate('Resources'),
                'node'     => $form->translate('Nodes'),
            ]),
        ]);

        if (! ($queryType = $form->getSentOrObjectSetting('query_type'))) {
            return;
        }

        try {
            $db = new PuppetDbApi(
                $form->getSentOrObjectSetting('api_version'),
                $cert,
                $server
            );

            $resourceTypes = $db->enumResourceTypes();
        } catch (Exception $e) {
            $form->addError(
                \sprintf(
                    $form->translate('Failed to load resource types: %s'),
                    $e->getMessage()
                )
            );
        }

        if (empty($resourceTypes)) {
            $form->addElement('text', 'resource_type', [
                'label'        => 'Resource type',
                'required'     => true,
            ]);
        } else {
            $form->addElement('select', 'resource_type', [
                'label'        => 'Resource type',
                'required'     => true,
                'class'        => 'autosubmit',
                'multiOptions' => $form->optionalEnum($resourceTypes)
            ]);
        }

        return;
    }

    /**
     * @return PuppetDbApi
     */
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
