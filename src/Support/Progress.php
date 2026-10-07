<?php

declare(strict_types=1);

namespace WpEnvironment\Support;

use WpEnvironment\Core\Logger;

/**
 * Renders the structured setup report (sections, check marks, +/- plans).
 *
 * Example output:
 *
 *   Environment checks
 *     ✓ PHP 8.1.2
 *     ✓ WP-CLI 2.8.1
 *
 *   Plugins to install
 *     + elementor
 *     - hello
 */
final class Progress
{
    /** @var Logger */
    private $logger;

    public function __construct(Logger $logger)
    {
        $this->logger = $logger;
    }

    /**
     * Print a report section heading on its own line.
     */
    public function section(string $title): void
    {
        $this->logger->plain();
        $this->logger->heading($title);
    }

    /**
     * Print a passed/failed check line.
     */
    public function check(string $label, bool $ok, string $note = ''): void
    {
        $mark   = $ok ? '✓' : '✗';
        $suffix = $note !== '' ? ' — ' . $note : '';

        $this->logger->plain('  ' . $mark . ' ' . $label . $suffix);
    }

    /**
     * Print a planned/added item ("+ elementor").
     */
    public function add(string $label): void
    {
        $this->logger->plain('  + ' . $label);
    }

    /**
     * Print a removal item ("- hello").
     */
    public function remove(string $label): void
    {
        $this->logger->plain('  - ' . $label);
    }

    /**
     * Print an indented note line.
     */
    public function note(string $label): void
    {
        $this->logger->plain('    ' . $label);
    }

    /**
     * Print an indented "Key: value" line.
     *
     * @param bool $pad Pad the key to a fixed width (used in summaries).
     */
    public function kv(string $key, string $value, bool $pad = false): void
    {
        if ($pad) {
            $this->logger->plain('  ' . str_pad($key . ':', 18, ' ', STR_PAD_RIGHT) . $value);

            return;
        }

        $this->logger->plain('  ' . $key . ': ' . $value);
    }
}
