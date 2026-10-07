<?php

declare(strict_types=1);

namespace WpEnvironment\Core;

use WpEnvironment\Support\CommandRunner;
use WpEnvironment\Support\Progress;
use WpEnvironment\WordPress\Database;
use WpEnvironment\WordPress\Plugins;
use WpEnvironment\WordPress\Themes;
use WpEnvironment\WordPress\WordPressInstaller;

/**
 * Orchestrates the full setup workflow:
 *
 *   Validate configuration
 *           ↓
 *   Environment checks (PHP, WP-CLI, database)
 *           ↓
 *   Download WordPress → wp-config.php → create database → install
 *           ↓
 *   Verify core checksums
 *           ↓
 *   Install/activate plugins → remove unwanted plugins → verify plugin checksums
 *           ↓
 *   Install theme → activate theme → remove default themes
 *           ↓
 *   Validate the final installation → print summary
 *
 * `dryRun()` performs only read-only checks and prints the plan; the
 * CommandRunner is constructed in dry-run mode as a second layer of safety,
 * so no WP-CLI command can modify anything even by mistake.
 */
final class Environment
{
    /** @var array */
    private $config;

    /** @var Logger */
    private $logger;

    /** @var Progress */
    private $progress;

    /** @var CommandRunner */
    private $runner;

    /** @var Validator */
    private $validator;

    /** @var Database */
    private $database;

    /** @var WordPressInstaller */
    private $installer;

    /** @var Plugins */
    private $plugins;

    /** @var Themes */
    private $themes;

    /** @var bool */
    private $dryRun;

    public function __construct(array $config, array $secrets, Logger $logger, bool $dryRun = false)
    {
        $this->config   = $config;
        $this->logger   = $logger;
        $this->dryRun   = $dryRun;
        $this->progress = new Progress($logger);
        $this->runner   = new CommandRunner($logger, $dryRun);

        // Nothing that could reappear in command output or error reports may
        // be logged unredacted: the database password plus every non-trivial
        // value from the secrets file (ACF Pro key, ...).
        $this->runner->addSecret((string) $config['database']['password']);

        foreach ($secrets as $value) {
            if (is_string($value) && strlen($value) >= 6) {
                $this->runner->addSecret($value);
            }
        }

        $path = $config['wordpress']['path'];

        $this->database  = new Database($config['database'], $path, $this->runner, $logger);
        $this->installer = new WordPressInstaller($config, $this->runner, $logger);
        $this->validator = new Validator($this->runner);
        $this->plugins   = new Plugins($config['plugins'], $path, $this->runner, $logger, (string) ($secrets['acf_pro_key'] ?? ''));
        $this->themes    = new Themes($config['theme'], $path, $this->runner, $logger);
    }

    /**
     * Print the complete plan without changing anything.
     *
     * @param array $flags {'skip_plugins': bool, 'skip_theme': bool}
     * @throws SetupException When required environment checks fail.
     */
    public function dryRun(array $flags = []): void
    {
        $this->printHeader();

        $failedHints = $this->runChecks();

        $this->printConfiguration();
        $this->printPlan($flags);

        $this->logger->blank();
        $this->logger->plain('DRY RUN');
        $this->logger->plain('No changes were made.');

        if ($failedHints !== []) {
            throw new SetupException('Environment checks failed.', $failedHints);
        }
    }

    /**
     * Execute the setup.
     *
     * @param array $flags {'skip_plugins': bool, 'skip_theme': bool}
     * @throws SetupException On any critical failure.
     */
    public function run(array $flags = []): void
    {
        $skipPlugins = !empty($flags['skip_plugins']);
        $skipTheme   = !empty($flags['skip_theme']);

        $this->printHeader();

        $failedHints = $this->runChecks();

        if ($failedHints !== []) {
            throw new SetupException('Environment checks failed - nothing was changed.', $failedHints);
        }

        $this->printConfiguration();

        // WordPress core: files → config → database → installation → checksums.
        $this->installer->download();
        $this->installer->createConfig();
        $this->database->create();
        $this->installer->install();
        $this->installer->verifyChecksums();

        if ($skipPlugins) {
            $this->logger->info('Skipping plugin steps (--skip-plugins).');
        } else {
            $this->plugins->installAll();
            $this->plugins->removeUnwanted();
            $this->plugins->verifyChecksums();
        }

        if ($skipTheme) {
            $this->logger->info('Skipping theme steps (--skip-theme).');
        } else {
            $this->themes->install();
            $this->themes->activate();
            $this->themes->removeInactive();
        }

        $this->validateInstallation($skipPlugins, $skipTheme);
        $this->printSummary($skipPlugins, $skipTheme);
    }

    private function printHeader(): void
    {
        $this->logger->separator();
        $this->logger->heading('WordPress Environment Setup');
        $this->logger->separator();
    }

    /**
     * Run and print the read-only environment checks.
     *
     * @return string[] Hints describing failed checks ([] when all passed).
     */
    private function runChecks(): array
    {
        $checks = $this->validator->check($this->database, (string) $this->config['wordpress']['path']);

        $this->progress->section('Environment checks');

        $failedHints = [];

        foreach ($checks as $check) {
            $this->progress->check($check['label'], $check['status'] !== 'fail', $check['detail']);

            if ($check['status'] === 'fail') {
                $failedHints[] = $check['label'] . ' check failed.';
                $failedHints   = array_merge($failedHints, $check['hints']);
            }
        }

        return $failedHints;
    }

    private function printConfiguration(): void
    {
        $this->progress->section('Configuration');
        $this->progress->check('WordPress ' . $this->config['wordpress']['version'], true);
        $this->progress->check('Database configured', true, sprintf(
            '%s @ %s',
            $this->config['database']['name'],
            $this->config['database']['host']
        ));
        $this->progress->check('Site configured', true, $this->config['site']['url']);
        $this->progress->kv('Path', $this->config['wordpress']['path']);
    }

    /**
     * @param array $flags {'skip_plugins': bool, 'skip_theme': bool}
     */
    private function printPlan(array $flags): void
    {
        $skipPlugins = !empty($flags['skip_plugins']);
        $skipTheme   = !empty($flags['skip_theme']);

        $this->progress->section('Plugins to install');

        if ($skipPlugins) {
            $this->progress->note('(skipped: --skip-plugins)');
        } elseif ($this->config['plugins']['install'] === []) {
            $this->progress->note('None');
        } else {
            foreach ($this->config['plugins']['install'] as $entry) {
                $this->progress->add($this->pluginPlanLabel($entry));
            }
        }

        $this->progress->section('Plugins to remove');

        if ($skipPlugins) {
            $this->progress->note('(skipped: --skip-plugins)');
        } elseif ($this->config['plugins']['remove'] === []) {
            $this->progress->note('None');
        } else {
            foreach ($this->config['plugins']['remove'] as $slug) {
                $this->progress->remove($slug);
            }
        }

        $this->progress->section('Theme');

        if ($skipTheme) {
            $this->progress->note('(skipped: --skip-theme)');

            return;
        }

        $theme = $this->config['theme'];

        if ($theme['source'] === '') {
            $this->progress->note('None configured');

            return;
        }

        $suffix = '';

        if (!$theme['activate']) {
            $suffix .= ' (activation disabled)';
        }

        if (!$theme['remove_default_themes']) {
            $suffix .= ' (default themes kept)';
        }

        $this->progress->add($theme['source'] . $suffix);
    }

    /**
     * Human readable one-line plan label for a plugin entry.
     */
    private function pluginPlanLabel(array $entry): string
    {
        $label = $entry['name'];

        if ($label === 'acf-pro') {
            $label = 'acf-pro (official ACF Pro download)';
        }

        if (!empty($entry['version'])) {
            $label .= ' (' . $entry['version'] . ')';
        }

        if (!empty($entry['source'])) {
            $label .= preg_match('#^https?://#i', $entry['source']) === 1 ? ' [URL]' : ' [local ZIP]';
        }

        return $label;
    }

    /**
     * Validate the end state of the installation.
     *
     * @throws SetupException
     */
    private function validateInstallation(bool $skipPlugins, bool $skipTheme): void
    {
        $this->logger->info('Validating the final installation...');
        $this->progress->section('Verifying installation');

        // 1. WordPress must be installed.
        if (!$this->installer->isInstalled()) {
            throw new SetupException(
                'WordPress is not installed at the end of setup.',
                [
                    'Check the database tables with: wp db tables --path=' . $this->config['wordpress']['path'],
                    'Check the installation path: ' . $this->config['wordpress']['path'],
                ]
            );
        }

        $this->progress->check('WordPress installed', true, $this->installer->currentVersion());

        // 2. Site URL consistency (read-only; never silently rewritten).
        $siteurl = $this->installer->getOption('siteurl');

        if ($siteurl === null) {
            $this->logger->warning('Could not read the stored site URL - comparison skipped.');
        } elseif ($siteurl !== $this->config['site']['url']) {
            $this->logger->warning(sprintf(
                'Stored site URL "%s" differs from the configured "%s". WordPress keeps the stored value; '
                . 'align it with: wp option update siteurl %s --path=%s',
                $siteurl,
                $this->config['site']['url'],
                $this->config['site']['url'],
                $this->config['wordpress']['path']
            ));
        } else {
            $this->progress->check('Site URL matches configuration', true, $siteurl);
        }

        // 3. Configured plugins must be active.
        if ($skipPlugins) {
            $this->progress->note('Plugin activation not checked (--skip-plugins)');
        } else {
            $expected = $this->plugins->getExpectedActiveSlugs();

            if ($expected !== []) {
                $active   = $this->activePlugins();
                $missing  = array_values(array_diff($expected, $active));

                if ($missing === []) {
                    $this->progress->check('Configured plugins active', true, implode(', ', $expected));
                } else {
                    $message = 'These plugins are not active after setup: ' . implode(', ', $missing);
                    $hints   = [
                        'Activate them with: wp plugin activate <slug> --path=' . $this->config['wordpress']['path'],
                        'If a plugin folder name differs from your configuration, align plugins.install[].name with the folder slug.',
                    ];

                    if (!empty($this->config['plugins']['strict'])) {
                        throw new SetupException($message, $hints);
                    }

                    $this->logger->warning($message . ' (continuing because plugins.strict is false)');

                    foreach ($hints as $hint) {
                        $this->progress->note($hint);
                    }
                }
            }
        }

        // 4. Theme must be installed and active when configured.
        if ($skipTheme) {
            $this->progress->note('Theme not checked (--skip-theme)');
        } else {
            $this->themes->verify();

            if ($this->themes->getTargetSlug() !== null) {
                $this->progress->check(
                    'Theme ready',
                    true,
                    $this->themes->getTargetSlug() . ($this->config['theme']['activate'] ? ' (active)' : '')
                );
            }
        }

        $this->logger->success('Installation verified.');
    }

    /**
     * @return string[]
     */
    private function activePlugins(): array
    {
        $result = $this->runner->run([
            'plugin',
            'list',
            '--status=active',
            '--field=name',
            '--path=' . $this->config['wordpress']['path'],
        ]);

        if (!$result->isOk()) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $result->getStdout()))));
    }

    private function printSummary(bool $skipPlugins, bool $skipTheme): void
    {
        $url = $this->config['site']['url'];

        $this->logger->separator();
        $this->logger->success('WordPress environment created successfully.');
        $this->logger->plain();

        $this->progress->kv('Site', $url, true);
        $this->progress->kv('Admin', $url . '/wp-admin', true);
        $this->progress->kv('WordPress', (string) ($this->installer->currentVersion() ?? $this->config['wordpress']['version']), true);

        if ($skipPlugins) {
            $this->progress->kv('Plugins', 'skipped (--skip-plugins)', true);
        } else {
            $this->progress->kv('Plugins installed', (string) $this->plugins->getInstalledCount(), true);
            $this->progress->kv('Plugins removed', (string) $this->plugins->getRemovedCount(), true);
        }

        if ($skipTheme) {
            $this->progress->kv('Theme', 'skipped (--skip-theme)', true);
        } else {
            $themeLabel = $this->themes->getTargetSlug();

            if ($themeLabel === null) {
                $themeLabel = $this->config['theme']['source'] === '' ? '(defaults only)' : $this->config['theme']['source'];
            }

            $this->progress->kv('Theme', $themeLabel, true);
        }

        $this->logger->plain();
        $this->logger->success('Setup completed successfully.');
        $this->logger->separator();
    }
}
