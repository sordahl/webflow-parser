<?php

namespace Sordahl\WebflowParser;

/**
 * Processes and transforms HTML content
 * Uses concepts from WebflowParser for cleaning and asset handling
 */
class HtmlProcessor
{
    private string $siteUrl;
    private string $hostUrl;
    private string $assetsDir;
    private string $siteName;

    public function __construct(string $siteUrl, string $hostUrl, string $assetsDir, string $siteName = '')
    {
        $this->siteUrl = rtrim($siteUrl, '/');
        $this->hostUrl = rtrim($hostUrl, '/');
        $this->assetsDir = $assetsDir;
        $this->siteName = $siteName;

        // Create assets directory if it doesn't exist
        if (!is_dir($this->assetsDir)) {
            mkdir($this->assetsDir, 0755, true);
        }
    }

    /**
     * Clean up HTML content
     * Adapted from WebflowParser::cleanup_html()
     */
    public function cleanupHtml(string $html): string
    {
        $patterns = [
            '/<!--(.|\s)*?-->/', // Remove comments
            '/(<meta content="Webflow" name="generator"\/>)/',
            '/\?site=([a-zA-Z0-9]+)/', // Remove ?site= param from script src
            '/\s(?:integrity|crossorigin)="[^"]*"/' // Remove integrity and crossorigin from script
        ];

        foreach ($patterns as $pattern)
            $html = preg_replace($pattern, '', $html);

        // Replace site branding
        $html = str_replace(
            'data-wf-domain="' . $this->siteUrl . '"',
            'data-wf-domain="' . $this->hostUrl . '"',
            $html
        );

        return $html;
    }

    /**
     * Minify inline styles and scripts
     * Compresses CSS in <style> tags and JavaScript in <script> tags
     */
    public function minifyInlineContent(string $html): string
    {
        // Minify inline <style> tags
        $html = preg_replace_callback(
            '/<style([^>]*)>(.*?)<\/style>/is',
            function ($matches) {
                $attributes = $matches[1];
                $css = $matches[2];
                $minifiedCss = $this->minifyCss($css);
                return '<style' . $attributes . '>' . $minifiedCss . '</style>';
            },
            $html
        );

        // Minify inline <script> tags (only non-external scripts)
        $html = preg_replace_callback(
            '/<script(?![^>]*\bsrc=)([^>]*)>(.*?)<\/script>/is',
            function ($matches) {
                $attributes = $matches[1];
                $js = $matches[2];
                $minifiedJs = $this->minifyJs($js);
                return '<script' . $attributes . '>' . $minifiedJs . '</script>';
            },
            $html
        );

        return $html;
    }

    /**
     * Minify CSS content
     */
    private function minifyCss(string $css): string
    {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);

        // Remove whitespace
        $css = preg_replace('/\s+/', ' ', $css);

        // Remove spaces around certain characters
        $css = preg_replace('/\s*([:;{}(),])\s*/', '$1', $css);

        // Remove trailing semicolons before }
        $css = preg_replace('/;+}/', '}', $css);

        // Remove unnecessary quotes
        $css = preg_replace('/url\((["\'])([^"\']+)\1\)/', 'url($2)', $css);

        return trim($css);
    }

    /**
     * Minify JavaScript content
     */
    private function minifyJs(string $js): string
    {
        // Remove single-line comments (// ...) but preserve URLs (http://, https://)
        $js = preg_replace('#(?<!:)//[^\n]*#', '', $js);

        // Remove multi-line comments (/* ... */)
        $js = preg_replace('#/\*.*?\*/#s', '', $js);

        // Remove whitespace (but preserve strings)
        // This is a simple approach - for production, consider using a proper JS minifier
        $js = preg_replace('/\s+/', ' ', $js);

        // Remove spaces around operators and punctuation
        $js = preg_replace('/\s*([=+\-*\/%<>!&|?:,;{}()\[\]])\s*/', '$1', $js);

        return trim($js);
    }

    /**
     * Download external assets and update HTML references
     * Adapted from WebflowParser::downloadExternalAssets()
     */
    public function downloadExternalAssets(string $html): string
    {
        $files = [];
        $pattern = 'https:\/\/[^"]*\/[^"]*\.[^"]+';
        preg_match_all('/' . $pattern . '/', $html, $fileList, PREG_PATTERN_ORDER);

        // Clean up fileList into $files array
        foreach ($fileList[0] as $file) {
            $file = str_replace(['"', '&quot;)'], '', $file);
            $files = array_merge($files, explode(' ', $file));
        }

        // Remove non-valid URLs
        $files = array_filter($files, function ($url) {
            return filter_var($url, FILTER_VALIDATE_URL);
        });

        if (empty($files)) {
            return $html;
        }

        echo "  Found " . count($files) . " external assets" . PHP_EOL;

        foreach ($files as $file) {
            // Get just the filename without path for sanitization
            $urlPath = parse_url($file, PHP_URL_PATH);
            $filename = $this->sanitizeFilename(basename($urlPath));

            $assetPath = $this->assetsDir . DIRECTORY_SEPARATOR . $filename;

            if (!file_exists($assetPath)) {
                echo "  Downloading: $filename..." . PHP_EOL;

                $contextOptions = ["ssl" => ["verify_peer" => false, "verify_peer_name" => false]];
                $fileContent = @file_get_contents($file, false, stream_context_create($contextOptions));

                if ($fileContent === false) {
                    echo "    Warning: Failed to download $file" . PHP_EOL;
                    continue;
                }

                // Handle gzipped content
                if (isset($http_response_header) && in_array('Content-Encoding: gzip', $http_response_header)) {
                    $fileContent = gzdecode($fileContent);
                }

                // Process CSS files for nested external resources
                if (pathinfo($filename, PATHINFO_EXTENSION) === 'css') {
                    $fileContent = $this->processCssAssets($fileContent);
                }

                file_put_contents($assetPath, $fileContent);
            }

            $html = str_replace($file, './assets/' . $filename, $html);
        }

        return $html;
    }

    /**
     * Process CSS files for external resources
     */
    private function processCssAssets(string $css): string
    {
        $pattern = 'https:\/\/[^"\'()]*\/[^"\'()]*\.[^"\'()]+';
        preg_match_all('/' . $pattern . '/', $css, $fileList, PREG_PATTERN_ORDER);

        echo "    Found " . count($fileList[0]) . " assets in CSS" . PHP_EOL;

        foreach ($fileList[0] as $file) {
            if (filter_var($file, FILTER_VALIDATE_URL)) {
                $urlPath = parse_url($file, PHP_URL_PATH);
                $filename = $this->sanitizeFilename(basename($urlPath));

                $assetPath = $this->assetsDir . DIRECTORY_SEPARATOR . $filename;

                if (!file_exists($assetPath)) {
                    echo "    Downloading CSS asset: $filename..." . PHP_EOL;

                    $contextOptions = ["ssl" => ["verify_peer" => false, "verify_peer_name" => false]];
                    $fileContent = @file_get_contents($file, false, stream_context_create($contextOptions));

                    if ($fileContent !== false) {
                        file_put_contents($assetPath, $fileContent);
                        echo "      Downloaded successfully" . PHP_EOL;
                    } else {
                        echo "      Warning: Failed to download" . PHP_EOL;
                    }
                }

                // CSS files are in /assets/, so reference other assets in same folder with just filename
                $css = str_replace($file, './' . $filename, $css);
            }
        }

        return $css;
    }

    /**
     * Sanitize filename
     * Adapted from WebflowParser::$removeFilenameCharacters
     */
    private function sanitizeFilename(string $filename): string
    {
        $removeChars = [
            'Æ',
            'æ',
            'ø',
            'Ø',
            'å',
            'Å',
            ' ',
            '(',
            ')',
            'webflow',
            'sordahl',
            '%20',
            '%21',
            '%22',
            '%23',
            '%24',
            '%25',
            '%26',
            '%27',
            '%28',
            '%29',
            '%2A',
            '%2B',
            '%2C',
            '%2D',
            '%2E',
            '%2F',
            '%30',
            '%31',
            '%32',
            '%33',
            '%34',
            '%35',
            '%36',
            '%37',
            '%38',
            '%39',
            '%3A',
            '%3B',
            '%3C',
            '%3D',
            '%3E',
            '%3F',
            '%40',
            '%41',
            '%42',
            '%43',
            '%44',
            '%45',
            '%46',
            '%47',
            '%48',
            '%49',
            '%4A',
            '%4B',
            '%4C',
            '%4D',
            '%4E',
            '%4F',
            '%50',
            '%51',
            '%52',
            '%53',
            '%54',
            '%55',
            '%56',
            '%57',
            '%58',
            '%59',
            '%5A',
            '%5B',
            '%5C',
            '%5D',
            '%5E',
            '%5F',
            '%60',
            '%61',
            '%62',
            '%63',
            '%64',
            '%65',
            '%66',
            '%67',
            '%68',
            '%69',
            '%6A',
            '%6B',
            '%6C',
            '%6D',
            '%6E',
            '%6F',
            '%70',
            '%71',
            '%72',
            '%73',
            '%74',
            '%75',
            '%76',
            '%77',
            '%78',
            '%79',
            '%7A',
            '%7B',
            '%7C',
            '%7D',
            '%7E'
        ];

        // Remove URL encoded characters
        $filename = urldecode($filename);

        // Remove unwanted characters
        $filename = str_replace($removeChars, '', $filename);

        // Trim dots and spaces
        $filename = trim($filename, '. ');

        return $filename;
    }

    /**
     * Normalize HTML attributes by sorting them alphabetically
     */
    private function normalizeHtmlAttributes(string $html): string
    {
        return preg_replace_callback('/<([a-z][a-z0-9]*)\s+([^>]+)>/i', function ($matches) {
            $tag = $matches[1];
            $attrsString = $matches[2];

            // Parse attributes
            preg_match_all('/([a-z\-]+)="([^"]*)"|\s+/i', $attrsString, $attrMatches, PREG_SET_ORDER);

            $attrs = [];
            foreach ($attrMatches as $attr) {
                if (isset($attr[1]) && isset($attr[2])) {
                    $attrs[$attr[1]] = $attr[2];
                }
            }

            // Sort attributes alphabetically
            ksort($attrs);

            // Rebuild tag
            $newAttrsString = '';
            foreach ($attrs as $name => $value) {
                $newAttrsString .= ' ' . $name . '="' . $value . '"';
            }

            return '<' . $tag . $newAttrsString . '>';
        }, $html);
    }

    /**
     * Create a flexible regex pattern that matches HTML with optional w-* classes and target attribute
     */
    private function createFlexiblePattern(string $html): string
    {
        // Escape for regex
        $pattern = preg_quote($html, '/');

        // Make class attributes flexible to allow w-* classes
        // After preg_quote, class="foo" becomes class\="foo" (single backslash in string)
        // To match single \, use \\ in regex pattern
        // Replace class\="foo" with class\="foo(?:\s+w-[^"]*)?\"
        $pattern = preg_replace(
            '/class\\\\="([^"]+)"/',
            'class\\\\="$1(?:\\\\s+w-[^\\\\"]*)?\\\"',
            $pattern
        );

        // Allow optional target="_blank" after <a tag
        // Replace \<a with \<a(?:\s+target="[^"]*")?
        $pattern = str_replace('\\<a ', '\\<a(?:\\s+target="[^"]*")? ', $pattern);

        return '/' . $pattern . '/';
    }

    /**
     * Apply translations to HTML content with flexible HTML matching
     */
    public function applyTranslations(string $html, array $translationMap): string
    {
        if (empty($translationMap)) {
            return $html;
        }

        // Keep two versions: original (for output) and normalized (for matching)
        $originalHtml = $html;
        $normalizedHtml = $this->normalizeHtmlAttributes($html);

        // Remove target and hreflang attributes from <a> tags in HTML for matching (will be restored later)
        // But keep class and href to maintain context
        $normalizedHtml = preg_replace('/<a\s+([^>]*?)\s*target="[^"]*"([^>]*)>/', '<a $1$2>', $normalizedHtml);
        $normalizedHtml = preg_replace('/<a\s+target="[^"]*"\s+([^>]*)>/', '<a $1>', $normalizedHtml);
        $normalizedHtml = preg_replace('/<a\s+([^>]*?)\s*hreflang="[^"]*"([^>]*)>/', '<a $1$2>', $normalizedHtml);
        $normalizedHtml = preg_replace('/<a\s+hreflang="[^"]*"\s+([^>]*)>/', '<a $1>', $normalizedHtml);

        // Also normalize the translation map keys
        $normalizedMap = [];
        foreach ($translationMap as $original => $translation) {
            $normalizedOriginal = $this->normalizeHtmlAttributes($original);
            $normalizedMap[$normalizedOriginal] = $translation;
        }
        $translationMap = $normalizedMap;

        // Sort by length (longest first) to avoid partial replacements
        uksort($translationMap, function ($a, $b) {
            return strlen($b) - strlen($a);
        });

        foreach ($translationMap as $original => $translation) {
            // Skip if translation is the same as original
            if ($original === $translation) {
                continue;
            }

            // Try direct replacement in normalized HTML
            if (strpos($normalizedHtml, $original) !== false) {
                $normalizedHtml = str_replace($original, $translation, $normalizedHtml);
                continue;
            }

            // If original contains HTML tags, try flexible matching with regex
            if (strpos($original, '<') !== false) {
                // Normalize by removing data-w-id attributes for comparison
                $normalizedOriginal = preg_replace('/\s+data-w-id="[^"]*"/', '', $original);
                $normalizedTranslation = preg_replace('/\s+data-w-id="[^"]*"/', '', $translation);

                // Convert <br> to <br/> to match rendered HTML
                $normalizedOriginal = str_replace('<br>', '<br/>', $normalizedOriginal);
                $normalizedTranslation = str_replace('<br>', '<br/>', $normalizedTranslation);

                // Handle HTML entities that get decoded in rendered HTML
                $normalizedOriginal = html_entity_decode($normalizedOriginal, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $normalizedTranslation = html_entity_decode($normalizedTranslation, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                // Try matching with classes intact first (for context preservation)
                // Remove target and hreflang attributes for matching but keep class
                $normalizedOriginalWithClass = preg_replace('/<a\s+([^>]*?)\s*target="[^"]*"([^>]*)>/', '<a $1$2>', $normalizedOriginal);
                $normalizedOriginalWithClass = preg_replace('/<a\s+target="[^"]*"\s+([^>]*)>/', '<a $1>', $normalizedOriginalWithClass);
                $normalizedOriginalWithClass = preg_replace('/<a\s+([^>]*?)\s*hreflang="[^"]*"([^>]*)>/', '<a $1$2>', $normalizedOriginalWithClass);
                $normalizedOriginalWithClass = preg_replace('/<a\s+hreflang="[^"]*"\s+([^>]*)>/', '<a $1>', $normalizedOriginalWithClass);

                // Try exact match with class
                if (strpos($normalizedHtml, $normalizedOriginalWithClass) !== false) {
                    $normalizedHtml = str_replace($normalizedOriginalWithClass, $normalizedTranslation, $normalizedHtml);
                    continue;
                }

                // Try flexible pattern with class (allows w-* classes to be added)
                $flexiblePatternWithClass = $this->createFlexiblePattern($normalizedOriginalWithClass);
                if (preg_match($flexiblePatternWithClass, $normalizedHtml, $matches)) {
                    $normalizedHtml = str_replace($matches[0], $normalizedTranslation, $normalizedHtml);
                    continue;
                }

                // If that fails, try matching without class attributes (for cases where locale differs)
                $normalizedOriginalNoClass = preg_replace_callback(
                    '/<a\s+([^>]*)>/',
                    function ($matches) {
                        // Extract href only
                        if (preg_match('/href="([^"]*)"/', $matches[1], $hrefMatch)) {
                            return '<a href="' . $hrefMatch[1] . '">';
                        }
                        return $matches[0];
                    },
                    $normalizedOriginalWithClass
                );

                // Try flexible pattern matching (allows w-* classes)
                $flexiblePattern = $this->createFlexiblePattern($normalizedOriginalNoClass);
                if (preg_match($flexiblePattern, $normalizedHtml, $matches)) {
                    $normalizedHtml = str_replace($matches[0], $normalizedTranslation, $normalizedHtml);
                    continue;
                }

                // Fallback: try direct match without class
                if (strpos($normalizedHtml, $normalizedOriginalNoClass) !== false) {
                    $normalizedHtml = str_replace($normalizedOriginalNoClass, $normalizedTranslation, $normalizedHtml);
                    continue;
                }
            }

            // Split multi-line text into parts and replace each part individually
            $originalLines = array_filter(array_map('trim', explode("\n", $original)));
            $translationLines = array_filter(array_map('trim', explode("\n", $translation)));

            // If multi-line text, replace line by line
            if (count($originalLines) > 1) {
                foreach ($originalLines as $index => $originalLine) {
                    if (isset($translationLines[$index]) && $originalLine !== $translationLines[$index]) {
                        $normalizedHtml = str_replace($originalLine, $translationLines[$index], $normalizedHtml);
                    }
                }
            } else {
                // Single line - try exact match and variants
                $originalVariants = [
                    $original,
                    trim($original),
                    str_replace("\n", ' ', $original),
                    str_replace("\n", '', $original),
                    preg_replace('/\s+/', ' ', trim($original)),
                ];

                foreach ($originalVariants as $variant) {
                    if ($variant && strpos($normalizedHtml, $variant) !== false) {
                        // Determine the translated variant
                        $translatedVariant = $translation;
                        if ($variant !== $original) {
                            // Apply the same transformation to translation
                            if ($variant === trim($original)) {
                                $translatedVariant = trim($translation);
                            } elseif ($variant === str_replace("\n", ' ', $original)) {
                                $translatedVariant = str_replace("\n", ' ', $translation);
                            } elseif ($variant === str_replace("\n", '', $original)) {
                                $translatedVariant = str_replace("\n", '', $original);
                            } elseif ($variant === preg_replace('/\s+/', ' ', trim($original))) {
                                $translatedVariant = preg_replace('/\s+/', ' ', trim($translation));
                            }
                        }

                        $normalizedHtml = str_replace($variant, $translatedVariant, $normalizedHtml);
                        break;
                    }
                }
            }
        }

        // Now restore w-* classes and target attributes from original HTML
        return $this->restoreAttributes($originalHtml, $normalizedHtml);
    }

    /**
     * Restore w-* classes and target attributes from original HTML
     * by matching tags and merging attributes
     */
    private function restoreAttributes(string $originalHtml, string $translatedHtml): string
    {
        // Extract all tags from both versions
        preg_match_all('/<([a-z][a-z0-9]*)\s+([^>]+)>/i', $originalHtml, $originalMatches, PREG_OFFSET_CAPTURE);
        preg_match_all('/<([a-z][a-z0-9]*)\s+([^>]+)>/i', $translatedHtml, $translatedMatches, PREG_OFFSET_CAPTURE);

        // Build a map of tags by href for <a> tags, and multiple keys for other tags
        $originalTagsByHref = [];
        $originalTagMap = [];

        foreach ($originalMatches[0] as $index => $match) {
            $fullTag = $match[0];
            $tagName = $originalMatches[1][$index][0];
            $attrsString = $originalMatches[2][$index][0];

            // Parse attributes from original
            preg_match_all('/([a-z\-]+)="([^"]*)"|\s+/i', $attrsString, $attrMatches, PREG_SET_ORDER);
            $attrs = [];
            foreach ($attrMatches as $attr) {
                if (isset($attr[1]) && isset($attr[2])) {
                    $attrs[$attr[1]] = $attr[2];
                }
            }

            // For <a> tags, create href-only key for matching
            if ($tagName === 'a' && isset($attrs['href'])) {
                $hrefKey = 'a::href::' . $attrs['href'];
                $originalTagsByHref[$hrefKey] = $attrs;
            }

            // Also create a full key with classes for exact matching
            $keyAttrs = [];
            if (isset($attrs['href'])) {
                $keyAttrs['href'] = $attrs['href'];
            }
            if (isset($attrs['class'])) {
                // Remove w-* classes for key
                $classes = explode(' ', $attrs['class']);
                $nonWClasses = array_filter($classes, function ($c) {
                    return strpos($c, 'w-') !== 0;
                });
                if (!empty($nonWClasses)) {
                    $keyAttrs['class'] = implode(' ', $nonWClasses);
                }
            }

            // Create a key for this tag
            $key = $tagName . '::' . json_encode($keyAttrs);
            $originalTagMap[$key] = $attrs;
        }

        // Now process translated HTML and restore attributes
        $result = $translatedHtml;
        $offset = 0;

        foreach ($translatedMatches[0] as $index => $match) {
            $fullTag = $match[0];
            $position = $match[1] + $offset;
            $tagName = $translatedMatches[1][$index][0];
            $attrsString = $translatedMatches[2][$index][0];

            // Parse attributes from translated tag
            preg_match_all('/([a-z\-]+)="([^"]*)"|\s+/i', $attrsString, $attrMatches, PREG_SET_ORDER);
            $translatedAttrs = [];
            foreach ($attrMatches as $attr) {
                if (isset($attr[1]) && isset($attr[2])) {
                    $translatedAttrs[$attr[1]] = $attr[2];
                }
            }

            $originalAttrs = null;

            // Try exact match first
            $keyAttrs = [];
            if (isset($translatedAttrs['href'])) {
                $keyAttrs['href'] = $translatedAttrs['href'];
            }
            if (isset($translatedAttrs['class'])) {
                $classes = explode(' ', $translatedAttrs['class']);
                $nonWClasses = array_filter($classes, function ($c) {
                    return strpos($c, 'w-') !== 0;
                });
                if (!empty($nonWClasses)) {
                    $keyAttrs['class'] = implode(' ', $nonWClasses);
                }
            }
            $key = $tagName . '::' . json_encode($keyAttrs);

            if (isset($originalTagMap[$key])) {
                $originalAttrs = $originalTagMap[$key];
            } elseif ($tagName === 'a' && isset($translatedAttrs['href'])) {
                // For <a> tags, fall back to href-only matching
                $hrefKey = 'a::href::' . $translatedAttrs['href'];
                if (isset($originalTagsByHref[$hrefKey])) {
                    $originalAttrs = $originalTagsByHref[$hrefKey];
                }
            }

            // If we have original attributes for this tag, merge them
            if ($originalAttrs !== null) {
                // Merge: add w-* classes and all non-w-* classes from original if translated doesn't have class attr
                if (isset($originalAttrs['class'])) {
                    $origClasses = explode(' ', $originalAttrs['class']);
                    $wClasses = array_filter($origClasses, function ($c) {
                        return strpos($c, 'w-') === 0;
                    });
                    $nonWClasses = array_filter($origClasses, function ($c) {
                        return strpos($c, 'w-') !== 0;
                    });

                    if (isset($translatedAttrs['class'])) {
                        // Translated has class - just add w-* classes
                        if (!empty($wClasses)) {
                            $translatedAttrs['class'] = $translatedAttrs['class'] . ' ' . implode(' ', $wClasses);
                        }
                    } else {
                        // Translated doesn't have class - add all classes from original
                        $translatedAttrs['class'] = $originalAttrs['class'];
                    }
                }

                if (isset($originalAttrs['target'])) {
                    $translatedAttrs['target'] = $originalAttrs['target'];
                }

                // Do NOT restore hreflang attribute - it's language-specific and should be
                // provided by the translation, not copied from the original.
                // If the translation doesn't provide hreflang, it means it should be removed.

                // Rebuild tag with merged attributes
                $newAttrsString = '';
                foreach ($translatedAttrs as $name => $value) {
                    $newAttrsString .= ' ' . $name . '="' . $value . '"';
                }
                $newTag = '<' . $tagName . $newAttrsString . '>';

                // Replace in result
                $result = substr_replace($result, $newTag, $position, strlen($fullTag));
                $offset += strlen($newTag) - strlen($fullTag);
            }
        }

        return $result;
    }

    /**
     * Fix relative paths for files in subdirectories
     * When a file is in a subdirectory (e.g., /da/), relative paths need to go up one level
     */
    public function fixRelativePaths(string $html, string $publishedPath): string
    {
        // Remove leading/trailing slashes to get clean path
        $cleanPath = trim($publishedPath, '/');

        // Check if this is in a subdirectory (contains a slash or is not empty after removing index)
        $isInSubdirectory = !empty($cleanPath) && $cleanPath !== 'index' && strpos($cleanPath, '/') !== false;

        // If in subdirectory, count depth
        if ($isInSubdirectory) {
            $depth = substr_count($cleanPath, '/');
            $prefix = str_repeat('../', $depth);
        } else {
            // Single level subdirectory (e.g., da/index.html)
            $parts = explode('/', $cleanPath);
            if (count($parts) > 1 || (count($parts) === 1 && $parts[0] !== 'index' && !empty($parts[0]))) {
                $prefix = '../';
            } else {
                $prefix = './';
            }
        }

        // Fix asset paths in standard attributes
        $html = str_replace('src="./assets/', 'src="' . $prefix . 'assets/', $html);
        $html = str_replace('href="./assets/', 'href="' . $prefix . 'assets/', $html);

        // Fix asset paths in data attributes (e.g., data-poster-url, data-video-urls)
        $html = preg_replace_callback(
            '/data-[^=]+=("|\')\.\/assets\/([^"\']+)\1/',
            function ($matches) use ($prefix) {
                return str_replace('./assets/', $prefix . 'assets/', $matches[0]);
            },
            $html
        );

        // Fix asset paths in inline styles (e.g., background-image)
        $html = preg_replace_callback(
            '/style="[^"]*background-image:\s*url\(&quot;\.\/assets\/([^&]+)&quot;\)[^"]*"/',
            function ($matches) use ($prefix) {
                return str_replace('./assets/', $prefix . 'assets/', $matches[0]);
            },
            $html
        );

        // Also handle inline styles without &quot; encoding
        $html = preg_replace_callback(
            '/style="[^"]*background-image:\s*url\([\'"]?\.\/assets\/([^\)\'"\s]+)[\'"]?\)[^"]*"/',
            function ($matches) use ($prefix) {
                return str_replace('./assets/', $prefix . 'assets/', $matches[0]);
            },
            $html
        );

        // Fix relative links starting with ./
        $html = preg_replace_callback(
            '/href="(\.\/[^"#]*)"/',
            function ($matches) use ($prefix) {
                $link = $matches[1];
                // Skip if it's pointing to assets
                if (strpos($link, './assets/') === 0) {
                    return $matches[0];
                }
                // Convert ./page.html to ../page.html (or appropriate depth)
                return 'href="' . $prefix . substr($link, 2) . '"';
            },
            $html
        );

        // Fix absolute internal links (e.g., /da, /privacy-policy, /)
        // Convert them to relative paths with .html extension
        $html = preg_replace_callback(
            '/href="(\/[^"#:]*)"/',
            function ($matches) use ($prefix) {
                $link = $matches[1];

                // Clean up the link
                $cleanLink = trim($link, '/');

                // If empty, it's home - link to directory without index.html
                if (empty($cleanLink)) {
                    return 'href="' . $prefix . '"';
                }

                // Add .html extension if not present
                if (!preg_match('/\.(html|php)$/', $cleanLink)) {
                    $cleanLink .= '.html';
                }

                // Convert /da to ../da/ or ./da/ depending on depth (link to directory, not index.html)
                if (preg_match('/^([a-z]{2})(\.html)?$/', $cleanLink, $langMatch)) {
                    return 'href="' . $prefix . $langMatch[1] . '/"';
                }

                return 'href="' . $prefix . $cleanLink . '"';
            },
            $html
        );

        return $html;
    }

    /**
     * Append HTML right before the closing </body> tag
     */
    public function appendBeforeBody(string $html, string $content): string
    {
        if (empty($content)) {
            return $html;
        }

        // Find the closing </body> tag and insert content before it
        $position = strripos($html, '</body>');

        if ($position !== false) {
            $html = substr_replace($html, $content . "\n", $position, 0);
        }

        return $html;
    }

    /**
     * Update the lang attribute in the <html> tag
     */
    public function updateLangAttribute(string $html, string $lang): string
    {
        if (empty($lang)) {
            return $html;
        }

        // Replace lang attribute in <html> tag
        $html = preg_replace(
            '/(<html[^>]*\s)lang="[^"]*"/',
            '${1}lang="' . $lang . '"',
            $html
        );

        return $html;
    }

    /**
     * Add hreflang attributes to language switcher links
     * Detects language from href and adds appropriate hreflang
     */
    public function addHreflangAttributes(string $html): string
    {
        // Pattern to match links in footer-language section without hreflang
        // Look for <a> tags that don't already have hreflang attribute
        $html = preg_replace_callback(
            '/<a\s+([^>]*?)href="([^"]*)"([^>]*?)>([^<]*(?:Danish|English|Dansk)[^<]*)<\/a>/i',
            function ($matches) {
                $beforeHref = $matches[1];
                $href = $matches[2];
                $afterHref = $matches[3];
                $linkText = $matches[4];

                // Skip if already has hreflang
                if (stripos($beforeHref . $afterHref, 'hreflang') !== false) {
                    return $matches[0];
                }

                // Determine language from href or link text
                $hreflang = null;

                // If href points to /da or ends with /da/, it's Danish
                if (preg_match('#(^|/)da/?$#', $href)) {
                    $hreflang = 'da';
                }
                // If href points to root (/, ../, ./) and text mentions English, it's English
                elseif (preg_match('#^(\.\.?)?/?$#', $href) && stripos($linkText, 'English') !== false) {
                    $hreflang = 'en';
                }
                // If link text mentions Danish/Dansk, it's Danish
                elseif (stripos($linkText, 'Danish') !== false || stripos($linkText, 'Dansk') !== false) {
                    $hreflang = 'da';
                }

                // Add hreflang if determined
                if ($hreflang) {
                    return '<a ' . trim($beforeHref) . ' href="' . $href . '" hreflang="' . $hreflang . '"' . $afterHref . '>' . $linkText . '</a>';
                }

                return $matches[0];
            },
            $html
        );

        return $html;
    }

    /**
     * Add alternate hreflang link tags for SEO
     * Adds <link rel="alternate" hreflang="en" href="..."> to the head section
     *
     * @param string $html The HTML content
     * @param string $defaultUrl The fully qualified URL to the default (English) version
     * @return string Updated HTML with hreflang link tags
     */
    public function addAlternateHreflangLinks(string $html, string $defaultUrl): string
    {
        if (empty($defaultUrl)) {
            return $html;
        }

        // Create the hreflang link tag
        $hreflangLink = '<link rel="alternate" hreflang="en" href="' . htmlspecialchars($defaultUrl, ENT_QUOTES, 'UTF-8') . '">';

        // Insert after the last meta tag in the head section
        $html = preg_replace(
            '/(<meta[^>]*>)(?![\s\S]*<meta)/i',
            '$1' . "\n    " . $hreflangLink,
            $html,
            1
        );

        return $html;
    }

    /**
     * Add og:site_name meta tag if configured
     *
     * @param string $html The HTML content
     * @return string Updated HTML with og:site_name meta tag
     */
    public function addSiteNameMeta(string $html): string
    {
        if (empty($this->siteName)) {
            return $html;
        }

        // Check if og:site_name already exists
        if (preg_match('/<meta\s+[^>]*property="og:site_name"/i', $html)) {
            return $html;
        }

        // Try to insert after og:description first
        $inserted = preg_replace(
            '/(<meta\s+(?:property="og:description"|content="[^"]*"\s+property="og:description")[^>]*>)/i',
            '$1' . "\n" . '    <meta property="og:site_name" content="' . htmlspecialchars($this->siteName, ENT_QUOTES, 'UTF-8') . '">',
            $html,
            1,
            $count
        );

        if ($count > 0) {
            return $inserted;
        }

        // If no og:description, try after og:title
        $inserted = preg_replace(
            '/(<meta\s+(?:property="og:title"|content="[^"]*"\s+property="og:title")[^>]*>)/i',
            '$1' . "\n" . '    <meta property="og:site_name" content="' . htmlspecialchars($this->siteName, ENT_QUOTES, 'UTF-8') . '">',
            $html,
            1,
            $count
        );

        if ($count > 0) {
            return $inserted;
        }

        // If no og tags found, insert after the last meta tag
        $inserted = preg_replace(
            '/(<meta[^>]*>)(?![\s\S]*<meta)/i',
            '$1' . "\n" . '    <meta property="og:site_name" content="' . htmlspecialchars($this->siteName, ENT_QUOTES, 'UTF-8') . '">',
            $html,
            1
        );

        return $inserted;
    }

    /**
     * Apply meta tag translations from metadata
     *
     * @param string $html The HTML content
     * @param array $metadata Metadata array with 'seo' and 'openGraph' fields
     * @return string Updated HTML with translated meta tags
     */
    public function applyMetaTranslations(string $html, array $metadata): string
    {
        if (empty($metadata)) {
            return $html;
        }

        // Extract SEO data
        $seo = $metadata['seo'] ?? [];
        $openGraph = $metadata['openGraph'] ?? [];

        // If Open Graph fields are null but marked as copied, use SEO values
        if (isset($openGraph['titleCopied']) && $openGraph['titleCopied'] === true && empty($openGraph['title'])) {
            $openGraph['title'] = $seo['title'] ?? null;
        }
        if (isset($openGraph['descriptionCopied']) && $openGraph['descriptionCopied'] === true && empty($openGraph['description'])) {
            $openGraph['description'] = $seo['description'] ?? null;
        }

        // Translate <title> tag
        if (!empty($seo['title'])) {
            $html = preg_replace(
                '/<title>.*?<\/title>/s',
                '<title>' . htmlspecialchars($seo['title'], ENT_QUOTES, 'UTF-8') . '</title>',
                $html,
                1
            );
        }

        // Translate meta description - remove all existing first, then add the translated one
        if (!empty($seo['description'])) {
            // Remove all meta description tags (including content= and name= variants)
            $html = preg_replace(
                '/<meta\s+(?:name="description"\s+content="[^"]*"|content="[^"]*"\s+name="description")\s*\/?>/i',
                '',
                $html
            );

            // Add the translated meta description after <title>
            $html = preg_replace(
                '/(<title>.*?<\/title>)/s',
                '$1' . "\n" . '    <meta name="description" content="' . htmlspecialchars($seo['description'], ENT_QUOTES, 'UTF-8') . '">',
                $html,
                1
            );
        }

        // Translate Open Graph title - replace all occurrences
        if (!empty($openGraph['title'])) {
            // Remove all og:title tags
            $html = preg_replace(
                '/<meta\s+(?:property="og:title"\s+content="[^"]*"|content="[^"]*"\s+property="og:title")\s*\/?>/i',
                '',
                $html
            );

            // Add translated og:title after meta description
            $html = preg_replace(
                '/(<meta\s+name="description"[^>]*>)/i',
                '$1' . "\n" . '    <meta property="og:title" content="' . htmlspecialchars($openGraph['title'], ENT_QUOTES, 'UTF-8') . '">',
                $html,
                1
            );
        }

        // Translate Open Graph description - replace all occurrences
        if (!empty($openGraph['description'])) {
            // Remove all og:description tags
            $html = preg_replace(
                '/<meta\s+(?:property="og:description"\s+content="[^"]*"|content="[^"]*"\s+property="og:description")\s*\/?>/i',
                '',
                $html
            );

            // Add translated og:description after og:title
            $html = preg_replace(
                '/(<meta\s+property="og:title"[^>]*>)/i',
                '$1' . "\n" . '    <meta property="og:description" content="' . htmlspecialchars($openGraph['description'], ENT_QUOTES, 'UTF-8') . '">',
                $html,
                1
            );
        }

        // Add og:site_name using the dedicated method
        $html = $this->addSiteNameMeta($html);

        // Also update Twitter card tags if Open Graph is available
        if (!empty($openGraph['title'])) {
            $html = preg_replace(
                '/<meta\s+(?:property="twitter:title"\s+content="[^"]*"|content="[^"]*"\s+property="twitter:title")\s*\/?>/i',
                '<meta property="twitter:title" content="' . htmlspecialchars($openGraph['title'], ENT_QUOTES, 'UTF-8') . '">',
                $html
            );
        }

        if (!empty($openGraph['description'])) {
            $html = preg_replace(
                '/<meta\s+(?:property="twitter:description"\s+content="[^"]*"|content="[^"]*"\s+property="twitter:description")\s*\/?>/i',
                '<meta property="twitter:description" content="' . htmlspecialchars($openGraph['description'], ENT_QUOTES, 'UTF-8') . '">',
                $html
            );
        }

        return $html;
    }
}
