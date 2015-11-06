<?php

namespace Icinga\Module\Puppetdb\Director;

use Icinga\Module\Director\Web\Form\QuickForm;
use Icinga\Module\Director\Web\Hook\ImportSourceHook;
use Icinga\Module\Puppetdb\PuppetDbApi;
use Icinga\Module\Puppetdb\PuppetDb;
use Icinga\Application\Benchmark;

class ImportSourcePuppetDb extends ImportSourceHook
{
    protected $db;

    public function fetchData()
    {
        $db    = $this->db();
        Benchmark::measure('Pdb, going to fetch classes');
        $data  = $db->classes();
        Benchmark::measure('Pdb, got classes, going to fetch facts');
        $facts = $db->fetchFacts();
        Benchmark::measure('Pdb, got facts, preparing result');

        foreach ($data as $host => $classes) {

            $f = $facts[$host];
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

        if ($server = $form->getSentValue('server', $form->getValue('server'))) {

            $form->addElement('select', 'client_cert', array(
                'label'        => 'Client Certificate',
                'required'     => true,
                'multiOptions' => $form->optionalEnum($pdb->listClientCerts($server)),
            ));

        }
        return $form;
    }

    protected function db()
    {
        if ($this->db === null) {
            $this->db = new PuppetDbApi(
                $this->settings['api_version'],
                $this->settings['client_cert'],
                $this->settings['server']
            );
        }

        return $this->db;
    }
}
