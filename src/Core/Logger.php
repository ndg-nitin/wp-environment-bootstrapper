<?php

declare(strict_types=1);

namespace WpEnvironment\Core;

/**
 * Leveled console logger.
 *
 * Output format:
 *
 *   [INFO] Checking environment...
 *   [SUCCESS] WP-CLI detected.
 *   [WARNING] Checksums unavailable.
 *   [ERROR] Database creation failed.
 *
 * INFO/SUCCESS/WARNING go to STDOUT so a piped run keeps a linear narrative.
 * ERROR goes to STDERR, following normal CLI conventions.
 */
final class Logger
{
    private const COLOR_RESET  = "\033[0m";
    private const COLOR_BOLD   = "\033[1m";
    private const COLOR_INFO   = "\033[36m"; // cyan
    private const COLOR_OK     = "\033[32m"; // green
    private const COLOR_WARN   = "\033[33m"; // yellow
    private const COLOR_ERROR  = "\033[31m"; // red

    /** @var bool */
    private $color;

    public function __construct(?bool $color = null)
    {
        $this->color = $color ?? self::supportsColor();
    }

    public function info(string $message): void
    {
        $this->write(STDOUT, 'INFO', $message, self::COLOR_INFO);
    }

    public function success(string $message): void
    {
        $this->write(STDOUT, 'SUCCESS', $message, self::COLOR_OK);
    }

    public function warning(string $message): void
    {
        $this->write(STDOUT, 'WARNING', $message, self::COLOR_WARN);
    }

    public function error(string $message): void
    {
        $this->write(STDERR, 'ERROR', $message, self::COLOR_ERROR);
    }

    /**
     * Print a raw line with no level prefix.
     */
    public function plain(string $message = ''): void
    {
        fwrite(STDOUT, $message . PHP_EOL);
    }

    /**
     * Print a bold section heading.
     */
    public function heading(string $message): void
    {
        $text = $this->color ? self::COLOR_BOLD . $message . self::COLOR_RESET : $message;
        fwrite(STDOUT, $text . PHP_EOL);
    }

    /**
     * Print a horizontal rule.
     */
    public function separator(): void
    {
        fwrite(STDOUT, str_repeat('─', 40) . PHP_EOL);
    }

    /**
     * Print a blank line.
     */
    public function blank(): void
    {
        fwrite(STDOUT, PHP_EOL);
    }

    private function write($stream, string $level, string $message, string $color): void
    {
        if ($this->color) {
            $line = $color . sprintf('[%s]', $level) . self::COLOR_RESET . ' ' . $message;
        } else {
            $line = sprintf('[%s] %s', $level, $message);
        }

        fwrite($stream, $line . PHP_EOL);
    }

    private static function supportsColor(): bool
    {
        if (getenv('NO_COLOR') !== false) {
            return false;
        }

        if (getenv('TERM') === 'dumb') {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return stream_isatty(STDOUT);
        }

        return function_exists('posix_isatty') && @posix_isatty(STDOUT);
    }
}
