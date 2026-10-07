<?php

declare(strict_types=1);

namespace WpEnvironment\WordPress;

use WpEnvironment\Core\Logger;
use WpEnvironment\Core\SetupException;
use WpEnvironment\Support\CommandRunner;

/**
 * Database pre-flight checks and database creation.
 *
 * Credentials are taken exclusively from the configuration - nothing is
 * hardcoded. The password is never included in messages; it is registered
 * with the CommandRunner so it is redacted from any command output.
 */
final class Database
{
    /** @var array{name:string,user:string,password:string,host:string,prefix:string} */
    private $db;

    /** @var string */
    private $installPath;

    /** @var CommandRunner */
    private $runner;

    /** @var Logger */
    private $logger;

    /**
     * @param array{name:string,user:string,password:string,host:string,prefix:string} $db
     */
    public function __construct(array $db, string $installPath, CommandRunner $runner, Logger $logger)
    {
        $this->db          = $db;
        $this->installPath = $installPath;
        $this->runner      = $runner;
        $this->logger      = $logger;
    }

    /**
     * Pre-flight connection check using PHP's mysqli extension.
     *
     * Read-only: opens a connection and asks which schemas exist, so it is
     * safe to run in dry-run mode.
     *
     * @return array{status:'ok'|'warn'|'fail', detail:string, hints:string[]}
     */
    public function preflight(): array
    {
        if (!extension_loaded('mysqli')) {
            return [
                'status' => 'warn',
                'detail' => 'mysqli extension not available - pre-flight check skipped',
                'hints'  => ['Enable the mysqli PHP extension to validate database credentials before setup runs.'],
            ];
        }

        // PHP 8.1+ throws by default; WordPress/WP-CLI manage errors themselves.
        if (function_exists('mysqli_report')) {
            mysqli_report(MYSQLI_REPORT_OFF);
        }

        [$host, $port, $socket] = $this->parseHost($this->db['host']);

        $connection = @mysqli_connect(
            $host,
            $this->db['user'],
            $this->db['password'],
            '',
            $port,
            $socket
        );

        if ($connection === false) {
            $error = (string) mysqli_connect_error();

            return [
                'status' => 'fail',
                'detail' => 'connection failed',
                'hints'  => array_merge(
                    [
                        sprintf('Database: %s', $this->db['name']),
                        sprintf('Host: %s', $this->db['host']),
                        sprintf('User: %s', $this->db['user']),
                        'Verify the database credentials and ensure MySQL/MariaDB is running.',
                    ],
                    $this->classifyConnectError($error)
                ),
            ];
        }

        $server = mysqli_get_server_info($connection);

        $exists = false;

        if ($this->db['name'] !== '') {
            $stmt = @mysqli_prepare(
                $connection,
                'SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = ?'
            );

            if ($stmt !== false) {
                mysqli_stmt_bind_param($stmt, 's', $this->db['name']);
                mysqli_stmt_execute($stmt);
                mysqli_stmt_store_result($stmt);
                $exists = mysqli_stmt_num_rows($stmt) > 0;
                mysqli_stmt_close($stmt);
            }
        }

        mysqli_close($connection);

        $detail = sprintf(
            '%s - database "%s" %s',
            $server !== '' ? $server : 'MySQL/MariaDB',
            $this->db['name'],
            $exists ? 'exists' : 'will be created'
        );

        return ['status' => 'ok', 'detail' => $detail, 'hints' => []];
    }

    /**
     * Create the configured database via `wp db create`.
     *
     * Idempotent: an already-existing database is not an error.
     *
     * @throws SetupException When creation fails for any other reason.
     * @return bool True when the database was created, false when it already existed.
     */
    public function create(): bool
    {
        $this->logger->info(sprintf('Creating database "%s"...', $this->db['name']));

        $result = $this->runner->run([
            'db',
            'create',
            '--path=' . $this->installPath,
        ]);

        if ($result->isOk()) {
            $this->logger->success(sprintf('Database "%s" created.', $this->db['name']));

            return true;
        }

        // MySQL/MariaDB word this differently: "already exists" (MariaDB),
        // "database exists" (MySQL 8, error 1007).
        if (preg_match('/already\s+exists|database\s+exists|ERROR\s*1007/i', $result->getOutput()) === 1) {
            $this->logger->success(sprintf('Database "%s" already exists - nothing to do.', $this->db['name']));

            return false;
        }

        throw new SetupException(
            'Database creation failed.',
            $this->runner->buildHints($result, [
                sprintf('Database: %s', $this->db['name']),
                sprintf('Host: %s', $this->db['host']),
                sprintf('User: %s', $this->db['user']),
                'Verify your database credentials and ensure MySQL/MariaDB is running.',
                'The database user needs the CREATE privilege for a new database.',
            ])
        );
    }

    /**
     * Split a MySQL host string into mysqli parts.
     *
     * Supported forms: "localhost", "127.0.0.1", "host:3307", "host:/tmp/mysql.sock",
     * "/tmp/mysql.sock", "::1" (IPv6, no explicit port).
     *
     * @return array{0:string,1:int,2:?string} host, port, socket
     */
    private function parseHost(string $host): array
    {
        $defaultPort = (int) (ini_get('mysqli.default_port') ?: 3306);

        if ($host !== '' && $host[0] === '/') {
            return ['localhost', $defaultPort, $host];
        }

        if (substr_count($host, ':') === 1) {
            [$name, $tail] = explode(':', $host, 2);

            if ($tail !== '' && ctype_digit($tail)) {
                return [$name, (int) $tail, null];
            }

            if ($tail !== '' && $tail[0] === '/') {
                return ['localhost', $defaultPort, $tail];
            }
        }

        return [$host, $defaultPort, null];
    }

    /**
     * Turn a mysqli error string into actionable hints.
     *
     * @return string[]
     */
    private function classifyConnectError(string $error): array
    {
        if ($error === '') {
            return ['No further error details were reported by mysqli.'];
        }

        $hints = ['mysqli said: ' . $error];

        if (stripos($error, 'Access denied') !== false) {
            $hints[] = 'Check database.user and database.password in your configuration.';
        }

        if (stripos($error, 'Unknown MySQL server host') !== false
            || stripos($error, "Can't connect") !== false
            || stripos($error, 'Connection refused') !== false
            || stripos($error, 'No such file or directory') !== false
        ) {
            $hints[] = 'Check database.host (and port) and make sure the MySQL/MariaDB server is running.';
        }

        return $hints;
    }
}
