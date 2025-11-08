<?php

namespace Sordahl\WebflowParser;

/**
 * Handles all interactions with the Webflow API
 */
class WebflowApiClient
{
    private string $apiKey;
    private string $baseUrl = 'https://api.webflow.com/v2/';

    public function __construct(string $apiKey)
    {
        $this->apiKey = $apiKey;
    }

    /**
     * Make a request to the Webflow API
     */
    private function request(string $endpoint): array
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
            'Content-Type: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        print 'Requesting ' . $url . ' - HTTP ' . $httpCode . PHP_EOL;
        print 'Response: ' . $response . PHP_EOL;

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \Exception('cURL Error: ' . $error);
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            throw new \Exception('API request failed with status code: ' . $httpCode . ' - Response: ' . $response);
        }

        return json_decode($response, true);
    }

    /**
     * Fetch all sites
     */
    public function getSites(): array
    {
        $data = $this->request('/sites');
        return $data['sites'] ?? [];
    }

    /**
     * Fetch all pages for a site with optional locale filter
     */
    public function getPages(string $siteId, ?string $localeId = null): array
    {
        $allPages = [];
        $offset = 0;
        $limit = 100;

        do {
            $endpoint = "/sites/$siteId/pages?offset=$offset&limit=$limit";
            if ($localeId) {
                $endpoint .= "&locale=$localeId";
            }

            $data = $this->request($endpoint);
            $pages = $data['pages'] ?? [];
            $allPages = array_merge($allPages, $pages);

            $offset += $limit;
            $hasMore = count($pages) === $limit;
        } while ($hasMore);

        return $allPages;
    }

    /**
     * Fetch page content (DOM structure) for a specific page
     */
    public function getPageContent(string $pageId, ?string $localeId = null): ?array
    {
        $endpoint = "/pages/$pageId/dom";
        if ($localeId) {
            $endpoint .= "?locale=$localeId";
        }

        try {
            return $this->request($endpoint);
        } catch (\Exception $e) {
            echo "  Warning: Could not fetch content for page $pageId: " . $e->getMessage() . PHP_EOL;
            return null;
        }
    }

    /**
     * Fetch page metadata including SEO and Open Graph data
     */
    public function getPageMetadata(string $pageId, ?string $localeId = null): ?array
    {
        $endpoint = "/pages/$pageId";
        if ($localeId) {
            $endpoint .= "?localeId=$localeId";
        }

        try {
            return $this->request($endpoint);
        } catch (\Exception $e) {
            echo "  Warning: Could not fetch metadata for page $pageId: " . $e->getMessage() . PHP_EOL;
            return null;
        }
    }

    /**
     * Fetch rendered HTML for a specific page from published site
     */
    public function getPublishedHtml(string $baseUrl, string $path = ''): ?string
    {
        $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36'
        ]);

        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            echo "  Warning: Could not fetch HTML: $error" . PHP_EOL;
            return null;
        }

        curl_close($ch);

        if ($httpCode !== 200) {
            echo "  Warning: HTTP status code $httpCode when fetching HTML from $url" . PHP_EOL;
            return null;
        }

        return $html;
    }
}
