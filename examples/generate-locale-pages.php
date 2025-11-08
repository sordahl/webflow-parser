<?php

/**
 * Generate Locale Pages
 *
 * This script fetches Webflow pages and generates locale-specific HTML files
 * with translations by comparing JSON DOM structures from the Webflow API.
 *
 * Uses concepts from WebflowParser for HTML processing and asset management.
 */

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/.env');

use HiFriday\WebflowLocale\WebflowLocaleFetcher;

// Load environment variables
function loadEnv(string $path): void
{
    if (!file_exists($path)) {
        throw new Exception('.env file not found');
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }

        if (strpos($line, '=') === false) {
            continue;
        }

        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, '"\'');

        if (!array_key_exists($name, $_ENV)) {
            putenv(sprintf('%s=%s', $name, $value));
            $_ENV[$name] = $value;
        }
    }
}

// Load environment
loadEnv(__DIR__ . '/.env');

// Get API key from environment
$apiKey = getenv('WEBFLOW_API');
if (!$apiKey) {
    die('WEBFLOW_API key not found in .env file' . PHP_EOL);
}

// Configuration
$config = [
    'apiKey' => $apiKey,
    'siteUrl' => 'https://hifriday.webflow.io',
    'hostUrl' => 'https://hifriday.app', // Your custom domain
    'outputDir' => __DIR__ . '/dist',
    'jsonDir' => __DIR__ . '/dist/json',
    'assetsDir' => __DIR__ . '/dist/assets',

    // Options
    'fetchContent' => true,  // Fetch JSON DOM structure
    'fetchHtml' => true,     // Fetch rendered HTML
    'downloadAssets' => true, // Download external assets
    'minifyInline' => true,  // Minify inline styles and scripts
    'site_name' => 'Friday™', // Site name for og:site_name meta tag

    // Pages to exclude by ID
    'excludePageIds' => [
        '671d6147172df4affd2ed334',
        '670f6126b73b3a75c0126239',
        '67cd6669aebf7cd8319e7d26'
    ],
];

try {
    // Create and run the fetcher
    $fetcher = new WebflowLocaleFetcher($config);
    $fetcher->run();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}
