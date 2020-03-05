<?php

namespace Icinga\Module\Puppetdb\Clicommands;

use Icinga\Data\Filter\Filter;
use Icinga\Cli\Command;
use Icinga\Module\Puppetdb\PuppetDbApi;

class FilterCommand extends Command
{
    public function testAction()
    {
        $pdb = new PuppetDbApi('v4', 'pe2015.example.com');
        // $filter = Filter::fromQueryString('type=Nagios_host&exported=true');
        // echo FilterRenderer::forFilter($filter)->toJson();
        $facts = $pdb->fetchFacts(Filter::fromQueryString('name=kernel|name=osfamily|name=partitions'));
        // certname=pe2015.example.com
    }
}
