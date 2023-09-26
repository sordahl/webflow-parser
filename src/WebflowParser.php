<?php

namespace Sordahl\WebflowParser;

class WebflowParser
{
    public static string $linkExtension = '.html';
		public static bool $configRemoveLinebreak = false;
    protected static string $url;
    protected static string $site;
    protected static string $host;
    protected static array $contextOptions = ["ssl" => ["verify_peer" => false, "verify_peer_name" => false]];
    private static int $maxDepth = 5;
    private static array $links = [];
    private static array $linksCrawled = [];

    public function __construct(string $site, string $host, $filename = 'index', $extension = '.html')
    {
        self::$linkExtension = $extension;
        self::$site = str_contains($site, '/') ? explode('/', $site)[0] : $site;
        self::$host = $host ?? $site;
        self::$url = 'https://' . $site;
        print '-> Init scraping: ' . self::$url . PHP_EOL;
        self::checkFolder('dist');
        self::checkFolder('dist/assets');

        $htmlRaw = self::getHtmlContent(self::$url);
        $htmlRaw = self::cleanup_html($htmlRaw);
        $htmlRaw = self::downloadExternalAssets($htmlRaw);
        self::getLinks($htmlRaw);
        if (self::$configRemoveLinebreak) $htmlRaw = str_replace(array("    " . PHP_EOL, PHP_EOL), "", $htmlRaw);

        file_put_contents('dist/' . $filename . self::$linkExtension, $htmlRaw);

        self::recursiveParsePages();

        print PHP_EOL;
        print 'Completed; ' . (sizeof(self::$linksCrawled) + 1) . ' pages crawled' . PHP_EOL;
    }

    private static function checkFolder($folder): void
    {
        $path = realpath($folder);
        print '<- Check folder: ' . $path . '... ';
        if ($path === false || !is_dir($path))
            mkdir($folder);

        print (($path === false || !is_dir($path)) ? 'ERROR' : 'OK') . PHP_EOL;
    }

    public static function getHtmlContent($url): bool|string
    {
        return file_get_contents($url, false, stream_context_create(self::$contextOptions));
    }

    public static function cleanup_html($htmlRaw): array|string|null
    {
        $patterns = array(
            '/<!--(.|\s)*?-->/', //Remove comments
            '/(<meta content="Webflow" name="generator"\/>)/',
            '/\?site=([a-zA-Z0-9]+)/', //Remove ?site= param from script src
            //'/<script type(.|\s)*?<\/script>/', //Remove javascript
            //'/<style>(.)*\<\/style>/', //Remove styles
            //'/ data\-wf\-(domain|page|site|status|ignore)="(.)*?"/', //Remove webflow data tags
            //'/<script src=\"https:\/\/uploads-ssl.webflow.com\/(.)*(js\/webflow)(.)*\"><\/script>/', //Remove webflow javascript library.

        );
        foreach ($patterns as $pattern)
            $htmlRaw = preg_replace($pattern, '', $htmlRaw);

        //Remove badge
        $htmlRaw = str_replace('data-wf-domain="' . self::$site . '"', 'data-wf-domain="' . self::$host . '"', $htmlRaw);
        //$htmlRaw = preg_replace('/<a class=\"w-webflow-badge\"(.*)<\/a>/', '', $htmlRaw);

        return $htmlRaw;
    }

    public static function downloadExternalAssets($htmlRaw)
    {
        $files = [];
        $pattern = '(https:\/\/(?:uploads-ssl.webflow.com)\/(?:.*?))(?:\"| )';
        //$pattern = '(https:\/\/(?:.*?))(?:\"| )';
        //$pattern = '(https:\/\/(?:.*?)\/(?:.*?)\.(?:.*?))(?:\"| )';
        preg_match_all('/' . $pattern . '/', $htmlRaw, $fileList, PREG_PATTERN_ORDER);

        //Clean-up fileList in to $files array.
        foreach ($fileList[1] as $file)
            if (!str_contains($file, ','))
                $files[] = str_replace(['"', '&quot;)'], '', $file);

        if (empty($files))
            return false;

        foreach ($files as $file) {
            $filename = explode('/', $file);
            $filename = str_replace(array(' ', '%20', '%40', '(', ')', 'webflow', 'sordahl'), '', end($filename));
            $filename = trim($filename, '. ');

            if (!file_exists('dist/assets/' . $filename)) {
                print '<- Downloading: ' . $file . ' -> dist/assets/' . $filename . PHP_EOL;

                $fileContent = file_get_contents($file, false, stream_context_create(self::$contextOptions));

                if (in_array('Content-Encoding: gzip', $http_response_header))
                    $fileContent = gzdecode($fileContent);

                if (pathinfo($filename, PATHINFO_EXTENSION) === 'css') {
                    print '<- Parser stylesheet ' . $filename . ' for external resources' . PHP_EOL;
                    self::downloadExternalAssets($fileContent);
                }

                file_put_contents('dist/assets/' . $filename, $fileContent);
                print ' OK' . PHP_EOL;
            }

            $htmlRaw = str_replace($file, './assets/' . $filename, $htmlRaw);
        }

        return $htmlRaw;
    }

    public static function getLinks(&$htmlRaw): void
    {
        $pattern = '(?:<a(?:.*?)href=\"\/)((?:[a-zA-Z])(?:.*?))(?:\"| )';
        preg_match_all('/' . $pattern . '/', $htmlRaw, $linkList, PREG_PATTERN_ORDER);

        foreach (array_unique(array_filter($linkList[1])) as $link) {
            if (!in_array($link, self::$links))
                self::$links[] = $link;

            $link = trim($link, '/');
            //Rename links to local dist folder
            //print '<- rename link: /' . $link . ' ->' . self::renameLink($link) . PHP_EOL;
            $htmlRaw = str_replace('/' . $link . '"', self::renameLink($link) . '"', $htmlRaw);
        }

        $htmlRaw = str_replace('href="/"', 'href="./index' . self::$linkExtension . '"', $htmlRaw);
    }

    public static function renameLink($link): string
    {
        return str_replace('/', '_', $link) . self::$linkExtension;
    }

    public static function recursiveParsePages($depth = 0): void
    {
        if ($depth >= self::$maxDepth) {
            print '<- max depth reached' . PHP_EOL;
            exit;
        }
        foreach (self::$links as $page) {
            if (!in_array($page, self::$linksCrawled)) {
                print '-> Scraping: ' . $page . PHP_EOL;
                $newPage = self::$url . '/' . $page;
                $htmlRaw = self::getHtmlContent($newPage);
                $htmlRaw = self::cleanup_html($htmlRaw);
                $htmlRaw = self::downloadExternalAssets($htmlRaw);
                $filename = self::renameLink($page);
                self::getLinks($htmlRaw);
                print '<- Saving file: ' . $filename . PHP_EOL;
                file_put_contents('dist/' . $filename, $htmlRaw);
                self::$linksCrawled[] = $page;

                print '<- Scraping done (' . $page . ')' . PHP_EOL;
            }
        }

        foreach (self::$links as $link)
            if (!in_array($link, self::$linksCrawled)) {
                self::recursiveParsePages($depth++);
                break;
            }
    }

}
