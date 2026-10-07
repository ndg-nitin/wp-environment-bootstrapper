<?php

declare(strict_types=1);

namespace WpEnvironment\Config;

use WpEnvironment\Support\Filesystem;

/**
 * Validates and normalizes the decoded configuration.
 *
 * Collects EVERY problem it can find (instead of stopping at the first one)
 * so a developer can fix the file in a single pass. Validation never mutates
 * the input array; a normalized copy is returned alongside the report.
 *
 * Result shape:
 *
 *   [
 *     'config'   => normalized configuration,
 *     'errors'   => string[]  (critical - setup must not run),
 *     'warnings' => string[]  (informational - setup may run),
 *   ]
 */
final class ConfigValidator
{
    private const WP_VERSION_PATTERN = '/^\d+\.\d+(\.\d+)?$/';
    private const SLUG_PATTERN       = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';
    private const DB_NAME_PATTERN    = '/^[A-Za-z0-9_]+$/';
    private const VERSION_PATTERN    = '/^[0-9][0-9A-Za-z.-]*$/';

    private const PLACEHOLDER_PASSWORDS = [
        'admin',
        'password',
        'passw0rd',
        'change-me',
        'change-this-password',
        '12345678',
        'qwerty',
    ];

    /** @var string */
    private $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot ?? Filesystem::projectRoot();
    }

    /**
     * @param array $config  Raw configuration as decoded from JSON.
     * @param array $secrets Decoded secrets (secrets/env.json).
     * @return array{config: array, errors: string[], warnings: string[]}
     */
    public function validate(array $config, array $secrets = []): array
    {
        $errors   = [];
        $warnings = [];

        $this->checkUnknownKeys(
            $config,
            ['wordpress', 'database', 'site', 'admin', 'plugins', 'theme'],
            '',
            $warnings
        );

        $normalized = $config;

        $normalized['wordpress'] = $this->validateWordpress($config, $errors, $warnings);
        $normalized['database']  = $this->validateDatabase($config, $errors, $warnings);
        $normalized['site']      = $this->validateSite($config, $errors, $warnings);
        $normalized['admin']     = $this->validateAdmin($config, $errors, $warnings);
        $normalized['plugins']   = $this->validatePlugins($config, $secrets, $errors, $warnings);
        $normalized['theme']     = $this->validateTheme($config, $errors, $warnings);

        return [
            'config'   => $normalized,
            'errors'   => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function validateWordpress(array $config, array &$errors, array &$warnings): array
    {
        $section = $this->section($config, 'wordpress', $errors);

        $this->checkUnknownKeys($section, ['version', 'path'], 'wordpress', $warnings);

        $version = $section['version'] ?? null;

        if (!is_string($version) || $version === '') {
            $errors[] = 'wordpress.version: required - specify a WordPress version such as "6.8.2".';
            $version  = '';
        } elseif (!preg_match(self::WP_VERSION_PATTERN, $version)) {
            $errors[] = sprintf(
                'wordpress.version: "%s" is not a valid WordPress version (expected a form like "6.8.2").',
                $this->printable($version)
            );
            $version = '';
        }

        $path = $section['path'] ?? null;

        if (!is_string($path) || trim($path) === '') {
            $errors[] = 'wordpress.path: required - installation directory, e.g. "." for the project root or "./wordpress".';
            $path     = '.';
        }

        return [
            'version' => $version,
            'path'    => Filesystem::resolve($path, $this->projectRoot),
        ];
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function validateDatabase(array $config, array &$errors, array &$warnings): array
    {
        $section = $this->section($config, 'database', $errors);

        $this->checkUnknownKeys($section, ['name', 'user', 'password', 'host', 'prefix'], 'database', $warnings);

        $name = $section['name'] ?? null;

        if (!is_string($name) || $name === '') {
            $errors[] = 'database.name: required - the MySQL/MariaDB database to create.';
            $name     = '';
        } elseif (!preg_match(self::DB_NAME_PATTERN, $name)) {
            $errors[] = sprintf(
                'database.name: "%s" may only contain letters, numbers and underscores.',
                $this->printable($name)
            );
            $name = '';
        }

        $user = $section['user'] ?? null;

        if (!is_string($user) || $user === '') {
            $errors[] = 'database.user: required - the database user (no default is assumed).';
            $user     = '';
        }

        if (array_key_exists('password', $section)) {
            $password = $section['password'];

            if (!is_string($password)) {
                $errors[]   = 'database.password: must be a string (use "" for an empty password).';
                $password   = '';
            }
        } else {
            $password = '';
            $warnings[] = 'database.password: not set - assuming an empty password.';
        }

        $host = $section['host'] ?? null;

        if (!is_string($host) || $host === '') {
            $errors[] = 'database.host: required - e.g. "localhost" or "127.0.0.1:3307".';
            $host     = 'localhost';
        } else {
            $colons = substr_count($host, ':');

            if ($colons === 1) {
                [, $tail] = explode(':', $host, 2);

                if ($tail !== '' && !ctype_digit($tail) && ($tail[0] ?? '') !== '/') {
                    $errors[] = sprintf(
                        'database.host: "%s" has an invalid port - use "host", "host:3306" or a socket path.',
                        $this->printable($host)
                    );
                } elseif (ctype_digit($tail) && ((int) $tail < 1 || (int) $tail > 65535)) {
                    $errors[] = 'database.host: port must be between 1 and 65535.';
                }
            }
        }

        $prefix = $section['prefix'] ?? 'wp_';

        if (!is_string($prefix) || $prefix === '') {
            $prefix = 'wp_';
        } elseif (!preg_match(self::DB_NAME_PATTERN, $prefix)) {
            $errors[] = sprintf(
                'database.prefix: "%s" may only contain letters, numbers and underscores.',
                $this->printable($prefix)
            );
            $prefix = 'wp_';
        } elseif (substr($prefix, -1) !== '_') {
            $warnings[] = sprintf('database.prefix: "%s" does not end with "_" - this is allowed but unconventional.', $prefix);
        }

        return [
            'name'     => $name,
            'user'     => $user,
            'password' => $password,
            'host'     => $host,
            'prefix'   => $prefix,
        ];
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function validateSite(array $config, array &$errors, array &$warnings): array
    {
        $section = $this->section($config, 'site', $errors);

        $this->checkUnknownKeys($section, ['url', 'title'], 'site', $warnings);

        $url = $section['url'] ?? null;

        if (!is_string($url) || $url === '') {
            $errors[] = 'site.url: required - e.g. "http://localhost/wordpress".';
            $url      = '';
        } elseif (!preg_match('#^https?://#i', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $errors[] = sprintf(
                'site.url: "%s" is not a valid http(s) URL.',
                $this->printable($url)
            );
            $url = '';
        } else {
            $url = rtrim($url, '/');
        }

        $title = $section['title'] ?? null;

        if (!is_string($title) || trim($title) === '') {
            $errors[] = 'site.title: required - the WordPress site title.';
            $title    = '';
        }

        return ['url' => $url, 'title' => $title];
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function validateAdmin(array $config, array &$errors, array &$warnings): array
    {
        $section = $this->section($config, 'admin', $errors);

        $this->checkUnknownKeys($section, ['username', 'password', 'email'], 'admin', $warnings);

        $username = $section['username'] ?? null;

        if (!is_string($username) || trim($username) === '') {
            $errors[]  = 'admin.username: required - the administrator login.';
            $username  = '';
        } elseif (preg_match('/\s/', $username)) {
            $errors[]  = 'admin.username: must not contain whitespace.';
            $username  = trim($username);
        } elseif (strlen($username) > 60) {
            $warnings[] = 'admin.username: longer than 60 characters - WordPress will truncate it.';
        }

        $password = $section['password'] ?? null;

        if (!is_string($password) || $password === '') {
            $errors[] = 'admin.password: required - the administrator password.';
            $password = '';
        } else {
            if (strlen($password) < 8) {
                $warnings[] = 'admin.password: shorter than 8 characters - fine for throwaway local sites, risky anywhere else.';
            }

            if (in_array(strtolower($password), self::PLACEHOLDER_PASSWORDS, true)) {
                $warnings[] = 'admin.password: looks like a placeholder value - change it before sharing this environment.';
            }
        }

        $email = $section['email'] ?? null;

        if (!is_string($email) || $email === '') {
            $errors[] = 'admin.email: required - the administrator e-mail address.';
            $email    = '';
        } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = sprintf('admin.email: "%s" is not a valid e-mail address.', $this->printable($email));
            $email    = '';
        }

        return [
            'username' => $username,
            'password' => $password,
            'email'    => $email,
        ];
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function validatePlugins(array $config, array $secrets, array &$errors, array &$warnings): array
    {
        $section = $config['plugins'] ?? [];

        if (!is_array($section)) {
            $errors[] = 'plugins: must be an object with "install" and "remove" arrays.';
            $section  = [];
        }

        $this->checkUnknownKeys($section, ['install', 'remove', 'strict'], 'plugins', $warnings);

        // --- install -------------------------------------------------------
        $install = $section['install'] ?? [];

        if ($install === null) {
            $install = [];
        }

        if (!is_array($install)) {
            $errors[]        = 'plugins.install: must be an array of plugin definitions.';
            $install         = [];
        }

        $normalizedInstall = [];
        $seenNames         = [];

        foreach (array_values($install) as $index => $entry) {
            $label = 'plugins.install[' . $index . ']';

            if (is_string($entry)) {
                $entry = ['name' => $entry];
            }

            if (!is_array($entry)) {
                $errors[] = $label . ': must be a plugin slug string or an object with a "name" property.';
                continue;
            }

            $this->checkUnknownKeys($entry, ['name', 'version', 'source', 'activate'], $label, $warnings);

            $name = $entry['name'] ?? null;

            if (!is_string($name) || $name === '') {
                $errors[] = $label . '.name: required - the plugin slug.';
                continue;
            }

            if (!preg_match(self::SLUG_PATTERN, $name)) {
                $errors[] = sprintf('%s.name: "%s" is not a valid plugin slug.', $label, $this->printable($name));
                continue;
            }

            if (isset($seenNames[$name])) {
                $warnings[] = $label . ': duplicate entry "' . $name . '" - it will only be processed once.';
                continue;
            }

            $item = ['name' => $name, 'activate' => $this->bool($entry, 'activate', true, $label, $errors)];

            if (array_key_exists('version', $entry) && $entry['version'] !== '' && $entry['version'] !== null) {
                if (!is_string($entry['version']) || !preg_match(self::VERSION_PATTERN, $entry['version'])) {
                    $errors[] = sprintf(
                        '%s.version: "%s" is not a valid version string.',
                        $label,
                        $this->printable((string) $entry['version'])
                    );
                } else {
                    $item['version'] = $entry['version'];
                }
            }

            if (array_key_exists('source', $entry) && $entry['source'] !== '' && $entry['source'] !== null) {
                if (!is_string($entry['source'])) {
                    $errors[] = $label . '.source: must be a URL or a file path string.';
                } else {
                    $resolved = $this->resolveArchiveSource($entry['source'], $label . '.source', $errors);

                    if ($resolved !== null) {
                        $item['source'] = $resolved;
                    }
                }
            }

            $normalizedInstall[] = $item;
            $seenNames[$name]    = true;
        }

        // --- remove --------------------------------------------------------
        $remove = $section['remove'] ?? [];

        if ($remove === null) {
            $remove = [];
        }

        if (!is_array($remove)) {
            $errors[] = 'plugins.remove: must be an array of plugin slugs.';
            $remove   = [];
        }

        $normalizedRemove = [];

        foreach (array_values($remove) as $index => $entry) {
            $label = 'plugins.remove[' . $index . ']';

            if (is_array($entry)) {
                $warnings[] = $label . ': object form is deprecated - use a plain slug string.';
                $entry = $entry['name'] ?? '';
            }

            if (!is_string($entry) || $entry === '') {
                $errors[] = $label . ': must be a plugin slug string.';
                continue;
            }

            if (!preg_match(self::SLUG_PATTERN, $entry)) {
                $errors[] = sprintf('%s: "%s" is not a valid plugin slug.', $label, $this->printable($entry));
                continue;
            }

            $normalizedRemove[] = $entry;
        }

        $normalizedRemove = array_values(array_unique($normalizedRemove));

        $installNames = array_column($normalizedInstall, 'name');
        $overlap      = array_intersect($installNames, $normalizedRemove);

        if ($overlap !== []) {
            $errors[] = 'plugins: "' . implode('", "', $overlap) . '" cannot be installed and removed at the same time.';
        }

        if (in_array('acf-pro', $installNames, true) && trim((string) ($secrets['acf_pro_key'] ?? '')) === '') {
            $errors[] = 'plugins.install: "acf-pro" requires your ACF Pro license key - set "acf_pro_key" in '
                . 'secrets/env.json (start from secrets/env.example.json). Only legitimate license keys are supported.';
        }

        $strict = $this->bool($section, 'strict', true, 'plugins', $errors);

        return [
            'install' => $normalizedInstall,
            'remove'  => $normalizedRemove,
            'strict'  => $strict,
        ];
    }

    /**
     * @param string[] $errors
     * @param string[] $warnings
     */
    private function validateTheme(array $config, array &$errors, array &$warnings): array
    {
        $section = $config['theme'] ?? [];

        if (!is_array($section)) {
            $errors[] = 'theme: must be an object with "source", "activate" and "remove_default_themes".';
            $section  = [];
        }

        $this->checkUnknownKeys($section, ['source', 'activate', 'remove_default_themes'], 'theme', $warnings);

        $source = $section['source'] ?? '';

        if ($source === null) {
            $source = '';
        }

        if (!is_string($source)) {
            $errors[] = 'theme.source: must be a string - a WordPress.org slug, a ZIP URL or a local ZIP path.';
            $source   = '';
        } elseif ($source !== '') {
            $resolved = $this->resolveArchiveSource($source, 'theme.source', $errors);
            $source   = $resolved ?? $source;
        }

        $activate             = $this->bool($section, 'activate', true, 'theme', $errors);
        $removeDefaultThemes  = $this->bool($section, 'remove_default_themes', true, 'theme', $errors);

        if ($source === '' && $activate && $removeDefaultThemes) {
            $warnings[] = 'theme.source is empty: no theme will be installed, and every theme except the currently '
                . 'active one will be removed. Set theme.source to install a specific theme.';
        }

        return [
            'source'                => $source,
            'activate'              => $activate,
            'remove_default_themes' => $removeDefaultThemes,
        ];
    }

    /**
     * Classify a plugin/theme source as URL, local archive or slug.
     * Local archives must exist; their absolute path is returned.
     *
     * @param string[] $errors
     * @return string|null Normalized value, or null when invalid (error recorded).
     */
    private function resolveArchiveSource(string $source, string $label, array &$errors): ?string
    {
        if (preg_match('#^https?://#i', $source)) {
            if (filter_var($source, FILTER_VALIDATE_URL) === false) {
                $errors[] = sprintf('%s: "%s" is not a valid URL.', $label, $this->printable($source));

                return null;
            }

            return $source;
        }

        $looksLikeFile = strpos($source, '/') !== false
            || strpos($source, '\\') !== false
            || preg_match('/\.zip$/i', $source);

        if (!$looksLikeFile) {
            if (!preg_match(self::SLUG_PATTERN, $source)) {
                $errors[] = sprintf(
                    '%s: "%s" is not a valid slug (use a WordPress.org slug, an http(s) URL or a .zip path).',
                    $label,
                    $this->printable($source)
                );

                return null;
            }

            return $source;
        }

        $path = Filesystem::resolve($source, $this->projectRoot);

        if (!is_file($path)) {
            $errors[] = sprintf('%s: file not found: %s', $label, $path);

            return null;
        }

        if (strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) !== 'zip') {
            $errors[] = sprintf('%s: "%s" is not a .zip archive.', $label, $path);

            return null;
        }

        return $path;
    }

    /**
     * Extract a boolean with default and type checking.
     *
     * @param string[] $errors
     */
    private function bool(array $section, string $key, bool $default, string $label, array &$errors): bool
    {
        if (!array_key_exists($key, $section)) {
            return $default;
        }

        $value = $section[$key];

        if (is_bool($value)) {
            return $value;
        }

        if ($value === 0 || $value === 1) {
            return (bool) $value;
        }

        $errors[] = sprintf('%s.%s: must be true or false.', $label, $key);

        return $default;
    }

    /**
     * Return a section as an array, recording an error for wrong types.
     *
     * @param string[] $errors
     */
    private function section(array $config, string $key, array &$errors): array
    {
        if (!array_key_exists($key, $config)) {
            $errors[] = $key . ': required configuration section is missing (see config/setup.example.json).';

            return [];
        }

        if (!is_array($config[$key])) {
            $errors[] = $key . ': must be a JSON object (see config/setup.example.json).';

            return [];
        }

        return $config[$key];
    }

    /**
     * Warn about keys that are not part of the documented schema - usually typos.
     *
     * @param string[] $known
     * @param string[] $warnings
     */
    private function checkUnknownKeys(array $section, array $known, string $prefix, array &$warnings): void
    {
        foreach (array_keys($section) as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            $label = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $warnings[] = $label . ': unknown key - it will be ignored.';
        }
    }

    /**
     * Render arbitrary values safely for error messages (never prints secrets
     * because callers only pass non-sensitive fields here).
     */
    private function printable($value): string
    {
        if (is_scalar($value) || $value === null) {
            $string = (string) $value;

            return strlen($string) > 60 ? substr($string, 0, 57) . '...' : $string;
        }

        return gettype($value);
    }
}
