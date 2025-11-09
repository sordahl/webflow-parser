# Webflow Website Generator

A comprehensive PHP-based static site generator for Webflow sites with multi-language support, automatic sitemap generation, and SEO optimization.

## Features

- 🌍 **Multi-language support** - Automatically generates locale-specific pages
- 🗺️ **Auto-detecting sitemap** - Automatically detects supported languages and creates SEO-optimized sitemap.xml
- 🤖 **Robots.txt generation** - Automatically generated with sitemap reference
- 🏷️ **Open Graph tags** - Includes og:site_name and other meta tags for social sharing
- 🔄 **Translation system** - Compares JSON DOM structures to generate accurate translations
- 📦 **Asset management** - Downloads and manages external assets locally
- 🔗 **SEO optimized** - Proper hreflang tags, alternate links, and meta translations
- ⚡ **Unified generator** - Single command to generate complete site
- 🗜️ **Minification** - Compresses inline CSS and JavaScript for faster page loads

## Quick Start

### Prerequisites

- PHP 8.3 or higher
- Composer
- Webflow API key

### Installation

1. Install dependencies:

```bash
composer install
```

2. Create `.env` file with your Webflow API key:

```bash
echo 'WEBFLOW_API="your-api-key-here"' . PHP_EOL > .env
```

### Generate Your Site

Simply run the unified generator script:

```bash
php generate-site.php
```

This single command will:

1. ✅ Fetch and generate all locale-specific HTML pages
2. ✅ Generate sitemap.xml with auto-detected languages
3. ✅ Generate robots.txt
4. ✅ Copy public files to dist directory

## Architecture

### Core Classes

- **`WebflowLocaleFetcher`** - Main orchestrator for fetching and generating locale-specific pages
- **`SitemapGenerator`** - Generates SEO-optimized sitemap.xml and robots.txt with auto-detected locales
- **`HtmlProcessor`** - Processes and transforms HTML (cleanup, assets, translations, meta tags)
- **`TranslationBuilder`** - Builds translation maps by comparing JSON DOM structures
- **`LocaleGenerator`** - Generates and saves locale-specific HTML files
- **`WebflowApiClient`** - Handles all Webflow API interactions

### Directory Structure

```
/
├── generate-site.php            # ⭐ Main unified generator (use this!)
├── generate-locale-pages.php   # Legacy: locale pages only
├── generate-sitemap.php         # Legacy: sitemap only
├── composer.json
├── .env
└── src/
    ├── WebflowLocaleFetcher.php  # Main orchestrator
    ├── SitemapGenerator.php       # Sitemap & robots.txt generator
    ├── HtmlProcessor.php          # HTML processing & meta tags
    ├── TranslationBuilder.php     # Translation logic
    ├── LocaleGenerator.php        # File generation
    └── WebflowApiClient.php       # API communication

dist/                           # Generated output
├── index.html                  # Primary language (English)
├── da/                        # Danish locale folder
│   └── index.html             # Translated Danish page
├── sitemap.xml                # SEO sitemap with hreflang
├── robots.txt                 # Search engine instructions
├── json/                      # JSON DOM files
│   ├── page-content-*-default.json
│   └── page-content-*-da.json
└── assets/                    # Downloaded assets
    ├── *.css
    ├── *.js
    └── *.svg
```

## Configuration

Edit the `$config` array in `generate-site.php`:

```php
$config = [
    'apiKey' => $apiKey,                              // From .env file
    'siteId' => '<webflow-site-id>',                  // Your Webflow site ID
    'siteUrl' => 'https://example.webflow.io',        // Webflow staging URL
    'hostUrl' => 'https://example.com',               // Your production domain
    'outputDir' => __DIR__ . '/dist',                 // Output directory
    'site_name' => 'SiteName',                         // For og:site_name meta tag

    // Options
    'fetchContent' => true,      // Fetch JSON DOM structure
    'fetchHtml' => true,         // Fetch rendered HTML
    'downloadAssets' => true,    // Download external assets
    'minifyInline' => true,      // Minify inline styles and scripts

    // Pages to exclude by ID
    'excludePageIds' => [],
];
```

## How It Works

### Translation Process

1. **Fetch JSON DOM**: Downloads JSON representation of page structure from Webflow API
2. **Compare Structures**: Compares primary locale JSON with secondary locale JSON
3. **Build Translation Map**: Creates mapping of original text to translated text
4. **Apply Translations**: Replaces original text in HTML with translations
5. **Update Meta Tags**: Translates SEO titles, descriptions, and Open Graph tags (including og:site_name)
6. **Fix Paths**: Updates relative paths for subdirectory locations
7. **Add Hreflang**: Adds proper language alternate links

### Sitemap Generation

1. **Auto-detect Locales**: Scans pages to identify all supported languages automatically
2. **Group Pages**: Groups pages by slug to identify translations
3. **Build Entries**: Creates sitemap entries with proper hreflang alternate links
4. **Prioritize**: Sets priority based on page type (homepage = 1.0, others = 0.8)
5. **Generate XML**: Outputs valid sitemap.xml following sitemaps.org schema

## Key Features Explained

### Auto-Detecting Sitemap

The `SitemapGenerator` class automatically detects all supported languages by scanning the pages fetched from Webflow. You don't need to manually configure which languages are supported - just add them in Webflow and the generator will detect and include them in the sitemap.

```php
// This method auto-detects locales from pages
private function detectLocales(array $pages): array
```

### Open Graph Site Name

The generator automatically adds `og:site_name` meta tags to all pages (both primary and translated). Configure it in `generate-site.php`:

```php
'site_name' => 'SiteName',  // Appears as <meta property="og:site_name" content="SiteName">
```

### Hreflang Attributes

All pages automatically get proper hreflang alternate links for SEO:

```html
<!-- Primary page links to Danish -->
<link rel="alternate" hreflang="da" href="https://example.com/da/" />

<!-- Danish page links back to primary -->
<link rel="alternate" hreflang="en" href="https://exampple.come/" />
```

### Inline Minification

The generator automatically minifies inline `<style>` and `<script>` tags to reduce page size and improve load times:

**Before minification:**

```html
<style type="text/css">
	.section-features > .feature-item:nth-child(n + 3) {
		margin-top: -20vh;
	}
	.section-features {
		overflow-x: clip;
	}
</style>
```

**After minification:**

```html
<style type="text/css">
	.section-features > .feature-item:nth-child(n + 3) {
		margin-top: -20vh;
	}
	.section-features {
		overflow-x: clip;
	}
</style>
```

**Features:**

- Removes CSS/JS comments
- Removes unnecessary whitespace
- Removes spaces around operators and punctuation
- Preserves functionality while reducing file size
- Can be disabled by setting `'minifyInline' => false` in config

## Development

### Adding a New Locale

Locales are automatically detected from Webflow! Just add a new locale in your Webflow site settings, and the generator will detect and process it automatically.

### Excluding Pages

Add page IDs to the `excludePageIds` array in `generate-site.php`:

```php
'excludePageIds' => [
    '671d6147172df4affd2ed334',  // Page to exclude
],
```

### Custom Meta Tags

To add custom meta tags, modify the `HtmlProcessor::addSiteNameMeta()` method or create a similar method in `src/HtmlProcessor.php`.

## Legacy Scripts

The following scripts are still available but **not recommended** for regular use:

- `generate-locale-pages.php` - Generate only locale pages (use `generate-site.php` instead)
- `generate-sitemap.php` - Generate only sitemap (use `generate-site.php` instead)

## Troubleshooting

### No locales detected

- Check that locales are properly configured in Webflow
- Ensure the Webflow API key has access to the site

### Missing translations

- Verify that content exists in both primary and secondary locales in Webflow
- Check the `dist/json/` directory for generated JSON files
- Review translation pairs count in console output

### Broken asset links

- Ensure `downloadAssets` is set to `true` in config
- Check that the `dist/assets/` directory has write permissions
- Verify external assets are accessible

### Sitemap not updating

- Make sure you're running `generate-site.php` (not the legacy scripts)
- Check that all locales are published in Webflow
- Verify the `hostUrl` is correct in config

## Performance

- **Average generation time**: 4-6 seconds (for 2 pages with 2 locales)
- **API calls**: Approximately 4-6 per page (varies by locale count)
- **Output size**: ~20KB per page (compressed HTML + assets)

## Inspired By

This project uses concepts from [WebflowParser](https://github.com/sordahl/webflow-parser) for asset management and HTML cleanup.

## License

Proprietary - Sordahl ApS
