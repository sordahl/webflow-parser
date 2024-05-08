# webflow-parser

Parse any Webflow site

## Installation

Copy this to `composer.json`

```
{
  "repositories": [
    {
      "type": "package",
      "package": {
        "name": "sordahl/webflow-parser",
        "version": "1.0.0",
        "source": {
          "url": "https://github.com/sordahl/webflow-parser.git",
          "type": "git",
          "reference": "master"
        },
        "autoload": {
          "classmap": [
            "src/"
          ]
        }
      }
    }
  ],
  "require": {
    "sordahl/webflow-parser": "*"
  },
  "config": {
    "optimize-autoloader": true
  }
}
```

copy the `tests/parse.php` file to your root and change site and host.
add symlink in dist folder to parser file to enable webhook auto-update on webflow publish
run `composer install` (add `--no-cache` to disable cache)
