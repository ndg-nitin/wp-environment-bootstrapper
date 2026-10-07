<?php
/**
 * Lightweight test runner (no framework, no external dependencies).
 *
 * Usage:
 *   php tests/run.php              # run everything
 *   php tests/run.php Config       # run test files matching "Config"
 *
 * Exit code 0 = all passed, 1 = failures.
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        $prefix = 'WpEnvironment\\';
        if (strpos($class, $prefix) !== 0) {
            return;
        }
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($prefix)));
        $file = $root . '/src/' . $relative . '.php';
        if (is_file($file)) {
            require $file;
        }
    });
}

error_reporting(E_ALL);
ini_set('display_errors', '1');

final class TestRunner
{
    /** @var array<string,int> */
    private array $counts = ['pass' => 0, 'fail' => 0];
    /** @var string[] */
    private array $failures = [];
    private string $current = '';

    public function suite(string $name): void
    {
        $this->current = $name;
        echo "\n== {$name} ==\n";
    }

    public function assertTrue(bool $condition, string $message): void
    {
        if ($condition) {
            $this->counts['pass']++;
            echo "  ok   {$message}\n";
            return;
        }
        $this->counts['fail']++;
        $this->failures[] = "[{$this->current}] {$message}";
        echo "  FAIL {$message}\n";
    }

    public function assertSame($expected, $actual, string $message): void
    {
        $ok = $expected === $actual;
        $this->assertTrue($ok, $message);
        if (!$ok) {
            echo '       expected: ' . var_export($expected, true) . "\n";
            echo '       actual:   ' . var_export($actual, true) . "\n";
        }
    }

    public function assertContains(string $needle, string $haystack, string $message): void
    {
        $this->assertTrue(strpos($haystack, $needle) !== false, $message);
    }

    public function assertNotContains(string $needle, string $haystack, string $message): void
    {
        $this->assertTrue(strpos($haystack, $needle) === false, $message);
    }

    /**
     * @param class-string<Throwable> $class
     */
    public function assertThrows(string $class, callable $callback, string $message, ?string $messageContains = null): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if (!($e instanceof $class)) {
                $this->counts['fail']++;
                $this->failures[] = "[{$this->current}] {$message} (threw " . get_class($e) . ')';
                echo "  FAIL {$message} (threw " . get_class($e) . ": {$e->getMessage()})\n";
                return;
            }
            if ($messageContains !== null && stripos($e->getMessage(), $messageContains) === false) {
                $this->counts['fail']++;
                $this->failures[] = "[{$this->current}] {$message} (message missing '{$messageContains}')";
                echo "  FAIL {$message} — exception message was: {$e->getMessage()}\n";
                return;
            }
            $this->counts['pass']++;
            echo "  ok   {$message}\n";
            return;
        }
        $this->counts['fail']++;
        $this->failures[] = "[{$this->current}] {$message} (nothing thrown)";
        echo "  FAIL {$message} (no exception thrown)\n";
    }

    public function summary(): int
    {
        echo "\n----------------------------------------\n";
        echo sprintf("Passed: %d   Failed: %d\n", $this->counts['pass'], $this->counts['fail']);
        if ($this->failures) {
            echo "\nFailures:\n";
            foreach ($this->failures as $failure) {
                echo "  - {$failure}\n";
            }
        }
        return $this->counts['fail'] > 0 ? 1 : 0;
    }
}

$t = new TestRunner();
$filter = $argv[1] ?? null;
$files = glob(__DIR__ . '/cases/*Test.php') ?: [];
sort($files);

foreach ($files as $file) {
    if ($filter !== null && stripos(basename($file), $filter) === false) {
        continue;
    }
    require $file;
}

exit($t->summary());
