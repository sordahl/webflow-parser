<?php

/**
 * Generate Complete Site
 *
 * This is the main script that generates the complete website including:
 * 1. Locale-specific HTML pages with translations
 * 2. sitemap.xml with auto-detected languages
 * 3. robots.txt
 * 4. Copy public files
 *
 * This replaces the need to run generate-locale-pages.php and generate-sitemap.php separately.
 */

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/.env');

use HiFriday\WebflowLocale\WebflowLocaleFetcher;
use HiFriday\WebflowLocale\WebflowApiClient;
use HiFriday\WebflowLocale\SitemapGenerator;

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
    'siteId' => '670f6126b73b3a75c012622d',
    'siteUrl' => 'https://hifriday.webflow.io',
    'hostUrl' => 'https://hifriday.app',
    'outputDir' => __DIR__ . '/dist',
    'jsonDir' => __DIR__ . '/dist/json',
    'assetsDir' => __DIR__ . '/dist/assets',
    'publicDir' => __DIR__ . '/public',

    // Options
    'fetchContent' => true,  // Fetch JSON DOM structure
    'fetchHtml' => true,     // Fetch rendered HTML
    'downloadAssets' => true, // Download external assets
    'minifyInline' => true,  // Minify inline styles and scripts
    'site_name' => 'Friday', // Site name for og:site_name meta tag

    // Append script before </body> - async loading ensures no performance impact
    'appendBeforeBody' => '<script src="/assets/analytics.js" async defer></script>',

    // Pages to exclude by ID
    'excludePageIds' => [
        '671d6147172df4affd2ed334',
        '670f6126b73b3a75c0126239',
        '67cd6669aebf7cd8319e7d26'
    ],
];

try {
    echo PHP_EOL;
    echo "====================================" . PHP_EOL;
    echo "   Webflow Site Generator" . PHP_EOL;
    echo "====================================" . PHP_EOL . PHP_EOL;

    $startTime = microtime(true);

    // Step 1: Generate locale pages
    echo "STEP 1: Generating locale-specific HTML pages..." . PHP_EOL;
    echo "------------------------------------" . PHP_EOL;
    $fetcher = new WebflowLocaleFetcher($config);
    $fetcher->run();

    echo PHP_EOL . "STEP 2: Generating sitemap and robots.txt..." . PHP_EOL;
    echo "------------------------------------" . PHP_EOL;

    // Fetch all pages for sitemap generation
    $apiClient = new WebflowApiClient($config['apiKey']);
    $sites = $apiClient->getSites();

    if (empty($sites)) {
        throw new Exception('No sites found');
    }

    $site = $sites[0]; // Use first site
    $locales = extractLocales($site);

    // Fetch all pages with locale tags
    $allPages = [];
    foreach ($locales as $locale) {
        $localeId = $locale['primary'] ? null : $locale['id'];
        $pages = $apiClient->getPages($config['siteId'], $localeId);

        // Add locale tag to each page
        foreach ($pages as &$page) {
            $page['localeTag'] = $locale['tag'];
        }

        $allPages = array_merge($allPages, $pages);
    }

    // Generate sitemap and robots.txt
    $sitemapGenerator = new SitemapGenerator($config['outputDir'], $config['hostUrl']);
    $sitemapGenerator->generateSitemap($allPages, $config['excludePageIds']);
    echo PHP_EOL;
    $sitemapGenerator->generateRobotsTxt();

    echo PHP_EOL . "STEP 3: Copying public files..." . PHP_EOL;
    echo "------------------------------------" . PHP_EOL;
    $sitemapGenerator->copyPublicFiles($config['publicDir']);

    $endTime = microtime(true);
    $duration = round($endTime - $startTime, 2);

    echo PHP_EOL . "====================================" . PHP_EOL;
    echo "   ✓ Site generation completed!" . PHP_EOL;
    echo "   Duration: {$duration}s" . PHP_EOL;
    echo "====================================" . PHP_EOL;
} catch (Exception $e) {
    echo PHP_EOL . "❌ Error: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

/**
 * Extract locale information from site data
 */
function extractLocales(array $site): array
{
    $locales = [];

    if (!isset($site['locales'])) {
        return $locales;
    }

    // Handle primary locale
    if (isset($site['locales']['primary'])) {
        $locale = $site['locales']['primary'];
        $locales[] = [
            'id' => $locale['id'] ?? null,
            'tag' => $locale['tag'] ?? 'en',
            'displayName' => $locale['displayName'] ?? 'English',
            'primary' => true
        ];
    }

    // Handle secondary locales
    if (isset($site['locales']['secondary']) && is_array($site['locales']['secondary'])) {
        foreach ($site['locales']['secondary'] as $locale) {
            $locales[] = [
                'id' => $locale['id'] ?? null,
                'tag' => $locale['tag'] ?? 'unknown',
                'displayName' => $locale['displayName'] ?? 'Unknown',
                'primary' => false
            ];
        }
    }

    return $locales;
}
