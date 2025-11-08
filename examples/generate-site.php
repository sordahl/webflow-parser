<?php
require_once(__DIR__ . '/vendor/autoload.php');

use Sordahl\WebflowParser\SiteGenerator;
use Sordahl\WebflowParser\EnvLoader;

EnvLoader::load(__DIR__ . '/.env');

$config = [
    'apiKey' => EnvLoader::getRequired('WEBFLOW_API'),
    'siteId' => '<site-id>',
    'siteUrl' => 'your-site.webflow.io',
    'hostUrl' => 'https://your.site',
    'outputDir' => __DIR__ . '/dist',
    'jsonDir' => __DIR__ . '/dist/json',
    'assetsDir' => __DIR__ . '/dist/assets',
    'publicDir' => __DIR__ . '/public',

    'site_name' => 'Your site name',
    'appendBeforeBody' => '<script src="/assets/analytics.js" async defer></script>',
    'excludePageIds' => [],
];

$generator = (new SiteGenerator($config))->generate();
