<?php

declare(strict_types=1);

namespace WpEnvironment\Support;

use WpEnvironment\Core\SetupException;

/**
 * Cross-platform path helpers.
 *
 * All project-relative files are resolved from the project root (derived from
 * this class' own location), never from the caller's working directory.
 */
final class Filesystem
{
    /**
     * Absolute path of the project root (the directory containing setup.php).
     */
    public static function projectRoot(): string
    {
        // src/Support -> src -> project root.
        return dirname(dirname(__DIR__));
    }

    /**
     * Whether a path is already absolute (Unix, Windows drive or UNC style).
     */
    public static function isAbsolute(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        // Windows drive letter, e.g. C:\wordpress or C:/wordpress.
        return (bool) preg_match('#^[A-Za-z]:[/\\\\]#', $path);
    }

    /**
     * Resolve a possibly relative path against a base directory.
     *
     * Handles ".", "..", mixed separators and already-absolute paths without
     * touching the filesystem (no symlink resolution - realpath() is used
     * separately when a target must exist).
     */
    public static function resolve(string $path, ?string $base = null): string
    {
        if ($path === '') {
            return self::normalize($base ?? self::projectRoot());
        }

        if (!self::isAbsolute($path)) {
            $path = ($base ?? self::projectRoot()) . DIRECTORY_SEPARATOR . $path;
        }

        return self::normalize($path);
    }

    /**
     * Collapse ".", ".." and redundant separators in a path string.
     */
    public static function normalize(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        $prefix = '';
        if (preg_match('#^[A-Za-z]:' . preg_quote(DIRECTORY_SEPARATOR, '#') . '#', $path, $m)) {
            $prefix = $m[0];
            $path   = substr($path, strlen($m[0]));
        }

        $absolute = strpos($path, DIRECTORY_SEPARATOR) === 0;
        $segments = explode(DIRECTORY_SEPARATOR, $path);
        $stack    = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($stack !== [] && end($stack) !== '..') {
                    array_pop($stack);
                } elseif (!$absolute) {
                    $stack[] = '..';
                }

                continue;
            }

            $stack[] = $segment;
        }

        $normalized = implode(DIRECTORY_SEPARATOR, $stack);

        if ($absolute) {
            return DIRECTORY_SEPARATOR . $normalized;
        }

        if ($prefix !== '') {
            return $prefix . ($normalized === '' ? DIRECTORY_SEPARATOR : $normalized);
        }

        return $normalized === '' ? '.' : $normalized;
    }

    /**
     * Create a directory (and parents) when missing.
     *
     * @throws SetupException When the directory cannot be created.
     */
    public static function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!@mkdir($path, 0755, true) && !is_dir($path)) {
            throw new SetupException(
                sprintf('Unable to create directory "%s".', $path),
                [
                    'Check that the parent directory exists and is writable by the current user.',
                    'Avoid granting world-writable permissions; grant access only to the user that runs WP-CLI.',
                ]
            );
        }
    }

    /**
     * Whether the file or its nearest existing parent directory is writable.
     */
    public static function isWritableAt(string $path): bool
    {
        if (file_exists($path)) {
            return is_writable($path);
        }

        $parent = dirname($path);

        while ($parent !== dirname($parent)) {
            if (file_exists($parent)) {
                return is_writable($parent);
            }

            $parent = dirname($parent);
        }

        return false;
    }

    /**
     * Read the root folder slug of a theme/plugin ZIP (first path segment
     * that contains a style.css entry), or null when it cannot be determined.
     */
    public static function zipRootSlug(string $zipPath): ?string
    {
        if (!class_exists('ZipArchive')) {
            return null;
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            return null;
        }

        $slug = null;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (preg_match('#^([^/]+)/style\.css$#', $name, $matches)) {
                $slug = $matches[1];
                break;
            }
        }

        $zip->close();

        return $slug !== null && $slug !== '' ? $slug : null;
    }

    /**
     * Recursively delete a directory. Used only for temporary files created
     * by this tool; never pointed at user data.
     */
    public static function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
