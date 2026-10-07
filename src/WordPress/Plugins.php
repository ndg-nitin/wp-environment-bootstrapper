<?php

declare(strict_types=1);

namespace WpEnvironment\WordPress;

use WpEnvironment\Core\Logger;
use WpEnvironment\Core\SetupException;
use WpEnvironment\Support\CommandResult;
use WpEnvironment\Support\CommandRunner;
use WpEnvironment\Support\Downloader;
use WpEnvironment\Support\Filesystem;

/**
 * Plugin lifecycle: install (WordPress.org, version pins, local/remote ZIPs,
 * licensed ACF Pro), activate, remove and checksum verification.
 *
 * Behaviour is governed by plugins.strict (default true):
 *   strict  = any plugin failure stops the setup;
 *   !strict = failures are reported as warnings and setup continues.
 */
final class Plugins
{
    private const ACF_PRO_SLUG       = 'acf-pro';
    private const ACF_PRO_FOLDER     = 'advanced-custom-fields-pro';
    private const ACF_PRO_DOWNLOAD   = 'https://connect.advancedcustomfields.com/index.php';

    /** @var array[] Normalized plugin definitions to install. */
    private $installEntries;

    /** @var string[] Slugs to remove. */
    private $removeSlugs;

    /** @var bool */
    private $strict;

    /** @var string Absolute WordPress path. */
    private $path;

    /** @var CommandRunner */
    private $runner;

    /** @var Logger */
    private $logger;

    /** @var string ACF Pro license key (never printed). */
    private $acfKey;

    /** @var int */
    private $installedCount = 0;

    /** @var int */
    private $removedCount = 0;

    public function __construct(
        array $pluginsConfig,
        string $installPath,
        CommandRunner $runner,
        Logger $logger,
        string $acfKey = ''
    ) {
        $this->installEntries = $pluginsConfig['install'];
        $this->removeSlugs    = $pluginsConfig['remove'];
        $this->strict         = $pluginsConfig['strict'];
        $this->path           = $installPath;
        $this->runner         = $runner;
        $this->logger         = $logger;
        $this->acfKey         = $acfKey;
    }

    public function getInstalledCount(): int
    {
        return $this->installedCount;
    }

    public function getRemovedCount(): int
    {
        return $this->removedCount;
    }

    /**
     * Slugs that must be active after a successful setup.
     *
     * @return string[]
     */
    public function getExpectedActiveSlugs(): array
    {
        $slugs = [];

        foreach ($this->installEntries as $entry) {
            if (!empty($entry['activate'])) {
                $slug = $this->slugFor($entry);

                if ($slug !== null) {
                    $slugs[] = $slug;
                }
            }
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Install and activate every configured plugin.
     */
    public function installAll(): void
    {
        if ($this->installEntries === []) {
            $this->logger->info('No plugins configured for installation.');

            return;
        }

        $this->logger->info('Installing plugins...');

        foreach ($this->installEntries as $entry) {
            $slug = $this->installEntry($entry);

            if ($slug !== null && !empty($entry['activate'])) {
                $this->activate($slug);
            }
        }

        if ($this->installedCount > 0) {
            $this->logger->success(sprintf('%d plugin(s) installed.', $this->installedCount));
        } else {
            $this->logger->info('All configured plugins were already installed.');
        }
    }

    /**
     * Deactivate and uninstall every plugin listed in plugins.remove.
     */
    public function removeUnwanted(): void
    {
        if ($this->removeSlugs === []) {
            $this->logger->info('No plugins configured for removal.');

            return;
        }

        $this->logger->info('Removing unwanted plugins...');

        foreach ($this->removeSlugs as $slug) {
            if (!$this->isInstalled($slug)) {
                $this->logger->info(sprintf('Plugin "%s" is not installed - nothing to remove.', $slug));

                continue;
            }

            $deactivate = $this->runner->run([
                'plugin',
                'deactivate',
                $slug,
                '--path=' . $this->path,
            ]);

            if (!$deactivate->isOk()) {
                $this->logger->warning(sprintf('Could not deactivate "%s" before removal - continuing.', $slug));
            }

            $result = $this->runner->run([
                'plugin',
                'uninstall',
                $slug,
                '--path=' . $this->path,
            ]);

            if ($result->isOk()) {
                $this->removedCount++;
                $this->logger->success(sprintf('Removed plugin "%s".', $slug));

                continue;
            }

            $this->fail(
                sprintf('Failed to remove plugin "%s".', $slug),
                $this->runner->buildHints($result, [
                    'Check filesystem permissions of wp-content/plugins/' . $slug . '.',
                    'Deactivate the plugin manually, then remove its directory.',
                ])
            );
        }

        if ($this->removedCount > 0) {
            $this->logger->success(sprintf('%d plugin(s) removed.', $this->removedCount));
        }
    }

    /**
     * Verify installed plugins against WordPress.org checksums where possible.
     *
     * Always recoverable: private plugins and locally modified files cannot be
     * verified, so failures produce warnings instead of stopping the setup.
     */
    public function verifyChecksums(): void
    {
        $this->logger->info('Verifying plugin checksums...');

        $result = $this->runner->run([
            'plugin',
            'verify-checksums',
            '--all',
            '--path=' . $this->path,
        ]);

        if ($result->isOk()) {
            $this->logger->success('Plugin checksums verified.');

            return;
        }

        $this->logger->warning(
            'Plugin checksum verification reported problems (private plugins and locally modified files cannot be verified):'
        );

        foreach (explode("\n", $result->getOutput()) as $line) {
            if (trim($line) !== '') {
                $this->logger->plain('    ' . trim($line));
            }
        }
    }

    /**
     * Install a single plugin from the correct source.
     *
     * @param array $entry Normalized plugin entry.
     * @return string|null The plugin slug, or null when installation failed in lenient mode.
     */
    private function installEntry(array $entry): ?string
    {
        if ($entry['name'] === self::ACF_PRO_SLUG) {
            return $this->installAcfPro($entry);
        }

        if (!empty($entry['source'])) {
            return $this->installFromSource($entry);
        }

        return $this->installFromRepository($entry);
    }

    /**
     * Install (or version-pin) a plugin from the WordPress.org repository.
     *
     * @return string|null
     */
    private function installFromRepository(array $entry): ?string
    {
        $slug    = $entry['name'];
        $version = $entry['version'] ?? '';

        if ($this->isInstalled($slug)) {
            if ($version === '') {
                $this->logger->info(sprintf('Plugin "%s" already installed - skipping.', $slug));

                return $slug;
            }

            $current = $this->installedVersion($slug);

            if ($current === $version) {
                $this->logger->info(sprintf('Plugin "%s" already at version %s - skipping.', $slug, $version));

                return $slug;
            }

            $this->logger->info(sprintf(
                'Plugin "%s" is at version %s but %s is configured - reinstalling the pinned version...',
                $slug,
                $current ?? 'unknown',
                $version
            ));

            $result = $this->runner->run([
                'plugin',
                'install',
                $slug,
                '--version=' . $version,
                '--force',
                '--path=' . $this->path,
            ]);

            if (!$result->isOk()) {
                $this->fail(
                    sprintf('Failed to install plugin "%s" version %s.', $slug, $version),
                    $this->repositoryHints($slug, $version, $result)
                );

                return null;
            }

            $this->installedCount++;
            $this->logger->success(sprintf('Installed plugin "%s" (%s).', $slug, $version));

            return $slug;
        }

        $args = ['plugin', 'install', $slug, '--path=' . $this->path];

        if ($version !== '') {
            $args[] = '--version=' . $version;
        }

        $result = $this->runner->run($args);

        if (!$result->isOk()) {
            $this->fail(
                sprintf('Failed to install plugin "%s".', $slug),
                $this->repositoryHints($slug, $version, $result)
            );

            return null;
        }

        $this->installedCount++;
        $this->logger->success(sprintf('Installed plugin "%s"%s.', $slug, $version !== '' ? ' (' . $version . ')' : ''));

        return $slug;
    }

    /**
     * Install a plugin from a local ZIP file or a URL.
     *
     * @return string|null
     */
    private function installFromSource(array $entry): ?string
    {
        $slug    = $entry['name'];
        $source  = $entry['source'];
        $isUrl   = (bool) preg_match('#^https?://#i', $source);
        $display = $this->sourceDisplay($source);
        $temp    = null;

        if ($isUrl) {
            // Signed/private URLs may contain tokens in the query string;
            // register them so nothing leaks into logs or error reports.
            if (strpos($source, '?') !== false) {
                $this->runner->addSecret($source);
            }

            $temp   = $this->tempZipPath('wp-env-plugin-');
            $source = Downloader::download($source, $temp, $display);
        }

        try {
            if ($this->isInstalled($slug)) {
                $this->logger->info(sprintf('Plugin "%s" already installed - skipping.', $slug));

                return $slug;
            }

            $result = $this->runner->run([
                'plugin',
                'install',
                $source,
                '--path=' . $this->path,
            ]);

            if (!$result->isOk()) {
                if (preg_match('/already exists|already installed/i', $result->getOutput()) === 1) {
                    $this->logger->info(sprintf('Plugin "%s" is already present on disk - skipping install.', $slug));

                    return $slug;
                }

                $this->fail(
                    sprintf('Failed to install plugin "%s" from %s.', $slug, $display),
                    $this->runner->buildHints($result, [
                        'Verify that the file is a valid WordPress plugin ZIP archive.',
                        'The "name" in your configuration must match the plugin folder slug inside the ZIP.',
                    ])
                );

                return null;
            }

            $this->installedCount++;
            $this->logger->success(sprintf('Installed plugin "%s" from %s.', $slug, $display));

            return $slug;
        } finally {
            if ($temp !== null) {
                @unlink($temp);
            }
        }
    }

    /**
     * Install ACF Pro via the official ACF download endpoint using the
     * user's own license key from secrets/env.json.
     *
     * The key is never written to disk, never printed, and the download URL
     * (which contains it) is redacted from all output.
     *
     * @return string|null
     * @throws SetupException In strict mode when the key is missing or the download fails.
     */
    private function installAcfPro(array $entry): ?string
    {
        $key = trim($this->acfKey);

        if ($key === '') {
            // Configuration validation normally catches this first.
            throw new SetupException(
                'ACF Pro was requested but no license key is configured.',
                [
                    'Set "acf_pro_key" in secrets/env.json (start from secrets/env.example.json).',
                    'Only a legitimate ACF Pro license is supported; this tool does not bypass licensing.',
                ]
            );
        }

        $version = $entry['version'] ?? '';
        $url     = self::ACF_PRO_DOWNLOAD . '?p=pro&a=download&k=' . rawurlencode($key);

        if ($version !== '') {
            $url .= '&t=' . rawurlencode($version);
        }

        $display = self::ACF_PRO_DOWNLOAD . '?p=pro&a=download&k=[redacted]';
        $temp    = $this->tempZipPath('wp-env-acf-pro-');

        $this->logger->info('Downloading ACF Pro from the official ACF endpoint...');

        try {
            Downloader::download($url, $temp, $display);

            $slug = Filesystem::zipRootSlug($temp) ?? self::ACF_PRO_FOLDER;

            if ($this->isInstalled($slug)) {
                $this->logger->info(sprintf('Plugin "%s" already installed - skipping.', $slug));

                return $slug;
            }

            $result = $this->runner->run([
                'plugin',
                'install',
                $temp,
                '--path=' . $this->path,
            ]);

            if (!$result->isOk()) {
                if (preg_match('/already exists|already installed/i', $result->getOutput()) === 1) {
                    $this->logger->info(sprintf('Plugin "%s" is already present on disk - skipping install.', $slug));

                    return $slug;
                }

                $this->fail(
                    'Failed to install the downloaded ACF Pro package.',
                    $this->runner->buildHints($result, [
                        'Verify your license key in secrets/env.json (the key itself is never printed).',
                        'Ensure this machine may reach connect.advancedcustomfields.com.',
                    ])
                );

                return null;
            }

            $this->installedCount++;
            $this->logger->success(sprintf('Installed plugin "%s".', $slug));

            return $slug;
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Activate a plugin.
     */
    private function activate(string $slug): void
    {
        $result = $this->runner->run([
            'plugin',
            'activate',
            $slug,
            '--path=' . $this->path,
        ]);

        if ($result->isOk()) {
            $this->logger->success(sprintf('Activated plugin "%s".', $slug));

            return;
        }

        if (preg_match('/already active/i', $result->getOutput()) === 1) {
            $this->logger->info(sprintf('Plugin "%s" is already active.', $slug));

            return;
        }

        $this->fail(
            sprintf('Failed to activate plugin "%s".', $slug),
            $this->runner->buildHints($result, [
                'Check that the plugin is installed with: wp plugin list --path=' . $this->path,
                'For ZIP installs, the "name" in your configuration must match the plugin folder slug.',
            ])
        );
    }

    /**
     * Is the plugin installed at the target path? (Read-only check.)
     */
    private function isInstalled(string $slug): bool
    {
        return $this->runner->run([
            'plugin',
            'is-installed',
            $slug,
            '--path=' . $this->path,
        ])->isOk();
    }

    /**
     * Installed version of a plugin, or null.
     */
    private function installedVersion(string $slug): ?string
    {
        $result = $this->runner->run([
            'plugin',
            'get',
            $slug,
            '--field=version',
            '--path=' . $this->path,
        ]);

        if (!$result->isOk()) {
            return null;
        }

        $version = trim($result->getStdout());

        return $version !== '' ? $version : null;
    }

    /**
     * Slug implied by an entry (ACF Pro maps to its real folder name only
     * after download; before that, the configured name is used).
     */
    private function slugFor(array $entry): ?string
    {
        return $entry['name'] !== '' ? $entry['name'] : null;
    }

    /**
     * Hints shared by WordPress.org install failures.
     *
     * @return string[]
     */
    private function repositoryHints(string $slug, string $version, CommandResult $result): array
    {
        $hints = [
            'Check the plugin slug against https://wordpress.org/plugins/.',
            'Check your internet connection.',
        ];

        if ($version !== '') {
            $hints[] = sprintf('The requested version "%s" may not exist for this plugin.', $version);
        }

        return $this->runner->buildHints($result, $hints);
    }

    /**
     * Report a plugin failure: throw in strict mode, warn otherwise.
     *
     * @param string[] $hints
     */
    private function fail(string $message, array $hints): void
    {
        if ($this->strict) {
            throw new SetupException($message, $hints);
        }

        $this->logger->warning($message . ' (continuing because plugins.strict is false)');

        foreach ($hints as $hint) {
            $this->logger->plain('    ' . $hint);
        }
    }

    /**
     * Human readable source with query strings redacted.
     */
    private function sourceDisplay(string $source): string
    {
        if (strpos($source, '?') !== false) {
            return (string) preg_replace('/\?.*$/', '?[redacted]', $source);
        }

        return $source;
    }

    /**
     * Reserve a unique temporary .zip path.
     *
     * @throws SetupException
     */
    private function tempZipPath(string $prefix): string
    {
        $base = tempnam(sys_get_temp_dir(), $prefix);

        if ($base === false) {
            throw new SetupException(
                'Unable to create a temporary file for the download.',
                ['Check that the system temporary directory is writable.']
            );
        }

        @unlink($base);

        return $base . '.zip';
    }
}
