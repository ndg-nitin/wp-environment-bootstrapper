<?php
/**
 * WP Environment - WP-CLI entry point.
 *
 * Primary command:
 *
 *   wp --require=setup.php setup
 *
 * Options:
 *
 *   --dry-run          Show what would happen without changing anything.
 *   --config=<path>    Use a specific configuration file.
 *   --skip-plugins     Skip plugin installation and removal.
 *   --skip-theme       Skip theme installation and activation.
 *
 * @package WpEnvironment
 */

declare(strict_types=1);

use WpEnvironment\Commands\SetupCommand;

if (!defined('WP_CLI') || !WP_CLI) {
    fwrite(STDERR, 'This file must be run through WP-CLI, for example: wp --require=setup.php setup' . PHP_EOL);
    exit(1);
}

/*
 * Prefer Composer's autoloader when available; otherwise fall back to a
 * minimal PSR-4 loader so the tool also works on a fresh clone without
 * running `composer install` first.
 */
$composerAutoload = __DIR__ . '/vendor/autoload.php';

if (is_file($composerAutoload)) {
    require $composerAutoload;
} else {
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WpEnvironment\\';

        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file     = __DIR__ . '/src/' . str_replace('\\', '/', $relative) . '.php';

        if (is_file($file)) {
            require $file;
        }
    });
}

WP_CLI::add_command('setup', SetupCommand::class, [
    'when'      => 'before_wp_load',
    'shortdesc' => 'Create or update a reproducible WordPress environment from a JSON configuration file.',
    'synopsis'  => [
        [
            'type'        => 'assoc',
            'name'        => 'config',
            'description' => 'Path to the configuration file. Defaults to config/setup.json (falls back to legacy ./setup.json).',
            'optional'    => true,
        ],
        [
            'type'        => 'flag',
            'name'        => 'dry-run',
            'description' => 'Validate the environment and configuration, then print the plan without changing anything.',
            'optional'    => true,
        ],
        [
            'type'        => 'flag',
            'name'        => 'skip-plugins',
            'description' => 'Skip plugin installation, activation and removal.',
            'optional'    => true,
        ],
        [
            'type'        => 'flag',
            'name'        => 'skip-theme',
            'description' => 'Skip theme installation, activation and default theme cleanup.',
            'optional'    => true,
        ],
    ],
]);
