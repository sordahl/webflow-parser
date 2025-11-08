<?php

namespace HiFriday\WebflowLocale;

/**
 * Builds translation maps by comparing default and locale JSON structures
 */
class TranslationBuilder
{
    /**
     * Extract text content from JSON nodes (non-recursive, memory efficient)
     */
    private function extractTextFromNodes(array $nodes, ?string $nodeId = null): array
    {
        $textMap = [];

        if (!is_array($nodes)) {
            return $textMap;
        }

        // Build a lookup map for faster access
        $nodeMap = [];
        foreach ($nodes as $node) {
            // Support both 'id' and '_id' field names
            $id = $node['id'] ?? $node['_id'] ?? null;
            if ($id) {
                $nodeMap[$id] = $node;
            }
        }

        // If no specific nodeId, process all nodes
        if ($nodeId === null) {
            foreach ($nodeMap as $id => $node) {
                // Extract text from node's text.text property
                $text = null;
                if (isset($node['text']['text']) && !empty($node['text']['text'])) {
                    $text = $node['text']['text'];
                } elseif (isset($node['text']) && is_string($node['text']) && !empty($node['text'])) {
                    $text = $node['text'];
                }

                if ($text) {
                    $textMap[$id] = $text;
                }

                // Also extract HTML content if available (for link text, etc.)
                if (isset($node['text']['html']) && !empty($node['text']['html'])) {
                    $textMap[$id . '_html'] = $node['text']['html'];
                }
            }
            return $textMap;
        }

        // Process specific node and its children iteratively
        $queue = [$nodeId];
        $processed = [];

        while (!empty($queue)) {
            $currentId = array_shift($queue);

            if (isset($processed[$currentId])) {
                continue;
            }

            $processed[$currentId] = true;

            if (!isset($nodeMap[$currentId])) {
                continue;
            }

            $node = $nodeMap[$currentId];

            // Extract text from node's text.text property
            $text = null;
            if (isset($node['text']['text']) && !empty($node['text']['text'])) {
                $text = $node['text']['text'];
            } elseif (isset($node['text']) && is_string($node['text']) && !empty($node['text'])) {
                $text = $node['text'];
            }

            if ($text) {
                $textMap[$currentId] = $text;
            }

            // Also extract HTML content if available (for link text, etc.)
            if (isset($node['text']['html']) && !empty($node['text']['html'])) {
                $textMap[$currentId . '_html'] = $node['text']['html'];
            }

            // Add children to queue
            if (isset($node['nodes']) && is_array($node['nodes'])) {
                foreach ($node['nodes'] as $childId) {
                    if (!isset($processed[$childId])) {
                        $queue[] = $childId;
                    }
                }
            }
        }

        return $textMap;
    }

    /**
     * Compare default and locale JSON to build translation map
     */
    public function buildTranslationMap(array $defaultJson, array $localeJson): array
    {
        if (empty($defaultJson) || empty($localeJson)) {
            return [];
        }

        $defaultNodes = $defaultJson['nodes'] ?? [];
        $localeNodes = $localeJson['nodes'] ?? [];

        if (empty($defaultNodes) || empty($localeNodes)) {
            return [];
        }

        // Extract text from both node structures
        $defaultTexts = $this->extractTextFromNodes($defaultNodes);
        $localeTexts = $this->extractTextFromNodes($localeNodes);

        // Build translation map - prioritize HTML/link entries over plain text
        $translationMap = [];

        // FIRST: Extract and map full HTML chunks and link text (higher priority)
        foreach ($defaultTexts as $nodeId => $defaultText) {
            // Check if this is an HTML entry
            if (strpos($nodeId, '_html') !== false) {
                continue; // Skip HTML entries in this pass
            }

            $htmlKey = $nodeId . '_html';
            if (isset($defaultTexts[$htmlKey]) && isset($localeTexts[$htmlKey])) {
                $defaultHtml = $defaultTexts[$htmlKey];
                $localeHtml = $localeTexts[$htmlKey];

                if ($defaultHtml !== $localeHtml) {
                    // First, add the full HTML chunk as a translation (this captures surrounding text with links)
                    $translationMap[$defaultHtml] = $localeHtml;

                    // Also extract and map individual link elements
                    $defaultLinks = $this->extractLinksFromHtml($defaultHtml);
                    $localeLinks = $this->extractLinksFromHtml($localeHtml);

                    // Map complete link tags (for href attribute changes)
                    foreach ($defaultLinks as $index => $defaultLink) {
                        if (isset($localeLinks[$index])) {
                            // Map normalized <a> tag (without data attributes) for better matching
                            if ($defaultLink['normalized'] !== $localeLinks[$index]['normalized']) {
                                $translationMap[$defaultLink['normalized']] = $localeLinks[$index]['normalized'];
                            }

                            // DON'T add plain link text - it causes conflicts when same text has different translations
                            // The full HTML tag provides the context needed
                        }
                    }
                }
            }
        }

        // SECOND: Add plain text translations (lower priority - won't overwrite HTML context)
        // But skip if there's already an HTML entry with different translation (to avoid ambiguity)
        foreach ($defaultTexts as $nodeId => $defaultText) {
            if (isset($localeTexts[$nodeId]) && $localeTexts[$nodeId] !== $defaultText) {
                // Only add if the text is different
                // Don't add if the same text already maps to a different translation (context-dependent / ambiguous)
                if (!isset($translationMap[$defaultText])) {
                    $translationMap[$defaultText] = $localeTexts[$nodeId];
                }
            }
        }

        return $translationMap;
    }

    /**
     * Extract link information from HTML
     */
    private function extractLinksFromHtml(string $html): array
    {
        $links = [];

        // Match <a> tags with href and text content
        if (preg_match_all('/<a\s+(?:[^>]*?\s+)?href="([^"]*)"[^>]*>(.*?)<\/a>/i', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $links[] = [
                    'href' => $match[1],
                    'text' => strip_tags($match[2]),
                    'full' => $match[0],
                    // Create a normalized version without data attributes for matching
                    'normalized' => '<a href="' . $match[1] . '">' . $match[2] . '</a>'
                ];
            }
        }

        return $links;
    }
}
