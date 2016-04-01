<?php

$this->provideHook('director/ImportSource');

// Workaround for doc images:
if (Icinga::app()->isCli()) {
    return;
}

$screenshotRoute = new Zend_Controller_Router_Route(
    'screenshot/puppetdb/:subdir/:file',
    array(
        'module'        => 'puppetdb',
        'controller'    => 'screenshot',
    )
);

$this->addRoute('screenshot/puppetdb', $screenshotRoute);

