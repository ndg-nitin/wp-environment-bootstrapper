<?php

declare(strict_types=1);

namespace WpEnvironment\Core;

use RuntimeException;
use Throwable;

/**
 * A failure that stops the setup process.
 *
 * Carries a human readable message plus "hints" that tell the user what to
 * check, so every fatal error answers three questions:
 *
 *   1. What failed?
 *   2. Why did it fail (when known)?
 *   3. What should the user check next?
 */
final class SetupException extends RuntimeException
{
    /** @var string[] */
    private $hints;

    /**
     * @param string[] $hints Actionable follow-up checks for the user.
     */
    public function __construct(string $message, array $hints = [], ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);

        $this->hints = $hints;
    }

    /**
     * @return string[]
     */
    public function getHints(): array
    {
        return $this->hints;
    }
}
