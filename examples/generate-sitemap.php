<?php

/**
 * Generate robots.txt and sitemap.xml
 *
 * This script generates SEO files for the website including:
 * - robots.txt: Tells search engines which pages to crawl
 * - sitemap.xml: Lists all pages and their language versions
 */

require_once(__DIR__ . '/vendor/autoload.php');
require_once(__DIR__ . '/.env');

use Sordahl\WebflowParser\WebflowApiClient;

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
    'hostUrl' => 'https://hifriday.app',
    'outputDir' => __DIR__ . '/dist',

    // Pages to exclude by ID (should match generate-locale-pages.php)
    'excludePageIds' => [
        '671d6147172df4affd2ed334',
        '670f6126b73b3a75c0126239',
        '67cd6669aebf7cd8319e7d26'
    ],

    // Supported locales
    'locales' => [
        [
            'id' => '67f6606ec021083df0d9dd7b',
            'tag' => 'en',
            'primary' => true
        ],
        [
            'id' => '6884aa16f9d276ff52ab35fd',
            'tag' => 'da',
            'primary' => false
        ]
    ]
];

try {
    echo "Starting sitemap and robots.txt generation..." . PHP_EOL . PHP_EOL;

    // Initialize API client
    $apiClient = new WebflowApiClient($config['apiKey']);

    // Create output directory if it doesn't exist
    if (!is_dir($config['outputDir'])) {
        mkdir($config['outputDir'], 0755, true);
    }

    // Fetch all pages
    echo "Fetching pages from Webflow API..." . PHP_EOL;
    $allPages = [];

    foreach ($config['locales'] as $locale) {
        $localeId = $locale['primary'] ? null : $locale['id'];
        $localeInfo = $localeId ? " (locale: {$locale['tag']})" : "";

        echo "  Fetching pages for {$locale['tag']}$localeInfo..." . PHP_EOL;
        $pages = $apiClient->getPages($config['siteId'], $localeId);

        // Add locale tag to each page
        foreach ($pages as &$page) {
            $page['localeTag'] = $locale['tag'];
        }

        $allPages = array_merge($allPages, $pages);
    }

    // Filter out excluded pages
    $allPages = array_filter($allPages, function ($page) use ($config) {
        return !in_array($page['id'], $config['excludePageIds']);
    });

    echo "Found " . count($allPages) . " pages (including locale versions)" . PHP_EOL . PHP_EOL;

    // Group pages by slug to identify translations
    $pagesBySlug = [];
    foreach ($allPages as $page) {
        $slug = $page['slug'] ?? $page['id'];
        if (!isset($pagesBySlug[$slug])) {
            $pagesBySlug[$slug] = [];
        }
        $pagesBySlug[$slug][] = $page;
    }

    // Generate robots.txt
    echo "Generating robots.txt..." . PHP_EOL;
    $robotsTxt = generateRobotsTxt($config['hostUrl']);
    file_put_contents($config['outputDir'] . '/robots.txt', $robotsTxt);
    echo "  Saved to: {$config['outputDir']}/robots.txt" . PHP_EOL . PHP_EOL;

    // Generate sitemap.xml
    echo "Generating sitemap.xml..." . PHP_EOL;
    $sitemapXml = generateSitemapXml($pagesBySlug, $config['hostUrl']);
    file_put_contents($config['outputDir'] . '/sitemap.xml', $sitemapXml);
    echo "  Saved to: {$config['outputDir']}/sitemap.xml" . PHP_EOL . PHP_EOL;

    // Copy files from public to dist
    echo "Copying files from public to dist..." . PHP_EOL;
    $publicDir = __DIR__ . '/public';
    if (is_dir($publicDir)) {
        copyDirectory($publicDir, $config['outputDir']);
        echo "  Files copied successfully" . PHP_EOL . PHP_EOL;
    } else {
        echo "  No public directory found, skipping copy" . PHP_EOL . PHP_EOL;
    }

    echo "Generation completed successfully!" . PHP_EOL;

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . PHP_EOL;
    echo $e->getTraceAsString() . PHP_EOL;
    exit(1);
}

/**
 * Generate robots.txt content
 */
function generateRobotsTxt(string $hostUrl): string
{
    $content = "# hifriday.com robots.txt\n";
    $content .= "# Generated on " . date('Y-m-d H:i:s') . "\n\n";
    $content .= "User-agent: *\n";
    $content .= "Allow: /\n\n";
    $content .= "# Sitemap\n";
    $content .= "Sitemap: {$hostUrl}/sitemap.xml\n";

    return $content;
}

/**
 * Generate sitemap.xml content
 */
function generateSitemapXml(array $pagesBySlug, string $hostUrl): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
    $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

    // Process each page group (pages with same slug but different locales)
    foreach ($pagesBySlug as $slug => $pages) {
        // Find primary (English) page
        $primaryPage = null;
        $localizedPages = [];

        foreach ($pages as $page) {
            if ($page['localeTag'] === 'en') {
                $primaryPage = $page;
            } else {
                $localizedPages[] = $page;
            }
        }

        // Skip if no primary page found
        if (!$primaryPage) {
            continue;
        }

        // Get the published path
        $path = $primaryPage['publishedPath'] ?? '/';
        $url = rtrim($hostUrl, '/') . $path;

        // Get last updated date
        $lastmod = isset($primaryPage['lastUpdated'])
            ? date('Y-m-d', strtotime($primaryPage['lastUpdated']))
            : date('Y-m-d');

        // Determine priority and change frequency
        $priority = ($path === '/') ? '1.0' : '0.8';
        $changefreq = 'weekly';

        // Add primary page entry
        $xml .= "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($url, ENT_XML1, 'UTF-8') . "</loc>\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";

        // Add alternate language links for primary page (only other languages, not self)
        foreach ($localizedPages as $localePage) {
            $localePath = $localePage['publishedPath'] ?? '/' . $localePage['localeTag'];
            $localeUrl = rtrim($hostUrl, '/') . $localePath;
            $hreflang = $localePage['localeTag'];

            $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$hreflang}\" href=\"" . htmlspecialchars($localeUrl, ENT_XML1, 'UTF-8') . "\"/>\n";
        }

        $xml .= "  </url>\n";

        // Add entries for localized versions
        foreach ($localizedPages as $localePage) {
            $localePath = $localePage['publishedPath'] ?? '/' . $localePage['localeTag'];
            $localeUrl = rtrim($hostUrl, '/') . $localePath;
            $hreflang = $localePage['localeTag'];

            // Get last updated date for locale page
            $localeLastmod = isset($localePage['lastUpdated'])
                ? date('Y-m-d', strtotime($localePage['lastUpdated']))
                : $lastmod;

            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($localeUrl, ENT_XML1, 'UTF-8') . "</loc>\n";
            $xml .= "    <lastmod>{$localeLastmod}</lastmod>\n";
            $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
            $xml .= "    <priority>{$priority}</priority>\n";

            // Add alternate language links (link back to primary)
            $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"" . htmlspecialchars($url, ENT_XML1, 'UTF-8') . "\"/>\n";

            // Add links to other locale versions (but not self)
            foreach ($localizedPages as $altLocalePage) {
                // Skip self-reference
                if ($altLocalePage['localeTag'] === $localePage['localeTag']) {
                    continue;
                }

                $altLocalePath = $altLocalePage['publishedPath'] ?? '/' . $altLocalePage['localeTag'];
                $altLocaleUrl = rtrim($hostUrl, '/') . $altLocalePath;
                $altHreflang = $altLocalePage['localeTag'];

                $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$altHreflang}\" href=\"" . htmlspecialchars($altLocaleUrl, ENT_XML1, 'UTF-8') . "\"/>\n";
            }

            $xml .= "  </url>\n";
        }
    }

    $xml .= "</urlset>\n";

    return $xml;
}

/**
 * Recursively copy directory contents
 */
function copyDirectory(string $source, string $destination): void
{
    if (!is_dir($source)) {
        return;
    }

    // Create destination directory if it doesn't exist
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }

    $dir = opendir($source);
    while (($file = readdir($dir)) !== false) {
        // Skip . and ..
        if ($file === '.' || $file === '..') {
            continue;
        }

        $sourcePath = $source . '/' . $file;
        $destPath = $destination . '/' . $file;

        if (is_dir($sourcePath)) {
            // Recursively copy subdirectories
            copyDirectory($sourcePath, $destPath);
        } else {
            // Copy file
            copy($sourcePath, $destPath);
            echo "    Copied: $file" . PHP_EOL;
        }
    }
    closedir($dir);
}
