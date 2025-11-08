<?php

namespace Sordahl\WebflowParser;

/**
 * Generates locale-specific HTML files with translations
 */
class LocaleGenerator
{
    private string $outputDir;
    private string $jsonDir;
    private HtmlProcessor $htmlProcessor;
    private TranslationBuilder $translationBuilder;

    public function __construct(
        string $outputDir,
        string $jsonDir,
        HtmlProcessor $htmlProcessor,
        TranslationBuilder $translationBuilder
    ) {
        $this->outputDir = rtrim($outputDir, '/');
        $this->jsonDir = rtrim($jsonDir, '/');
        $this->htmlProcessor = $htmlProcessor;
        $this->translationBuilder = $translationBuilder;

        // Create directories if they don't exist
        if (!is_dir($this->outputDir)) {
            mkdir($this->outputDir, 0755, true);
        }
        if (!is_dir($this->jsonDir)) {
            mkdir($this->jsonDir, 0755, true);
        }
    }

    /**
     * Save JSON content to file
     */
    public function saveJsonContent(array $content, string $pageSlug, string $localeTag): string
    {
        $filename = 'page-content-' . $pageSlug . '-' . $localeTag . '.json';
        $filepath = $this->jsonDir . '/' . $filename;

        file_put_contents($filepath, json_encode($content, JSON_PRETTY_PRINT));

        return $filepath;
    }

    /**
     * Save HTML file with proper path structure
     */
    public function saveHtmlFile(string $html, string $publishedPath): string
    {
        // Remove leading slash and handle index files
        $publishedPath = ltrim($publishedPath, '/');

        // If path is empty or ends with /, it's an index file
        if (empty($publishedPath)) {
            $publishedPath = 'index';
        } elseif (substr($publishedPath, -1) === '/') {
            $publishedPath = rtrim($publishedPath, '/') . '/index';
        }

        // Create the full file path
        $filepath = $this->outputDir . '/' . $publishedPath . '.html';

        // Create directory if it doesn't exist
        $directory = dirname($filepath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($filepath, $html);

        return $filepath;
    }

    /**
     * Generate translated HTML for a locale
     */
    public function generateTranslatedHtml(
        string $defaultHtml,
        string $defaultJsonPath,
        string $localeJsonPath,
        string $publishedPath,
        string $appendBeforeBody = '',
        string $localeTag = '',
        ?array $localeMetadata = null,
        string $hostUrl = ''
    ): ?string {
        // Load JSON files
        if (!file_exists($defaultJsonPath) || !file_exists($localeJsonPath)) {
            echo "    Warning: Missing JSON files for translation" . PHP_EOL;
            return null;
        }

        $defaultJson = json_decode(file_get_contents($defaultJsonPath), true);
        $localeJson = json_decode(file_get_contents($localeJsonPath), true);

        if (!$defaultJson || !$localeJson) {
            echo "    Warning: Failed to parse JSON files" . PHP_EOL;
            return null;
        }

        // Build translation map
        $translationMap = $this->translationBuilder->buildTranslationMap($defaultJson, $localeJson);

        echo "    Found " . count($translationMap) . " translation pairs" . PHP_EOL;

        if (empty($translationMap)) {
            echo "    No translations found (pages may be identical)" . PHP_EOL;
            return null;
        }

        // Apply translations
        $translatedHtml = $this->htmlProcessor->applyTranslations($defaultHtml, $translationMap);

        // Apply meta tag translations if metadata is provided
        if (!empty($localeMetadata)) {
            echo "    Applying meta/SEO translations..." . PHP_EOL;
            $translatedHtml = $this->htmlProcessor->applyMetaTranslations($translatedHtml, $localeMetadata);
        }

        // Add alternate hreflang link to default (English) version
        if (!empty($hostUrl)) {
            $defaultUrl = rtrim($hostUrl, '/') . '/';
            echo "    Adding alternate hreflang link to: $defaultUrl" . PHP_EOL;
            $translatedHtml = $this->htmlProcessor->addAlternateHreflangLinks($translatedHtml, $defaultUrl);
        }

        // Fix relative paths for subdirectory location
        $translatedHtml = $this->htmlProcessor->fixRelativePaths($translatedHtml, $publishedPath);

        // Update lang attribute if locale tag is provided
        if (!empty($localeTag)) {
            $translatedHtml = $this->htmlProcessor->updateLangAttribute($translatedHtml, $localeTag);
        }

        // Add hreflang attributes to language switcher links
        $translatedHtml = $this->htmlProcessor->addHreflangAttributes($translatedHtml);

        // Append HTML before </body> tag if configured
        if (!empty($appendBeforeBody)) {
            $translatedHtml = $this->htmlProcessor->appendBeforeBody($translatedHtml, $appendBeforeBody);
        }

        // Save translated HTML
        $filepath = $this->saveHtmlFile($translatedHtml, $publishedPath);
        echo "    Translated HTML saved to: $filepath" . PHP_EOL;

        return $filepath;
    }

    /**
     * Get JSON file path for a page
     */
    public function getJsonFilePath(string $pageSlug, string $localeTag): string
    {
        return $this->jsonDir . '/page-content-' . $pageSlug . '-' . $localeTag . '.json';
    }
}
