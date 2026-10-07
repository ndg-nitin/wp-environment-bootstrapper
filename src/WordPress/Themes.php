<?php

declare(strict_types=1);

namespace WpEnvironment\WordPress;

use WpEnvironment\Core\Logger;
use WpEnvironment\Core\SetupException;
use WpEnvironment\Support\CommandRunner;
use WpEnvironment\Support\Downloader;
use WpEnvironment\Support\Filesystem;

/**
 * Theme lifecycle: install from a WordPress.org slug, a ZIP URL or a local
 * ZIP; activate it; optionally remove every inactive (default) theme.
 *
 * Cleanup only ever removes themes with status "inactive" - the active theme
 * and any parent theme it depends on are always kept.
 */
final class Themes
{
    /** @var string '' when no source is configured. */
    private $source;

    /** @var bool */
    private $activateFlag;

    /** @var bool */
    private $removeDefaults;

    /** @var string */
    private $path;

    /** @var CommandRunner */
    private $runner;

    /** @var Logger */
    private $logger;

    /** @var string|null Slug of the theme this run is about. */
    private $targetSlug = null;

    public function __construct(array $themeConfig, string $installPath, CommandRunner $runner, Logger $logger)
    {
        $this->source         = $themeConfig['source'];
        $this->activateFlag   = $themeConfig['activate'];
        $this->removeDefaults = $themeConfig['remove_default_themes'];
        $this->path           = $installPath;
        $this->runner         = $runner;
        $this->logger         = $logger;
    }

    public function getTargetSlug(): ?string
    {
        return $this->targetSlug;
    }

    /**
     * Install the configured theme.
     *
     * @throws SetupException On any installation failure.
     */
    public function install(): void
    {
        if ($this->source === '') {
            $this->logger->info('No theme source configured - skipping theme installation.');

            return;
        }

        $isUrl   = (bool) preg_match('#^https?://#i', $this->source);
        $display = $this->sourceDisplay($this->source);
        $local   = $this->source;
        $temp    = null;

        if ($isUrl) {
            if (strpos($this->source, '?') !== false) {
                $this->runner->addSecret($this->source);
            }

            $temp   = $this->tempZipPath();
            $local  = Downloader::download($this->source, $temp, $display);
        }

        try {
            // Determine the folder slug up front when we have the archive in
            // hand, so "already installed" and activation work reliably.
            $slug = $isUrl || preg_match('/\.zip$/i', $this->source) === 1
                ? Filesystem::zipRootSlug($local)
                : $this->source;

            if ($slug !== null && $this->isInstalled($slug)) {
                $this->targetSlug = $slug;
                $this->logger->success(sprintf('Theme "%s" is already installed - skipping.', $slug));

                return;
            }

            $before = $slug === null ? $this->installedThemes() : [];

            $this->logger->info(sprintf('Installing theme from %s...', $display));

            $result = $this->runner->run([
                'theme',
                'install',
                $local,
                '--path=' . $this->path,
            ]);

            if (!$result->isOk()) {
                if (preg_match('/already exists|already installed/i', $result->getOutput()) === 1) {
                    $this->targetSlug = $slug;
                    $this->logger->success(sprintf('Theme "%s" is already present on disk.', $slug ?? $display));

                    return;
                }

                throw new SetupException(
                    sprintf('Theme installation failed (%s).', $display),
                    $this->runner->buildHints($result, [
                        'For a WordPress.org slug, check it at https://themes.trac.wordpress.org/ (slugs contain no spaces).',
                        'For a ZIP source, verify the archive contains a style.css theme header.',
                        'Check write permissions for wp-content/themes.',
                    ])
                );
            }

            if ($slug === null) {
                // Archive slug was not readable - detect what appeared.
                $slug = $this->detectNewTheme($before);
            }

            $this->targetSlug = $slug;

            if ($slug !== null) {
                $this->logger->success(sprintf('Installed theme "%s".', $slug));
            } else {
                $this->logger->warning('Theme installed, but its slug could not be determined from the archive.');
            }
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    /**
     * Activate the configured theme when theme.activate is true.
     *
     * @throws SetupException When activation was requested but fails.
     */
    public function activate(): void
    {
        if (!$this->activateFlag || $this->source === '') {
            return;
        }

        if ($this->targetSlug === null) {
            $this->logger->warning(
                'The theme slug is unknown (the archive did not expose it), so automatic activation was skipped. '
                . 'Activate the theme manually from wp-admin or with: wp theme activate <slug> --path=' . $this->path
            );

            return;
        }

        $result = $this->runner->run([
            'theme',
            'activate',
            $this->targetSlug,
            '--path=' . $this->path,
        ]);

        if ($result->isOk()) {
            $this->logger->success(sprintf('Activated theme "%s".', $this->targetSlug));

            return;
        }

        if (preg_match('/already active|is already the active/i', $result->getOutput()) === 1) {
            $this->logger->info(sprintf('Theme "%s" is already active.', $this->targetSlug));

            return;
        }

        throw new SetupException(
            sprintf('Failed to activate theme "%s".', $this->targetSlug),
            $this->runner->buildHints($result, [
                'List available themes with: wp theme list --path=' . $this->path,
                'Check that the theme was installed and that its style.css is valid.',
            ])
        );
    }

    /**
     * Remove every inactive theme (default theme cleanup).
     *
     * Failures are recoverable: a theme that cannot be deleted only produces
     * a warning, because cleanup is not essential to a working environment.
     */
    public function removeInactive(): void
    {
        if (!$this->removeDefaults) {
            return;
        }

        $this->logger->info('Removing default themes...');

        $rows = $this->themeRows();
        $kept = [];

        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'inactive') {
                if (($row['name'] ?? '') !== '') {
                    $kept[] = $row['name'];
                }

                continue;
            }

            $result = $this->runner->run([
                'theme',
                'delete',
                $row['name'],
                '--path=' . $this->path,
            ]);

            if ($result->isOk()) {
                $this->logger->info(sprintf('Removed theme "%s".', $row['name']));
            } else {
                $this->logger->warning(sprintf(
                    'Could not remove theme "%s" (optional cleanup) - continuing.',
                    $row['name']
                ));
            }
        }

        if ($kept !== []) {
            $this->logger->success(sprintf('Default theme cleanup done. Kept: %s.', implode(', ', $kept)));
        }
    }

    /**
     * Verify the end state: installed (when configured) and active (when
     * activation was requested).
     *
     * @throws SetupException
     */
    public function verify(): void
    {
        if ($this->source === '') {
            return;
        }

        if ($this->targetSlug === null) {
            return; // Already warned during install/activation.
        }

        if (!$this->isInstalled($this->targetSlug)) {
            throw new SetupException(
                sprintf('Theme "%s" was not found after installation.', $this->targetSlug),
                ['List installed themes with: wp theme list --path=' . $this->path]
            );
        }

        if (!$this->activateFlag) {
            return;
        }

        $active = $this->activeThemes();

        if (!in_array($this->targetSlug, $active, true)) {
            throw new SetupException(
                sprintf('Theme "%s" is installed but not active.', $this->targetSlug),
                [
                    'Active theme(s): ' . ($active !== [] ? implode(', ', $active) : 'none'),
                    'Activate it with: wp theme activate ' . $this->targetSlug . ' --path=' . $this->path,
                ]
            );
        }
    }

    private function isInstalled(string $slug): bool
    {
        return in_array($slug, $this->installedThemes(), true);
    }

    /**
     * @return string[]
     */
    private function installedThemes(): array
    {
        $result = $this->runner->run([
            'theme',
            'list',
            '--field=name',
            '--path=' . $this->path,
        ]);

        if (!$result->isOk()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->getStdout()))));
    }

    /**
     * @return string[]
     */
    private function activeThemes(): array
    {
        $result = $this->runner->run([
            'theme',
            'list',
            '--status=active',
            '--field=name',
            '--path=' . $this->path,
        ]);

        if (!$result->isOk()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->getStdout()))));
    }

    /**
     * Full theme list with statuses, parsed from CSV.
     *
     * @return array<int, array{name:string,status:string}>
     */
    private function themeRows(): array
    {
        $result = $this->runner->run([
            'theme',
            'list',
            '--format=csv',
            '--path=' . $this->path,
        ]);

        if (!$result->isOk()) {
            return [];
        }

        $rows   = [];
        $lines  = explode("\n", trim($result->getStdout()));
        $header = null;

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $columns = str_getcsv($line, ',', '"', '\\');

            if ($header === null) {
                $header = array_flip(array_map('trim', $columns));

                continue;
            }

            $rows[] = [
                'name'   => (string) ($columns[$header['name'] ?? 0] ?? ''),
                'status' => (string) ($columns[$header['status'] ?? 1] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * Detect which theme appeared after an installation.
     *
     * @param string[] $before
     */
    private function detectNewTheme(array $before): ?string
    {
        $after = $this->installedThemes();
        $new   = array_diff($after, $before);

        return count($new) === 1 ? (string) reset($new) : null;
    }

    private function sourceDisplay(string $source): string
    {
        if (strpos($source, '?') !== false) {
            return (string) preg_replace('/\?.*$/', '?[redacted]', $source);
        }

        return $source;
    }

    /**
     * @throws SetupException
     */
    private function tempZipPath(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'wp-env-theme-');

        if ($base === false) {
            throw new SetupException(
                'Unable to create a temporary file for the theme download.',
                ['Check that the system temporary directory is writable.']
            );
        }

        @unlink($base);

        return $base . '.zip';
    }
}
