<?php

declare(strict_types=1);

namespace WpEnvironment\Support;

use WpEnvironment\Core\Logger;
use WpEnvironment\Core\SetupException;

/**
 * Central place where WP-CLI child processes are started.
 *
 * Design notes:
 *
 * - Every WP-CLI subcommand runs in its own process, so each invocation gets a
 *   clean WP-CLI/WordPress bootstrap (exactly as if the user typed it), and a
 *   failing subcommand can never corrupt the state of the parent process.
 * - Commands are passed to proc_open() as an ARGUMENT ARRAY (PHP 7.4+), so no
 *   shell is involved: spaces, quotes, dollar signs and Windows paths in
 *   values never need escaping and cannot cause shell injection.
 * - Registered secrets (database password, ACF Pro key) are redacted from all
 *   captured output and from every displayed command line.
 * - In dry-run mode nothing executes unless a call is explicitly marked as
 *   read-only (environment checks), which makes accidental changes impossible.
 */
final class CommandRunner
{
    /** @var Logger */
    private $logger;

    /** @var bool */
    private $dryRun;

    /** @var string[] */
    private $secrets = [];

    /** @var string[] */
    private $history = [];

    /** @var string|null */
    private $wpScript;

    public function __construct(Logger $logger, bool $dryRun = false)
    {
        $this->logger  = $logger;
        $this->dryRun  = $dryRun;
    }

    /**
     * Register a value that must never appear in output or error reports.
     */
    public function addSecret(?string $secret): void
    {
        if ($secret !== null && $secret !== '' && !in_array($secret, $this->secrets, true)) {
            $this->secrets[] = $secret;
        }
    }

    /**
     * Commands executed (or skipped, in dry-run mode), redacted for display.
     *
     * @return string[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Run a WP-CLI subcommand in a child process.
     *
     * @param string[] $args         WP-CLI arguments, e.g. ['core', 'download', '--path=/tmp/wp'].
     * @param array    $options      Options: 'readonly' => true allows execution during dry-run.
     * @return CommandResult
     */
    public function run(array $args, array $options = []): CommandResult
    {
        $display = $this->display($args);
        $readonly = !empty($options['readonly']);

        if ($this->dryRun && !$readonly) {
            $this->history[] = $display;
            $this->logger->info('Would run: ' . $display);

            return new CommandResult(0, '', '', $display, false);
        }

        $this->history[] = $display;

        $script = $this->resolveWpScript();

        if ($script === null) {
            return new CommandResult(
                1,
                '',
                'Unable to locate the WP-CLI executable. Install WP-CLI (https://wp-cli.org/#installing) '
                . 'or run this command through the same `wp` binary that started the setup.',
                $display,
                false
            );
        }

        $command = array_merge([PHP_BINARY, $script], array_values($args));
        [$exitCode, $stdout, $stderr] = $this->execute($command);

        return new CommandResult(
            $exitCode,
            $this->redact($stdout),
            $this->redact($stderr),
            $display,
            true
        );
    }

    /**
     * Run a WP-CLI subcommand and throw a rich SetupException when it fails.
     *
     * @param string[] $args           WP-CLI arguments.
     * @param string   $failureMessage What failed, in user language.
     * @param string[] $hints          Extra checks for the user (command output is appended automatically).
     * @param array    $options        Passed through to run().
     * @throws \WpEnvironment\Core\SetupException
     * @return CommandResult
     */
    public function runOrFail(array $args, string $failureMessage, array $hints = [], array $options = []): CommandResult
    {
        $result = $this->run($args, $options);

        if ($result->isOk()) {
            return $result;
        }

        throw new SetupException($failureMessage, $this->buildHints($result, $hints));
    }

    /**
     * Build the "hints" block for a failed CommandResult: the redacted command
     * plus the most recent lines WP-CLI produced.
     *
     * @param string[] $hints Extra user-facing checks.
     * @return string[]
     */
    public function buildHints(CommandResult $result, array $hints = []): array
    {
        if ($result->getCommandLine() !== '') {
            $hints[] = 'Command: ' . $result->getCommandLine();
        }

        $output = $result->getOutput();

        if ($output !== '') {
            foreach (array_slice(explode("\n", $output), -8) as $line) {
                $hints[] = 'WP-CLI said: ' . trim($line);
            }
        }

        return $hints;
    }

    /**
     * Human readable, redacted command line (display only - never executed).
     *
     * @param string[] $args
     */
    public function display(array $args): string
    {
        $parts = ['wp'];

        foreach ($args as $arg) {
            $arg = (string) $arg;

            if ($arg !== '' && preg_match('/[\s"\'\\\\]/', $arg)) {
                $arg = "'" . str_replace("'", "'\\''", $arg) . "'";
            }

            $parts[] = $arg;
        }

        return $this->redact(implode(' ', $parts));
    }

    /**
     * Replace every registered secret in a block of text.
     */
    public function redact(string $text): string
    {
        if ($text === '' || $this->secrets === []) {
            return $text;
        }

        return str_replace($this->secrets, '[redacted]', $text);
    }

    /**
     * Resolve the WP-CLI script this process should call.
     *
     * Preference order:
     *
     *   1. The script we were started with, when its name says it is WP-CLI
     *      (`wp`, `wp.phar`, `wp-cli.phar`, `wp.bat`, ...) - so the exact
     *      binary the user invoked is reused.
     *   2. `wp` found on PATH - covers wrappers (`php my-wrapper.php`),
     *      `php -r` snippets and test runners whose own argv[0] is not WP-CLI.
     *   3. The script we were started with, when its contents identify it as
     *      WP-CLI (a renamed phar or wrapper).
     *
     * Returning null makes the caller report "install WP-CLI" instead of
     * silently executing an unrelated PHP file.
     */
    private function resolveWpScript(): ?string
    {
        if ($this->wpScript !== null) {
            return $this->wpScript;
        }

        $argv0 = (string) ($_SERVER['argv'][0] ?? '');

        if ($argv0 !== '' && preg_match('/^wp(?:-cli)?(?:\.(?:phar|bat|cmd))?$/i', basename(str_replace('\\', '/', $argv0))) === 1) {
            $real = realpath($argv0);

            if ($real !== false && is_file($real)) {
                return $this->wpScript = $real;
            }
        }

        $found = $this->findInPath('wp');

        if ($found !== null) {
            return $this->wpScript = $found;
        }

        if ($argv0 !== '') {
            $real = realpath($argv0);

            if ($real !== false && is_file($real)) {
                $head = (string) @file_get_contents($real, false, null, 0, 65536);

                if (strpos($head, 'WP-CLI') !== false) {
                    return $this->wpScript = $real;
                }
            }
        }

        return $this->wpScript = null;
    }

    /**
     * Search the PATH environment variable for an executable.
     */
    private function findInPath(string $name): ?string
    {
        $path = getenv('PATH');

        if ($path === false || $path === '') {
            return null;
        }

        $extensions = [''];

        if (DIRECTORY_SEPARATOR === '\\') {
            $extensions = ['.bat', '.cmd', '.exe', ''];
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '') {
                continue;
            }

            foreach ($extensions as $extension) {
                $candidate = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . $name . $extension;

                if (is_file($candidate) && is_executable($candidate)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /**
     * Spawn a process from an argument array (no shell) and capture output.
     *
     * @param string[] $command
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private function execute(array $command): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $pipes  = [];
        $proc   = @proc_open($command, $descriptors, $pipes);

        if (!is_resource($proc)) {
            return [1, '', 'Failed to start the WP-CLI process.'];
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout   = '';
        $stderr   = '';
        $exitCode = -1;

        while (true) {
            $read = [];

            if (!feof($pipes[1])) {
                $read[] = $pipes[1];
            }

            if (!feof($pipes[2])) {
                $read[] = $pipes[2];
            }

            if ($read !== []) {
                $write  = null;
                $except = null;
                @stream_select($read, $write, $except, 1, 0);
            }

            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($proc);

            if (!$status['running']) {
                // The real exit code is only available from the first
                // proc_get_status() call after the process has ended.
                $exitCode = $status['exitcode'];

                $stdout .= (string) stream_get_contents($pipes[1]);
                $stderr .= (string) stream_get_contents($pipes[2]);
                break;
            }
        }

        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return [$exitCode, $stdout, $stderr];
    }
}
