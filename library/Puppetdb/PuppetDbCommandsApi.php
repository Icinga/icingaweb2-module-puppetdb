<?php

namespace Icinga\Module\Puppetdb;

use Icinga\Data\Filter\Filter;

class PuppetDbCommandsApi extends BaseApi
{
    protected $baseUrls = [
        'v4' => '/pdb/cmd/v1'
    ];

    public function deactivate($nodeName)
    {
        $urlNodeName = \rawurlencode($nodeName);
        return $this->post("certname=$urlNodeName&command=deactivate_node&version=3", [
            'certname' => $nodeName,
            'producer_timestamp' => \date('Y-m-d'),
        ]);
    }
}
