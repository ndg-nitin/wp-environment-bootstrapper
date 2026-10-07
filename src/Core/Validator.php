<?php

declare(strict_types=1);

namespace WpEnvironment\Core;

use WpEnvironment\Support\CommandRunner;
use WpEnvironment\WordPress\Database;

/**
 * Runtime environment checks performed before anything is changed:
 *
 *   - PHP version
 *   - WP-CLI availability (`wp --info`)
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
    public function check(Database $database): array
    {
        return [
            $this->checkPhp(),
            $this->checkWpCli(),
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
