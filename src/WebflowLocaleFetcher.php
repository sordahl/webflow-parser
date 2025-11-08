<?php

namespace HiFriday\WebflowLocale;

/**
 * Main orchestrator class for fetching and generating locale-specific Webflow pages
 */
class WebflowLocaleFetcher
{
    private WebflowApiClient $apiClient;
    private HtmlProcessor $htmlProcessor;
    private LocaleGenerator $localeGenerator;
    private TranslationBuilder $translationBuilder;

    private string $siteUrl;
    private string $hostUrl;
    private array $excludePageIds;
    private bool $fetchContent;
    private bool $fetchHtml;
    private bool $downloadAssets;
    private bool $minifyInline;
    private string $appendBeforeBody;
    private string $siteName;

    public function __construct(array $config)
    {
        // Initialize API client
        $this->apiClient = new WebflowApiClient($config['apiKey']);

        // Initialize components
        $this->translationBuilder = new TranslationBuilder();

        $this->htmlProcessor = new HtmlProcessor(
            $config['siteUrl'],
            $config['hostUrl'] ?? $config['siteUrl'],
            $config['assetsDir'] ?? $config['outputDir'] . '/assets',
            $config['site_name'] ?? ''
        );

        $this->localeGenerator = new LocaleGenerator(
            $config['outputDir'],
            $config['jsonDir'] ?? $config['outputDir'] . '/json',
            $this->htmlProcessor,
            $this->translationBuilder
        );

        // Configuration
        $this->siteUrl = $config['siteUrl'];
        $this->hostUrl = $config['hostUrl'] ?? $config['siteUrl'];
        $this->excludePageIds = $config['excludePageIds'] ?? [];
        $this->fetchContent = $config['fetchContent'] ?? true;
        $this->fetchHtml = $config['fetchHtml'] ?? true;
        $this->downloadAssets = $config['downloadAssets'] ?? true;
        $this->minifyInline = $config['minifyInline'] ?? true;
        $this->appendBeforeBody = $config['appendBeforeBody'] ?? '';
        $this->siteName = $config['site_name'] ?? '';
    }

    /**
     * Run the locale fetcher
     */
    public function run(): void
    {
        echo "Starting Webflow Locale Fetcher..." . PHP_EOL . PHP_EOL;

        // Get all sites
        $sites = $this->apiClient->getSites();

        if (empty($sites)) {
            echo 'No sites found' . PHP_EOL;
            return;
        }

        echo "Found " . count($sites) . " site(s)" . PHP_EOL . PHP_EOL;

        // Process each site
        foreach ($sites as $site) {
            $this->processSite($site);
        }

        echo PHP_EOL . "Fetch completed successfully!" . PHP_EOL;
    }

    /**
     * Process a single site
     */
    private function processSite(array $site): void
    {
        echo "Site: " . ($site['displayName'] ?? $site['shortName'] ?? 'Unknown') . PHP_EOL;
        echo "Site ID: " . $site['id'] . PHP_EOL;

        // Extract locale information
        $locales = $this->extractLocales($site);
        $localesByPage = $this->buildLocaleMap($locales);

        echo PHP_EOL;

        // Fetch pages for all locales
        $allPages = $this->fetchAllPages($site['id'], $locales);

        // Filter out excluded pages
        $allPages = $this->filterPages($allPages);

        // Group pages by slug for processing
        $pagesBySlug = $this->groupPagesBySlug($allPages);

        // Phase 1: Fetch and save all JSON content
        echo PHP_EOL . "Phase 1: Fetching JSON content for all pages..." . PHP_EOL . PHP_EOL;
        $this->fetchJsonContent($allPages, $localesByPage);

        // Phase 2: Fetch HTML and generate translations
        echo PHP_EOL . "Phase 2: Fetching HTML and generating translations..." . PHP_EOL . PHP_EOL;
        $this->generateLocalizedPages($allPages, $pagesBySlug, $localesByPage);

        echo PHP_EOL . "Total pages processed: " . count($allPages) . PHP_EOL;
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

        echo "Available Locales: " . PHP_EOL;

        // Handle primary locale
        if (isset($site['locales']['primary'])) {
            $locale = $site['locales']['primary'];
            $locales[] = [
                'id' => $locale['id'] ?? null,
                'tag' => $locale['tag'] ?? 'N/A',
                'displayName' => $locale['displayName'] ?? 'N/A',
                'primary' => true
            ];

            echo "  - {$locales[0]['tag']}: {$locales[0]['displayName']} (Primary) (ID: {$locales[0]['id']})" . PHP_EOL;
        }

        // Handle secondary locales
        if (isset($site['locales']['secondary']) && is_array($site['locales']['secondary'])) {
            foreach ($site['locales']['secondary'] as $locale) {
                $locales[] = [
                    'id' => $locale['id'] ?? null,
                    'tag' => $locale['tag'] ?? 'N/A',
                    'displayName' => $locale['displayName'] ?? 'N/A',
                    'primary' => false
                ];

                $last = end($locales);
                echo "  - {$last['tag']}: {$last['displayName']} (Secondary) (ID: {$last['id']})" . PHP_EOL;
            }
        }

        return $locales;
    }

    /**
     * Build locale map (localeId => localeTag)
     */
    private function buildLocaleMap(array $locales): array
    {
        $map = [];
        foreach ($locales as $locale) {
            if ($locale['id']) {
                $map[$locale['id']] = $locale['tag'];
            }
        }
        return $map;
    }

    /**
     * Fetch all pages for all locales
     */
    private function fetchAllPages(string $siteId, array $locales): array
    {
        $allPages = [];

        if (empty($locales)) {
            // No locales specified, fetch all pages
            echo "Fetching pages for site: $siteId..." . PHP_EOL;
            $pages = $this->apiClient->getPages($siteId);
            $allPages = array_merge($allPages, $pages);
        } else {
            // Fetch pages for each locale
            foreach ($locales as $locale) {
                // Only pass locale parameter for secondary locales
                $localeId = $locale['primary'] ? null : $locale['id'];
                $localeInfo = $localeId ? " (locale: {$locale['tag']})" : "";

                echo "Fetching pages for site: $siteId$localeInfo..." . PHP_EOL;
                $pages = $this->apiClient->getPages($siteId, $localeId);
                $allPages = array_merge($allPages, $pages);
            }
        }

        return $allPages;
    }

    /**
     * Filter out excluded pages
     */
    private function filterPages(array $pages): array
    {
        if (empty($this->excludePageIds)) {
            return $pages;
        }

        return array_filter($pages, function ($page) {
            return !in_array($page['id'], $this->excludePageIds);
        });
    }

    /**
     * Group pages by slug
     */
    private function groupPagesBySlug(array $pages): array
    {
        $grouped = [];
        foreach ($pages as $page) {
            $slug = $page['slug'] ?? $page['id'];
            if (!isset($grouped[$slug])) {
                $grouped[$slug] = [];
            }
            $grouped[$slug][] = $page;
        }
        return $grouped;
    }

    /**
     * Fetch and save JSON content for all pages
     */
    private function fetchJsonContent(array $pages, array $localesByPage): void
    {
        foreach ($pages as $page) {
            if (!$this->fetchContent) {
                continue;
            }

            $localeId = $page['localeId'] ?? null;
            $localeTag = $localesByPage[$localeId] ?? 'default';

            echo "Fetching JSON for: " . ($page['title'] ?? 'N/A') . " ({$localeTag})" . PHP_EOL;

            $content = $this->apiClient->getPageContent($page['id'], $localeId);

            if ($content) {
                $pageSlug = $page['slug'] ?: $page['id'];
                $filepath = $this->localeGenerator->saveJsonContent($content, $pageSlug, $localeTag);
                echo "  Saved to: $filepath" . PHP_EOL;
            }

            echo str_repeat("-", 80) . PHP_EOL;
        }
    }

    /**
     * Generate localized HTML pages
     */
    private function generateLocalizedPages(array $pages, array $pagesBySlug, array $localesByPage): void
    {
        foreach ($pages as $page) {
            $localeId = $page['localeId'] ?? null;
            $localeTag = $localesByPage[$localeId] ?? null;
            $isPrimary = ($localeTag === 'en' || !$localeTag);

            // Only process primary locales for HTML fetching
            if (!$isPrimary || !$this->fetchHtml) {
                continue;
            }

            echo "Processing: " . ($page['title'] ?? 'N/A') . " (Primary: $localeTag)" . PHP_EOL;

            // Fetch HTML from published site
            $html = $this->apiClient->getPublishedHtml($this->siteUrl, '');

            if (!$html) {
                echo "  Warning: Failed to fetch HTML" . PHP_EOL;
                continue;
            }

            // Clean up HTML
            $html = $this->htmlProcessor->cleanupHtml($html);

            // Download assets if enabled
            if ($this->downloadAssets) {
                $html = $this->htmlProcessor->downloadExternalAssets($html);
            }

            // Minify inline styles and scripts if enabled
            if ($this->minifyInline) {
                $html = $this->htmlProcessor->minifyInlineContent($html);
            }

            // Keep a copy of HTML before path fixing for translation purposes
            $htmlForTranslation = $html;

            // Add og:site_name meta tag
            $html = $this->htmlProcessor->addSiteNameMeta($html);

            // Fix relative paths for primary HTML
            $publishedPath = $page['publishedPath'] ?? '/';
            $html = $this->htmlProcessor->fixRelativePaths($html, $publishedPath);

            // Append HTML before </body> tag if configured
            if (!empty($this->appendBeforeBody)) {
                $html = $this->htmlProcessor->appendBeforeBody($html, $this->appendBeforeBody);
            }

            // Save primary HTML
            $filepath = $this->localeGenerator->saveHtmlFile($html, $publishedPath);
            echo "  Rendered HTML saved to: $filepath" . PHP_EOL;

            // Generate translations for secondary locales (using unmodified HTML)
            $this->generateTranslations($page, $pagesBySlug, $localesByPage, $htmlForTranslation);

            echo str_repeat("-", 80) . PHP_EOL;
        }
    }

    /**
     * Generate translations for secondary locales
     */
    private function generateTranslations(
        array $primaryPage,
        array $pagesBySlug,
        array $localesByPage,
        string $defaultHtml
    ): void {
        $slug = $primaryPage['slug'] ?? $primaryPage['id'];
        $relatedPages = $pagesBySlug[$slug] ?? [];

        $primaryLocaleId = $primaryPage['localeId'] ?? null;
        $primaryLocaleTag = $localesByPage[$primaryLocaleId] ?? 'default';

        foreach ($relatedPages as $localePage) {
            $relatedLocaleId = $localePage['localeId'] ?? null;
            $relatedLocaleTag = $localesByPage[$relatedLocaleId] ?? null;
            $isRelatedPrimary = ($relatedLocaleTag === 'en' || !$relatedLocaleTag);

            // Skip primary locale
            if ($isRelatedPrimary || $relatedLocaleId === $primaryLocaleId) {
                continue;
            }

            echo PHP_EOL . "  Processing locale translation: $relatedLocaleTag..." . PHP_EOL;

            // Fetch metadata for the locale page (includes SEO and Open Graph data)
            echo "    Fetching metadata for SEO/meta tags..." . PHP_EOL;
            $localeMetadata = $this->apiClient->getPageMetadata($localePage['id'], $relatedLocaleId);

            // Get JSON file paths
            $defaultJsonPath = $this->localeGenerator->getJsonFilePath(
                $primaryPage['slug'] ?: $primaryPage['id'],
                $primaryLocaleTag
            );

            $localeJsonPath = $this->localeGenerator->getJsonFilePath(
                $localePage['slug'] ?: $localePage['id'],
                $relatedLocaleTag
            );

            // Generate translated HTML with metadata
            $this->localeGenerator->generateTranslatedHtml(
                $defaultHtml,
                $defaultJsonPath,
                $localeJsonPath,
                $localePage['publishedPath'] ?? '/' . $relatedLocaleTag,
                $this->appendBeforeBody,
                $relatedLocaleTag,
                $localeMetadata,
                $this->hostUrl
            );
        }
    }
}
