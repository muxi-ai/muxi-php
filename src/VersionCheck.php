<?php

declare(strict_types=1);

namespace Muxi;

class VersionCheck
{
    private const SDK_NAME = 'php';
    private const TWELVE_HOURS = 12 * 60 * 60;

    private static bool $checked = false;

    public static function checkForUpdates(array $headers): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;

        if (!self::isDevMode()) {
            return;
        }

        $latest = $headers['x-muxi-sdk-latest'] ?? null;
        if (!$latest) {
            return;
        }

        if (!self::isNewerVersion($latest, Version::VERSION)) {
            return;
        }

        self::updateLatestVersion($latest);

        if (!self::notifiedRecently()) {
            error_log("[muxi] SDK update available: {$latest} (current: " . Version::VERSION . ")");
            error_log("[muxi] Run: composer update muxi/muxi-php");
            self::markNotified();
        }
    }

    private static function isDevMode(): bool
    {
        return getenv('MUXI_DEBUG') === '1';
    }

    private static function getCachePath(): ?string
    {
        $home = getenv('HOME') ?: ($_SERVER['HOME'] ?? null);
        if (!$home) {
            return null;
        }
        return $home . '/.muxi/sdk-versions.json';
    }

    private static function loadCache(): array
    {
        $path = self::getCachePath();
        if (!$path || !file_exists($path)) {
            return [];
        }

        try {
            $content = file_get_contents($path);
            return json_decode($content, true) ?? [];
        } catch (\Exception $e) {
            return [];
        }
    }

    private static function saveCache(array $cache): void
    {
        $path = self::getCachePath();
        if (!$path) {
            return;
        }

        try {
            $dir = dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($path, json_encode($cache, JSON_PRETTY_PRINT));
        } catch (\Exception $e) {
            // Ignore cache errors
        }
    }

    private static function isNewerVersion(string $latest, string $current): bool
    {
        return version_compare($latest, $current, '>');
    }

    private static function notifiedRecently(): bool
    {
        $cache = self::loadCache();
        $entry = $cache[self::SDK_NAME] ?? null;
        if (!$entry || !isset($entry['last_notified'])) {
            return false;
        }

        try {
            $lastNotified = strtotime($entry['last_notified']);
            return (time() - $lastNotified) < self::TWELVE_HOURS;
        } catch (\Exception $e) {
            return false;
        }
    }

    private static function updateLatestVersion(string $latest): void
    {
        $cache = self::loadCache();
        $entry = $cache[self::SDK_NAME] ?? [];
        $cache[self::SDK_NAME] = array_merge($entry, [
            'current' => Version::VERSION,
            'latest' => $latest,
        ]);
        self::saveCache($cache);
    }

    private static function markNotified(): void
    {
        $cache = self::loadCache();
        if (isset($cache[self::SDK_NAME])) {
            $cache[self::SDK_NAME]['last_notified'] = date('c');
            self::saveCache($cache);
        }
    }
}
