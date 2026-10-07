<?php

declare(strict_types=1);

namespace WpEnvironment\Support;

/**
 * Outcome of a single WP-CLI child process invocation.
 */
final class CommandResult
{
    private int $exitCode;
    private string $stdout;
    private string $stderr;
    private string $commandLine;
    private bool $executed;

    public function __construct(int $exitCode, string $stdout, string $stderr, string $commandLine, bool $executed)
    {
        $this->exitCode    = $exitCode;
        $this->stdout      = $stdout;
        $this->stderr      = $stderr;
        $this->commandLine = $commandLine;
        $this->executed    = $executed;
    }

    public function getExitCode(): int
    {
        return $this->exitCode;
    }

    /**
     * Whether the command did NOT fail.
     *
     * A command skipped in dry-run mode counts as ok (it certainly did not
     * fail) - otherwise every guard such as runOrFail() would abort a dry run.
     * Use wasExecuted() when the distinction matters.
     */
    public function isOk(): bool
    {
        return $this->exitCode === 0;
    }

    /**
     * Whether the process was actually started (false in dry-run mode).
     */
    public function wasExecuted(): bool
    {
        return $this->executed;
    }

    public function getStdout(): string
    {
        return $this->stdout;
    }

    /**
     * Combined trimmed output, stdout first, for error reports.
     */
    public function getOutput(): string
    {
        $parts = [];

        foreach ([$this->stdout, $this->stderr] as $part) {
            $part = trim($part);

            if ($part !== '') {
                $parts[] = $part;
            }
        }

        return implode("\n", $parts);
    }

    /**
     * Human readable (secret-redacted) representation of the command.
     */
    public function getCommandLine(): string
    {
        return $this->commandLine;
    }
}
