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
	public static $filename = 'index';
	public static $extension = '.html';
	public static $dist = 'dist';
	public static $appendHTML = '';
	public static $removeFilenameCharacters = [
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

	public static function getAbsolutePath()
	{
		$scriptName = $_SERVER['SCRIPT_FILENAME'];
		if (is_link($scriptName))
			$scriptName = readlink($scriptName);

		return dirname(realpath($scriptName));
	}

	public function __construct(string $site, string $host)
	{
		//Set absolute dist path
		self::$dist = self::getAbsolutePath() . DIRECTORY_SEPARATOR . self::$dist;
		print '<- absolute path: ' . self::$dist . PHP_EOL;

		self::$linkExtension = self::$extension;
		self::$site = str_contains($site, '/') ? explode('/', $site)[0] : $site;
		self::$host = $host ?? $site;
		self::$url = 'https://' . $site;
		print '-> Init scraping: ' . self::$url . PHP_EOL;
		self::checkFolder(self::$dist);
		self::checkFolder(self::$dist . DIRECTORY_SEPARATOR . 'assets');

		$htmlRaw = self::getHtmlContent(self::$url);
		$htmlRaw = self::cleanup_html($htmlRaw);
		print '<- html size: ' . strlen($htmlRaw) . PHP_EOL;
		$htmlRaw = self::downloadExternalAssets($htmlRaw);

		self::getLinks($htmlRaw);
		if (self::$configRemoveLinebreak) $htmlRaw = str_replace(array("    " . PHP_EOL, PHP_EOL), "", $htmlRaw);

		file_put_contents(self::$dist . DIRECTORY_SEPARATOR . self::$filename . self::$linkExtension, $htmlRaw);

		self::recursiveParsePages();

		print PHP_EOL;
		print 'Completed; ' . (sizeof(self::$linksCrawled) + 1) . ' pages crawled' . PHP_EOL;
	}

	private static function checkFolder($folder): void
	{
		$path = $folder;
		print '<- Check folder: ' . $folder . ' -> ' . $path . '... ';
		if ($path === false || !is_dir($path)) {
			if (!is_dir($path)) print 'not path: ' . $path . PHP_EOL;
			mkdir($folder);
			$path = $folder;
		}

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
			'/\s(?:integrity|crossorigin)="[^"]*"/' //remove integrity and crossorigin from script
		);
		foreach ($patterns as $pattern)
			$htmlRaw = preg_replace($pattern, '', $htmlRaw);

		//Remove badge
		$htmlRaw = str_replace('data-wf-domain="' . self::$site . '"', 'data-wf-domain="' . self::$host . '"', $htmlRaw);
		if (self::$appendHTML) $htmlRaw = str_replace('</body>', self::$appendHTML . '</body>', $htmlRaw);

		return $htmlRaw;
	}

	public static function downloadExternalAssets($htmlRaw)
	{
		$files = [];
		$pattern = 'https:\/\/[^"]*\/[^"]*\.[^"]+'; //must contain / after domain and punctuation (ext)
		preg_match_all('/' . $pattern . '/', $htmlRaw, $fileList, PREG_PATTERN_ORDER);

		//Clean-up fileList in to $files array.
		foreach ($fileList[0] as $file) {
			$file = str_replace(['"', '&quot;)'], '', $file);
			$files = array_merge($files, explode(' ', $file));
		}

		//Remove non-valid urls
		$files = array_filter($files, function ($url) {
			return filter_var($url, FILTER_VALIDATE_URL);
		});

		print '<- external files: ' . sizeof($files) . PHP_EOL;

		if (empty($files))
			return $htmlRaw;

		foreach ($files as $file) {
			$filename = explode('/', $file);
			$filename = str_replace(self::$removeFilenameCharacters, '', end($filename));
			$filename = trim($filename, '. ');

			if (!file_exists(self::$dist . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $filename)) {
				print '<- Downloading: ' . $file . ' -> ' . self::$dist . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $filename . PHP_EOL;

				$fileContent = file_get_contents($file, false, stream_context_create(self::$contextOptions));

				if (in_array('Content-Encoding: gzip', $http_response_header))
					$fileContent = gzdecode($fileContent);

				if (pathinfo($filename, PATHINFO_EXTENSION) === 'css') {
					print '<- Parser stylesheet ' . $filename . ' for external resources' . PHP_EOL;
					self::downloadExternalAssets($fileContent);
				}

				file_put_contents(self::$dist . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . $filename, $fileContent);
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
				file_put_contents(self::$dist . DIRECTORY_SEPARATOR . $filename, $htmlRaw);
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
