<?php

namespace Sordahl\WebflowParser;

use Exception;

/**
 * Simple environment variable loader
 * Loads variables from a .env file into the environment
 */
class EnvLoader
{
    /**
     * Load environment variables from a .env file
     *
     * @param string $path Path to the .env file
     * @throws Exception If the .env file is not found
     */
    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            throw new Exception('.env file not found at: ' . $path);
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }

            // Skip lines without '='
            if (strpos($line, '=') === false) {
                continue;
            }

            list($name, $value) = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, '"\'');

            // Only set if not already set
            if (!array_key_exists($name, $_ENV)) {
                putenv(sprintf('%s=%s', $name, $value));
                $_ENV[$name] = $value;
            }
        }
    }

    /**
     * Get an environment variable value
     *
     * @param string $key The environment variable key
     * @param string|null $default Default value if not found
     * @return string|null
     */
    public static function get(string $key, ?string $default = null): ?string
    {
        $value = getenv($key);
        return $value !== false ? $value : $default;
    }

    /**
     * Get a required environment variable or throw an exception
     *
     * @param string $key The environment variable key
     * @throws Exception If the variable is not found
     * @return string
     */
    public static function getRequired(string $key): string
    {
        $value = self::get($key);
        if ($value === null) {
            throw new Exception("Required environment variable '{$key}' not found");
        }
        return $value;
    }
}
