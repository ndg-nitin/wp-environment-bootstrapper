<?php

declare(strict_types=1);

namespace WpEnvironment\Core;

use WpEnvironment\Support\CommandRunner;
use WpEnvironment\Support\Filesystem;
use WpEnvironment\WordPress\Database;

/**
 * Runtime environment checks performed before anything is changed:
 *
 *   - PHP version
 *   - WP-CLI availability (`wp --info`)
 *   - writability of the installation path
 *   - MySQL/MariaDB connectivity with the configured credentials
 *
 * Every check yields a structured result:
 *
 *   ['status' => 'ok'|'warn'|'fail', 'detail' => string, 'hints' => string[]]
 *
 * 'fail' is fatal for setup; 'warn' is informational.
 */
final class Validator
{
    /** @var CommandRunner */
    private $runner;

    public function __construct(CommandRunner $runner)
    {
        $this->runner = $runner;
    }

    /**
     * @return array<int, array{label:string, status:string, detail:string, hints:string[]}>
     */
    public function check(Database $database, string $installPath): array
    {
        return [
            $this->checkPhp(),
            $this->checkWpCli(),
            $this->checkInstallPath($installPath),
            $this->checkDatabase($database),
        ];
    }

    /**
     * @return array{label:string, status:string, detail:string, hints:string[]}
     */
    private function checkPhp(): array
    {
        $supported = version_compare(PHP_VERSION, '7.4.0', '>=');

        return [
            'label'  => 'PHP',
            'status' => $supported ? 'ok' : 'fail',
            'detail' => PHP_VERSION,
            'hints'  => $supported ? [] : [
                'WP Environment requires PHP 7.4 or newer; this is PHP ' . PHP_VERSION . '.',
                'Upgrade the PHP CLI version that runs WP-CLI.',
            ],
        ];
    }

    /**
     * @return array{label:string, status:string, detail:string, hints:string[]}
     */
    private function checkWpCli(): array
    {
        $result = $this->runner->run(['--info'], ['readonly' => true]);

        if (!$result->isOk()) {
            return [
                'label'  => 'WP-CLI',
                'status' => 'fail',
                'detail' => 'not available',
                'hints'  => $this->runner->buildHints($result, [
                    'Install WP-CLI: https://wp-cli.org/#installing',
                    'Verify the installation with: wp --info',
                ]),
            ];
        }

        $version = 'unknown version';

        if (preg_match('/WP-CLI version:\s*(\S+)/', $result->getStdout(), $matches) === 1) {
            $version = $matches[1];
        }

        return [
            'label'  => 'WP-CLI',
            'status' => 'ok',
            'detail' => $version,
            'hints'  => [],
        ];
    }

    /**
     * Whether the target directory (or its nearest existing parent) can be
     * written by the current user - the most common cause of a failed
     * `wp core download` is a path nobody may write to.
     *
     * @return array{label:string, status:string, detail:string, hints:string[]}
     */
    private function checkInstallPath(string $path): array
    {
        $writable = Filesystem::isWritableAt($path);
        $exists   = file_exists($path);

        return [
            'label'  => 'Install path',
            'status' => $writable ? 'ok' : 'fail',
            'detail' => sprintf('%s (%s)', $path, $writable ? ($exists ? 'writable' : 'parent writable') : 'not writable'),
            'hints'  => $writable ? [] : [
                'WordPress cannot be written to: ' . $path,
                'Fix the ownership/permissions of that directory for the user running WP-CLI, or point wordpress.path somewhere writable.',
            ],
        ];
    }

    /**
     * @return array{label:string, status:string, detail:string, hints:string[]}
     */
    private function checkDatabase(Database $database): array
    {
        $preflight = $database->preflight();

        return [
            'label'  => 'Database',
            'status' => $preflight['status'],
            'detail' => $preflight['detail'],
            'hints'  => $preflight['hints'],
        ];
    }
}
