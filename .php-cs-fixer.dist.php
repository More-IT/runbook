<?php

declare(strict_types=1);

require_once __DIR__ . '/vendor/autoload.php';

$config = new \Nextcloud\CodingStandard\Config();
$config
    ->getFinder()
    ->ignoreVCSIgnored(true)
    ->notPath('build')
    ->notPath('vendor')
    ->notPath('node_modules')
    ->in(__DIR__);

return $config;
