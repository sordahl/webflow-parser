<?php
require_once(__DIR__ . '/vendor/autoload.php');

use Sordahl\WebflowParser\SiteGenerator;
use Sordahl\WebflowParser\EnvLoader;

EnvLoader::load(__DIR__ . '/.env');

$config = [
	'apiKey' => EnvLoader::getRequired('WEBFLOW_API'),
	'siteId' => '644b04d843a3f621d5f74e0d',
	'siteUrl' => 'trackgrowth.webflow.io',
	'hostUrl' => 'https://trackgrowth.app',
	'outputDir' => __DIR__ . '/dist',
	'jsonDir' => __DIR__ . '/dist/json',
	'assetsDir' => __DIR__ . '/dist/assets',
	'publicDir' => __DIR__ . '/public',

	'site_name' => 'Track Growth',
	'appendBeforeBody' => '<script src="/assets/analytics.js" async defer></script>',
	'excludePageIds' => [],
];

$generator = (new SiteGenerator($config))->generate();
