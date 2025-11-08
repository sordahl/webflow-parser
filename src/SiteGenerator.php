<?php

namespace Sordahl\WebflowParser;

use Exception;

/**
 * Main orchestrator for complete Webflow site generation
 * Handles locale pages, sitemap, robots.txt, and public files
 */
class SiteGenerator
{
    private array $config;
    private WebflowApiClient $apiClient;
    private WebflowLocaleFetcher $localeFetcher;
    private SitemapGenerator $sitemapGenerator;
    private float $startTime;

    /**
     * Initialize the site generator with configuration
     *
     * @param array $config Configuration array with the following keys:
     *   - apiKey: Webflow API key (required)
     *   - siteId: Webflow site ID (required)
     *   - siteUrl: Webflow site URL (required)
     *   - hostUrl: Production host URL (optional, defaults to siteUrl)
     *   - outputDir: Output directory path (required)
     *   - jsonDir: JSON output directory (optional)
     *   - assetsDir: Assets directory (optional)
     *   - publicDir: Public files directory (optional)
     *   - fetchContent: Fetch JSON DOM structure (optional, default: true)
     *   - fetchHtml: Fetch rendered HTML (optional, default: true)
     *   - downloadAssets: Download external assets (optional, default: true)
     *   - minifyInline: Minify inline styles and scripts (optional, default: true)
     *   - site_name: Site name for og:site_name meta tag (optional)
     *   - appendBeforeBody: HTML to append before </body> tag (optional)
     *   - excludePageIds: Array of page IDs to exclude (optional)
     */
    public function __construct(array $config)
    {
        $this->validateConfig($config);
        $this->config = $config;

        // Initialize components
        $this->apiClient = new WebflowApiClient($config['apiKey']);
        $this->localeFetcher = new WebflowLocaleFetcher($config);
        $this->sitemapGenerator = new SitemapGenerator(
            $config['outputDir'],
            $config['hostUrl'] ?? $config['siteUrl']
        );
    }

    /**
     * Validate required configuration
     */
    private function validateConfig(array $config): void
    {
        $required = ['apiKey', 'siteId', 'siteUrl', 'outputDir'];

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new Exception("Required configuration key '{$key}' is missing or empty");
            }
        }
    }

    /**
     * Generate the complete site
     *
     * This method orchestrates the entire site generation process:
     * 1. Generate locale-specific HTML pages with translations
     * 2. Generate sitemap.xml with auto-detected languages
     * 3. Generate robots.txt
     * 4. Copy public files
     *
     * @return array Statistics about the generation process
     */
    public function generate(): array
    {
        $this->startTime = microtime(true);

        try {
            $this->printHeader();

            // Step 1: Generate locale pages
            $this->generateLocalePages();

            // Step 2: Generate sitemap and robots.txt
            $this->generateSitemap();

            // Step 3: Copy public files
            $this->copyPublicFiles();

            $stats = $this->printFooter();

            return $stats;
        } catch (Exception $e) {
            $this->printError($e);
            throw $e;
        }
    }

    /**
     * Step 1: Generate locale-specific HTML pages
     */
    private function generateLocalePages(): void
    {
        echo "STEP 1: Generating locale-specific HTML pages..." . PHP_EOL;
        echo "------------------------------------" . PHP_EOL;

        $this->localeFetcher->run();
    }

    /**
     * Step 2: Generate sitemap and robots.txt
     */
    private function generateSitemap(): void
    {
        echo PHP_EOL . "STEP 2: Generating sitemap and robots.txt..." . PHP_EOL;
        echo "------------------------------------" . PHP_EOL;

        // Fetch all pages for sitemap generation
        $sites = $this->apiClient->getSites();

        if (empty($sites)) {
            throw new Exception('No sites found');
        }

        $site = $sites[0]; // Use first site
        $locales = $this->extractLocales($site);

        // Fetch all pages with locale tags
        $allPages = $this->fetchAllPagesWithLocales($locales);

        // Generate sitemap and robots.txt
        $this->sitemapGenerator->generateSitemap($allPages, $this->config['excludePageIds'] ?? []);
        echo PHP_EOL;
        $this->sitemapGenerator->generateRobotsTxt();
    }

    /**
     * Step 3: Copy public files
     */
    private function copyPublicFiles(): void
    {
        if (empty($this->config['publicDir'])) {
            return;
        }

        echo PHP_EOL . "STEP 3: Copying public files..." . PHP_EOL;
        echo "------------------------------------" . PHP_EOL;
        $this->sitemapGenerator->copyPublicFiles($this->config['publicDir']);
    }

    /**
     * Extract locale information from site data
     */
    private function extractLocales(array $site): array
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

    /**
     * Fetch all pages for all locales with locale tags
     */
    private function fetchAllPagesWithLocales(array $locales): array
    {
        $allPages = [];

        foreach ($locales as $locale) {
            $localeId = $locale['primary'] ? null : $locale['id'];
            $pages = $this->apiClient->getPages($this->config['siteId'], $localeId);

            // Add locale tag to each page
            foreach ($pages as &$page) {
                $page['localeTag'] = $locale['tag'];
            }

            $allPages = array_merge($allPages, $pages);
        }

        return $allPages;
    }

    /**
     * Print header
     */
    private function printHeader(): void
    {
        echo PHP_EOL;
        echo "====================================" . PHP_EOL;
        echo "   Webflow Site Generator" . PHP_EOL;
        echo "====================================" . PHP_EOL . PHP_EOL;
    }

    /**
     * Print footer with statistics
     */
    private function printFooter(): array
    {
        $endTime = microtime(true);
        $duration = round($endTime - $this->startTime, 2);

        echo PHP_EOL . "====================================" . PHP_EOL;
        echo "   ✓ Site generation completed!" . PHP_EOL;
        echo "   Duration: {$duration}s" . PHP_EOL;
        echo "====================================" . PHP_EOL;

        return [
            'duration' => $duration,
            'success' => true
        ];
    }

    /**
     * Print error message
     */
    private function printError(Exception $e): void
    {
        echo PHP_EOL . "❌ Error: " . $e->getMessage() . PHP_EOL;
        echo $e->getTraceAsString() . PHP_EOL;
    }

    /**
     * Get the API client instance
     */
    public function getApiClient(): WebflowApiClient
    {
        return $this->apiClient;
    }

    /**
     * Get the sitemap generator instance
     */
    public function getSitemapGenerator(): SitemapGenerator
    {
        return $this->sitemapGenerator;
    }

    /**
     * Get the locale fetcher instance
     */
    public function getLocaleFetcher(): WebflowLocaleFetcher
    {
        return $this->localeFetcher;
    }
}
