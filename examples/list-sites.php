<?php

/**
 * List all sites accessible with your Webflow API key
 * This helps you find the correct site ID
 */

require_once(__DIR__ . '/vendor/autoload.php');

use Sordahl\WebflowParser\WebflowApiClient;
use Sordahl\WebflowParser\EnvLoader;

EnvLoader::load(__DIR__ . '/.env');

$apiKey = EnvLoader::getRequired('WEBFLOW_API');

try {
    echo "Fetching sites from Webflow API..." . PHP_EOL . PHP_EOL;

    $apiClient = new WebflowApiClient($apiKey);
    $sites = $apiClient->getSites();

    if (empty($sites)) {
        echo "No sites found. Please check your API key permissions." . PHP_EOL;
        exit(1);
    }

    echo "Found " . count($sites) . " site(s):" . PHP_EOL . PHP_EOL;

    foreach ($sites as $site) {
        echo "Site ID: " . ($site['id'] ?? 'N/A') . PHP_EOL;
        echo "  Name: " . ($site['displayName'] ?? $site['name'] ?? 'N/A') . PHP_EOL;
        echo "  Short Name: " . ($site['shortName'] ?? 'N/A') . PHP_EOL;

        if (isset($site['customDomains']) && !empty($site['customDomains'])) {
            echo "  Custom Domains: " . implode(', ', $site['customDomains']) . PHP_EOL;
        }

        if (isset($site['previewUrl'])) {
            echo "  Preview URL: " . $site['previewUrl'] . PHP_EOL;
        }

        echo PHP_EOL;
    }

    echo "Use one of these Site IDs in your site configuration." . PHP_EOL;
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . PHP_EOL;
    exit(1);
}
