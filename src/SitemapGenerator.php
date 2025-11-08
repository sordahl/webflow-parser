<?php

namespace HiFriday\WebflowLocale;

/**
 * Generates sitemap.xml and robots.txt files for SEO
 */
class SitemapGenerator
{
    private string $outputDir;
    private string $hostUrl;

    public function __construct(string $outputDir, string $hostUrl)
    {
        $this->outputDir = rtrim($outputDir, '/');
        $this->hostUrl = rtrim($hostUrl, '/');

        // Create output directory if it doesn't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
    }

    /**
     * Generate sitemap.xml from pages array
     *
     * @param array $allPages Array of all pages with localeTag
     * @param array $excludePageIds Array of page IDs to exclude
     * @return string Path to generated sitemap file
     */
    public function generateSitemap(array $allPages, array $excludePageIds = []): string
    {
        echo "Generating sitemap.xml..." . PHP_EOL;

        // Filter out excluded pages
        $allPages = $this->filterPages($allPages, $excludePageIds);

        // Auto-detect supported locales
        $locales = $this->detectLocales($allPages);
        echo "  Detected locales: " . implode(', ', array_keys($locales)) . PHP_EOL;

        // Group pages by slug to identify translations
        $pagesBySlug = $this->groupPagesBySlug($allPages);

        // Generate sitemap XML
        $sitemapXml = $this->buildSitemapXml($pagesBySlug, $locales);

        // Save to file
        $filepath = $this->outputDir . '/sitemap.xml';
        file_put_contents($filepath, $sitemapXml);

        echo "  Saved to: $filepath" . PHP_EOL;

        return $filepath;
    }

    /**
     * Generate robots.txt file
     *
     * @return string Path to generated robots.txt file
     */
    public function generateRobotsTxt(): string
    {
        echo "Generating robots.txt..." . PHP_EOL;

        $content = "# robots.txt" . "\n";
        $content .= "# Generated on " . date('Y-m-d H:i:s') . "\n\n";
        $content .= "User-agent: *" . "\n";
        $content .= "Allow: /" . "\n\n";
        $content .= "# Sitemap" . "\n";
        $content .= "Sitemap: {$this->hostUrl}/sitemap.xml" . "\n";

        $filepath = $this->outputDir . '/robots.txt';
        file_put_contents($filepath, $content);

        echo "  Saved to: $filepath" . PHP_EOL;

        return $filepath;
    }

    /**
     * Filter out excluded pages
     */
    private function filterPages(array $pages, array $excludePageIds): array
    {
        if (empty($excludePageIds)) {
            return $pages;
        }

        return array_filter($pages, function ($page) use ($excludePageIds) {
            return !in_array($page['id'], $excludePageIds);
        });
    }

    /**
     * Auto-detect supported locales from pages
     *
     * @return array Map of locale tag => locale info
     */
    private function detectLocales(array $pages): array
    {
        $locales = [];

        foreach ($pages as $page) {
            $localeTag = $page['localeTag'] ?? 'en';
            $localeId = $page['localeId'] ?? null;

            if (!isset($locales[$localeTag])) {
                $locales[$localeTag] = [
                    'tag' => $localeTag,
                    'id' => $localeId,
                    'primary' => ($localeTag === 'en' || $localeId === null)
                ];
            }
        }

        // Sort so primary locale comes first
        uasort($locales, function ($a, $b) {
            return $b['primary'] <=> $a['primary'];
        });

        return $locales;
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
     * Build sitemap XML content
     */
    private function buildSitemapXml(array $pagesBySlug, array $locales): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
        $xml .= '        xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n";

        // Find primary locale tag
        $primaryLocaleTag = null;
        foreach ($locales as $locale) {
            if ($locale['primary']) {
                $primaryLocaleTag = $locale['tag'];
                break;
            }
        }

        // Process each page group (pages with same slug but different locales)
        foreach ($pagesBySlug as $slug => $pages) {
            // Find primary page
            $primaryPage = null;
            $localizedPages = [];

            foreach ($pages as $page) {
                $pageLocaleTag = $page['localeTag'] ?? 'en';
                if ($pageLocaleTag === $primaryLocaleTag) {
                    $primaryPage = $page;
                } else {
                    $localizedPages[] = $page;
                }
            }

            // Skip if no primary page found
            if (!$primaryPage) {
                continue;
            }

            // Add primary page entry
            $xml .= $this->buildUrlEntry($primaryPage, $localizedPages, $primaryLocaleTag);

            // Add entries for localized versions
            foreach ($localizedPages as $localePage) {
                $xml .= $this->buildUrlEntry($localePage, [$primaryPage], $primaryLocaleTag, $localizedPages);
            }
        }

        $xml .= "</urlset>\n";

        return $xml;
    }

    /**
     * Build a single URL entry for sitemap
     */
    private function buildUrlEntry(
        array $page,
        array $alternatePages,
        string $primaryLocaleTag,
        array $additionalAlternates = []
    ): string {
        $path = $page['publishedPath'] ?? '/';
        $url = $this->hostUrl . $path;
        $localeTag = $page['localeTag'] ?? 'en';

        // Get last updated date
        $lastmod = isset($page['lastUpdated'])
            ? date('Y-m-d', strtotime($page['lastUpdated']))
            : date('Y-m-d');

        // Determine priority and change frequency
        $priority = ($path === '/' || $path === '/da/') ? '1.0' : '0.8';
        $changefreq = 'weekly';

        $xml = "  <url>\n";
        $xml .= "    <loc>" . htmlspecialchars($url, ENT_XML1, 'UTF-8') . "</loc>\n";
        $xml .= "    <lastmod>{$lastmod}</lastmod>\n";
        $xml .= "    <changefreq>{$changefreq}</changefreq>\n";
        $xml .= "    <priority>{$priority}</priority>\n";

        // Add alternate language links
        foreach ($alternatePages as $altPage) {
            $altLocaleTag = $altPage['localeTag'] ?? 'en';
            // Skip self-reference
            if ($altLocaleTag === $localeTag) {
                continue;
            }

            $altPath = $altPage['publishedPath'] ?? '/' . $altLocaleTag;
            $altUrl = $this->hostUrl . $altPath;

            $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$altLocaleTag}\" href=\"" . htmlspecialchars($altUrl, ENT_XML1, 'UTF-8') . "\"/>\n";
        }

        // Add additional alternate links (for localized pages linking to other locales)
        foreach ($additionalAlternates as $altPage) {
            $altLocaleTag = $altPage['localeTag'] ?? 'en';
            // Skip self-reference
            if ($altLocaleTag === $localeTag) {
                continue;
            }

            $altPath = $altPage['publishedPath'] ?? '/' . $altLocaleTag;
            $altUrl = $this->hostUrl . $altPath;

            $xml .= "    <xhtml:link rel=\"alternate\" hreflang=\"{$altLocaleTag}\" href=\"" . htmlspecialchars($altUrl, ENT_XML1, 'UTF-8') . "\"/>\n";
        }

        $xml .= "  </url>\n";

        return $xml;
    }

    /**
     * Copy directory contents recursively
     */
    public function copyPublicFiles(string $publicDir): void
    {
        echo "Copying files from public to dist..." . PHP_EOL;

        if (!is_dir($publicDir)) {
            echo "  No public directory found, skipping copy" . PHP_EOL;
            return;
        }

        $this->copyDirectory($publicDir, $this->outputDir);
        echo "  Files copied successfully" . PHP_EOL;
    }

    /**
     * Recursively copy directory contents
     */
    private function copyDirectory(string $source, string $destination): void
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
                $this->copyDirectory($sourcePath, $destPath);
            } else {
                // Copy file
                copy($sourcePath, $destPath);
                echo "    Copied: $file" . PHP_EOL;
            }
        }
        closedir($dir);
    }
}
