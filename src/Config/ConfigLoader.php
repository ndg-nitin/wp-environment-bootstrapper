<?php

declare(strict_types=1);

namespace WpEnvironment\Config;

use WpEnvironment\Core\SetupException;
use WpEnvironment\Support\Filesystem;

/**
 * Loads and decodes JSON configuration files.
 *
 * Resolution order for the main configuration:
 *
 *   1. Explicit path passed with --config=... (resolved against the current
 *      working directory, like any CLI argument).
 *   2. <project-root>/config/setup.json   (the documented default)
 *   3. <project-root>/setup.json          (legacy location, still supported)
 *
 * Secrets (secrets/env.json) follow the same idea, with a legacy fallback to
 * the original env.json in the project root.
 *
 * Returned arrays are raw file contents; schema validation is done by
 * ConfigValidator.
 */
final class ConfigLoader
{
    /** @var string */
    private $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? Filesystem::projectRoot();
    }

    /**
     * Load configuration and secrets.
     *
     * @param string|null $configPath Explicit --config value, if any.
     * @throws SetupException When a file exists but cannot be read or parsed.
     * @return array{
     *     config_path: string,
     *     config: array,
     *     secrets_path: string|null,
     *     secrets: array,
     *     warnings: string[]
     * }
     */
    public function load(?string $configPath = null): array
    {
        $warnings = [];

        $resolvedPath = $this->resolveConfigPath($configPath, $warnings);
        $config       = $this->readJson($resolvedPath, 'Configuration file');

        if ($this->isLegacyConfig($config)) {
            $config = $this->normalizeLegacyConfig($config, $warnings);
        }

        [$secretsPath, $secrets, $secretWarnings] = $this->loadSecrets();
        $warnings = array_merge($warnings, $secretWarnings);

        if ($configPath === null && is_file($this->projectRoot . '/setup.json') && $resolvedPath !== $this->projectRoot . '/setup.json') {
            $warnings[] = 'Both config/setup.json and a legacy ./setup.json exist; config/setup.json takes precedence. '
                . 'Remove the legacy file or pass --config=setup.json to use it.';
        }

        return [
            'config_path' => $resolvedPath,
            'config'      => $config,
            'secrets_path' => $secretsPath,
            'secrets'     => $secrets,
            'warnings'    => $warnings,
        ];
    }

    /**
     * @param string[] $warnings
     * @throws SetupException
     */
    private function resolveConfigPath(?string $explicit, array &$warnings): string
    {
        if ($explicit !== null && $explicit !== '') {
            $cwd = getcwd() ?: $this->projectRoot;
            $path = Filesystem::resolve($explicit, $cwd);

            if (!is_file($path)) {
                throw new SetupException(
                    sprintf('Configuration file not found: %s', $path),
                    [
                        'Check the value passed to --config=.',
                        'A template is available at config/setup.example.json.',
                    ]
                );
            }

            return $path;
        }

        $default = $this->projectRoot . '/config/setup.json';

        if (is_file($default)) {
            return $default;
        }

        $legacy = $this->projectRoot . '/setup.json';

        if (is_file($legacy)) {
            $warnings[] = 'Using the legacy ./setup.json location. Consider moving it to config/setup.json '
                . '(see config/setup.example.json).';

            return $legacy;
        }

        throw new SetupException(
            'No configuration file found (looked for config/setup.json and ./setup.json).',
            [
                'Create one from the template: cp config/setup.example.json config/setup.json',
                'Then edit it: database credentials, site URL, admin password and wordpress.path - a relative '
                . 'wordpress.path is created NEXT to this repository, never inside it (e.g. "project" -> '
                . Filesystem::parentOf($this->projectRoot) . DIRECTORY_SEPARATOR . 'project).',
                'Or pass an explicit path: wp --require=setup.php setup --config=path/to/setup.json',
                'Short form of the same command: bin/wp-env (bin\\wp-env.cmd on Windows).',
            ]
        );
    }

    /**
     * Read and decode a JSON object.
     *
     * @throws SetupException
     * @return array
     */
    private function readJson(string $path, string $label): array
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new SetupException(
                sprintf('%s could not be read: %s', $label, $path),
                ['Check file permissions for the current user.']
            );
        }

        $decoded = json_decode($contents, true);

        if (!is_array($decoded)) {
            $detail = json_last_error() !== JSON_ERROR_NONE
                ? json_last_error_msg()
                : 'the top level must be a JSON object';

            throw new SetupException(
                sprintf('%s contains invalid JSON: %s (%s).', $label, $path, $detail),
                [
                    'Validate the file with: php -r "json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);" ' . $path,
                    'Compare it against the template: config/setup.example.json',
                ]
            );
        }

        // A JSON list at the top level is almost certainly a mistake.
        if (array_keys($decoded) !== range(0, count($decoded) - 1) || $decoded === []) {
            return $decoded;
        }

        throw new SetupException(
            sprintf('%s must contain a JSON object at the top level: %s', $label, $path),
            ['Compare it against the template: config/setup.example.json']
        );
    }

    /**
     * Detect configuration written for the original setup.php.
     */
    private function isLegacyConfig(array $data): bool
    {
        return isset($data['dbname']) || isset($data['site_url']) || isset($data['admin_name']);
    }

    /**
     * Map the original setup.json schema onto the current schema so existing
     * project files keep working without manual edits.
     *
     * @param string[] $warnings
     */
    private function normalizeLegacyConfig(array $data, array &$warnings): array
    {
        $warnings[] = 'Detected the legacy setup.json schema (version/dbname/site_url/...). It has been mapped to '
            . 'the current format automatically; see config/setup.example.json for the new schema.';

        $install = [];

        foreach (is_array($data['pluginListInstall'] ?? null) ? $data['pluginListInstall'] : [] as $entry) {
            if (is_string($entry)) {
                $entry = ['name' => $entry];
            }

            if (!is_array($entry) || trim((string) ($entry['name'] ?? '')) === '') {
                continue;
            }

            $item = [
                'name'    => (string) $entry['name'],
                'activate' => !empty($entry['status']),
            ];

            if (!empty($entry['version'])) {
                $item['version'] = (string) $entry['version'];
            }

            if (!empty($entry['source'])) {
                $item['source'] = (string) $entry['source'];
            }

            $install[] = $item;
        }

        $remove = [];

        foreach (is_array($data['pluginListUninstall'] ?? null) ? $data['pluginListUninstall'] : [] as $entry) {
            if (is_string($entry)) {
                $remove[] = $entry;
            } elseif (is_array($entry) && trim((string) ($entry['name'] ?? '')) !== '') {
                $remove[] = (string) $entry['name'];
            }
        }

        $config = [
            'wordpress' => [
                'version' => (string) ($data['version'] ?? ''),
                'path'    => (string) ($data['path'] ?? '.'),
            ],
            'database'  => [
                'name'     => (string) ($data['dbname'] ?? ''),
                'user'     => (string) ($data['dbuser'] ?? ''),
                'password' => (string) ($data['dbpass'] ?? ''),
                'host'     => (string) ($data['dbhost'] ?? 'localhost'),
                'prefix'   => (string) ($data['dbprefix'] ?? 'wp_'),
            ],
            'site'      => [
                'url'   => (string) ($data['site_url'] ?? ''),
                'title' => (string) ($data['title'] ?? ''),
            ],
            'admin'     => [
                'username' => (string) ($data['admin_name'] ?? ''),
                'password' => (string) ($data['admin_password'] ?? ''),
                'email'    => (string) ($data['admin_email'] ?? ''),
            ],
            'plugins'   => [
                'install' => $install,
                'remove'  => $remove,
            ],
            'theme'     => [
                'source'               => (string) ($data['axioned_theme'] ?? ''),
                'activate'             => true,
                'remove_default_themes' => true,
            ],
        ];

        if (isset($data['pluginList'])) {
            $warnings[] = 'The legacy "pluginList" is a reference catalog only and is never installed by the setup '
                . 'command. The equivalent catalog now lives in config/plugins.json.';
        }

        return $config;
    }

    /**
     * Load secrets, falling back to the legacy env.json location.
     *
     * @return array{0: string|null, 1: array, 2: string[]}
     */
    private function loadSecrets(): array
    {
        $warnings = [];

        $candidates = [
            $this->projectRoot . '/secrets/env.json',
            $this->projectRoot . '/env.json',
        ];

        foreach ($candidates as $index => $path) {
            if (!is_file($path)) {
                continue;
            }

            $secrets = $this->readJson($path, 'Secrets file');

            if ($index > 0) {
                $warnings[] = 'Using the legacy ./env.json location. Consider moving it to secrets/env.json '
                    . '(see secrets/env.example.json). The file must stay out of version control.';
            }

            if (!isset($secrets['acf_pro_key']) && isset($secrets['acf_key'])) {
                $secrets['acf_pro_key'] = (string) $secrets['acf_key'];
                $warnings[] = 'The legacy env.json key "acf_key" was mapped to "acf_pro_key".';
            }

            return [$path, $secrets, $warnings];
        }

        return [null, [], $warnings];
    }
}
