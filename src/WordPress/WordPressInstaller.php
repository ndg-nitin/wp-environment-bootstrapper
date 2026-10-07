<?php

declare(strict_types=1);

namespace WpEnvironment\WordPress;

use WpEnvironment\Core\Logger;
use WpEnvironment\Core\SetupException;
use WpEnvironment\Support\CommandRunner;

/**
 * WordPress core lifecycle: download, wp-config, install, checksums.
 *
 * Every WP-CLI call receives an explicit --path so behaviour never depends on
 * the caller's working directory.
 */
final class WordPressInstaller
{
    /** @var array Normalized configuration. */
    private $config;

    /** @var CommandRunner */
    private $runner;

    /** @var Logger */
    private $logger;

    /** @var string Absolute WordPress installation path. */
    private $path;

    public function __construct(array $config, CommandRunner $runner, Logger $logger)
    {
        $this->config  = $config;
        $this->runner  = $runner;
        $this->logger  = $logger;
        $this->path    = $config['wordpress']['path'];
    }

    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * Whether WordPress core files are present at the target path.
     */
    public function coreExists(): bool
    {
        return is_file($this->path . DIRECTORY_SEPARATOR . 'wp-load.php');
    }

    /**
     * Whether WordPress has been installed (tables exist) at the target path.
     *
     * @throws SetupException When WordPress cannot be reached at all (e.g. database down).
     */
    public function isInstalled(): bool
    {
        if (!$this->coreExists()) {
            return false;
        }

        $result = $this->runner->run([
            'core',
            'is-installed',
            '--path=' . $this->path,
            '--url=' . $this->config['site']['url'],
        ]);

        if ($result->isOk()) {
            return true;
        }

        $output = $result->getOutput();

        if (preg_match('/Error establishing database connection|database error|wpdb/i', $output) === 1) {
            throw new SetupException(
                'WordPress could not reach the database.',
                $this->runner->buildHints($result, [
                    'Check that the database server is running and the credentials in your configuration are correct.',
                ])
            );
        }

        // Any other failure (wp-config missing, no tables, ...) means:
        // not installed yet.
        return false;
    }

    /**
     * Download WordPress core files when they are not present yet.
     *
     * Existing files are never overwritten - a mismatching version produces a
     * warning instead of silently replacing someone's working tree.
     *
     * @throws SetupException
     */
    public function download(): void
    {
        $version = $this->config['wordpress']['version'];

        if ($this->coreExists()) {
            $current = $this->currentVersion();

            if ($current !== null && $current === $version) {
                $this->logger->success(sprintf('WordPress %s is already present at %s - skipping download.', $version, $this->path));

                return;
            }

            $this->logger->warning(sprintf(
                'WordPress files already exist at %s (%s) but the configuration requests %s - existing files are left untouched.',
                $this->path,
                $current ?? 'unknown version',
                $version
            ));

            return;
        }

        $this->logger->info(sprintf('Downloading WordPress %s...', $version));

        $this->runner->runOrFail(
            [
                'core',
                'download',
                '--path=' . $this->path,
                '--version=' . $version,
            ],
            sprintf('WordPress %s download failed.', $version),
            [
                'Check your internet connection and that the version exists (https://wordpress.org/download/releases/).',
                'Check write permissions for the installation path: ' . $this->path,
            ]
        );

        $this->logger->success(sprintf('WordPress %s downloaded.', $version));
    }

    /**
     * Create wp-config.php (without connecting to the database yet - the
     * database itself may not exist before `wp db create` runs).
     *
     * @throws SetupException
     */
    public function createConfig(): void
    {
        $configFile = $this->path . DIRECTORY_SEPARATOR . 'wp-config.php';

        if (is_file($configFile)) {
            $this->logger->warning('wp-config.php already exists - leaving it untouched.');

            return;
        }

        $db = $this->config['database'];

        $this->logger->info('Creating wp-config.php...');

        $this->runner->runOrFail(
            [
                'config',
                'create',
                '--path=' . $this->path,
                '--dbname=' . $db['name'],
                '--dbuser=' . $db['user'],
                '--dbpass=' . $db['password'],
                '--dbhost=' . $db['host'],
                '--dbprefix=' . $db['prefix'],
                '--dbcharset=utf8mb4',
                '--skip-check',
            ],
            'wp-config.php creation failed.',
            [
                'Check that the database credentials in your configuration are correct.',
                'Remove a partially written wp-config.php and run setup again.',
            ]
        );

        $this->logger->success('wp-config.php created.');
    }

    /**
     * Run the actual WordPress installation (creates the tables and the
     * administrator account).
     *
     * @throws SetupException
     */
    public function install(): void
    {
        if ($this->isInstalled()) {
            $this->logger->warning(
                'WordPress is already installed - skipping installation. '
                . 'Admin credentials and site settings from the configuration are not applied to an existing installation.'
            );

            return;
        }

        $site  = $this->config['site'];
        $admin = $this->config['admin'];

        $this->logger->info('Installing WordPress...');

        $this->runner->runOrFail(
            [
                'core',
                'install',
                '--path=' . $this->path,
                '--url=' . $site['url'],
                '--title=' . $site['title'],
                '--admin_user=' . $admin['username'],
                '--admin_password=' . $admin['password'],
                '--admin_email=' . $admin['email'],
                '--skip-themes',
                '--skip-email',
            ],
            'WordPress installation failed.',
            [
                'Check that the database exists and is reachable with the configured credentials.',
                'If the database already contains WordPress tables from another project, use a different database.name.',
            ]
        );

        $this->logger->success('WordPress installed successfully.');
    }

    /**
     * Verify core files against the official WordPress.org checksums.
     *
     * A mismatch (files changed on disk) stops the setup; checksums merely
     * being unavailable (network/API problems) only produce a warning.
     *
     * @throws SetupException
     */
    public function verifyChecksums(): void
    {
        $version = $this->config['wordpress']['version'];

        $this->logger->info('Verifying WordPress core checksums...');

        $result = $this->runner->run([
            'core',
            'verify-checksums',
            '--path=' . $this->path,
            '--version=' . $version,
        ]);

        if ($result->isOk()) {
            $this->logger->success('WordPress core checksums verified.');

            return;
        }

        $output = $result->getOutput();

        if (preg_match('/couldn\'?t get checksums|couldn\'?t fetch|not available for WordPress|Checksum request/i', $output) === 1) {
            $this->logger->warning('Core checksums could not be retrieved (network or API issue) - verification skipped.');

            return;
        }

        if (preg_match('/doesn\'?t verify|does not verify|doesn\'?t exist|should not exist/i', $output) === 1) {
            throw new SetupException(
                'WordPress core checksum verification failed - files on disk differ from the official WordPress.org copies.',
                $this->runner->buildHints($result, [
                    'If you edited core files intentionally, expect this error: setup refuses to continue on modified core files.',
                    'To restore the official files: wp core download --path=' . $this->path . ' --version=' . $version . ' --force '
                    . '(back up wp-content first).',
                ])
            );
        }

        $this->logger->warning('Core checksum verification reported an unexpected problem - continuing (non-fatal).');
    }

    /**
     * The WordPress version actually present at the path, or null.
     */
    public function currentVersion(): ?string
    {
        $result = $this->runner->run([
            'core',
            'version',
            '--path=' . $this->path,
        ]);

        if (!$result->isOk()) {
            return null;
        }

        $version = trim($result->getStdout());

        return $version !== '' ? $version : null;
    }

    /**
     * Read a WordPress option value, or null when it cannot be read.
     */
    public function getOption(string $name): ?string
    {
        $result = $this->runner->run([
            'option',
            'get',
            $name,
            '--path=' . $this->path,
            '--url=' . $this->config['site']['url'],
        ]);

        if (!$result->isOk()) {
            return null;
        }

        $value = trim($result->getStdout());

        return $value !== '' ? $value : null;
    }
}
