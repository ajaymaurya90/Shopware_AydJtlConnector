<?php declare(strict_types=1);

use Shopware\Core\TestBootstrapper;

$loader = (new TestBootstrapper())
    ->addCallingPlugin()
    ->addActivePlugins('AydJtlConnector')
    ->setForceInstallPlugins(true)
    ->bootstrap()
    ->getClassLoader();

$loader->addPsr4('AydJtlConnector\\Tests\\', __DIR__);
