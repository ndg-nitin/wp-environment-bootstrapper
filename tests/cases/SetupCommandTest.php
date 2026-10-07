<?php
/**
 * SetupCommand option handling.
 *
 * WP-CLI reserves --skip-plugins as a global parameter: it is stripped from the
 * arguments handed to the command and only lives in the runner configuration.
 * The command must therefore read it back from there - this test pins that
 * behaviour with a stand-in runtime.
 */

declare(strict_types=1);

use WpEnvironment\Commands\SetupCommand;

$t->suite('SetupCommand: option handling');

if (!class_exists('WP_CLI', false)) {
    /**
     * Stand-in for the WP-CLI runtime. Mirrors the real Runner: the config
     * property is private and exposed through __get(), which is exactly what
     * `isset($runner->config)` gets wrong.
     */
    class WP_CLI
    {
        /** @var array<string, mixed> */
        public static $config = ['skip-plugins' => true];

        public static function get_runner()
        {
            return new class {
                public function __get($key)
                {
                    return $key === 'config' ? WP_CLI::$config : null;
                }
            };
        }
    }
}

$command = new SetupCommand();
$method  = new ReflectionMethod(SetupCommand::class, 'skipPluginsRequested');
$method->setAccessible(true);

WP_CLI::$config = ['skip-plugins' => true];
$t->assertTrue(
    $method->invoke($command, []),
    'the global --skip-plugins flag is read back from the WP-CLI runner configuration'
);
$t->assertTrue(
    $method->invoke($command, ['skip-plugins' => true]),
    'an explicit --skip-plugins argument is honoured too'
);

WP_CLI::$config = ['skip-plugins' => false];
$t->assertTrue(
    !$method->invoke($command, []),
    'plugin steps run when the flag was not passed'
);

WP_CLI::$config = ['skip-themes' => true];
$t->assertTrue(
    !$method->invoke($command, []),
    'unrelated runner configuration never skips plugin steps'
);

// The synopsis is what makes WP-CLI accept the flags at all.
$setupPhp = (string) file_get_contents(dirname(__DIR__, 2) . '/setup.php');

foreach (['skip-plugins', 'skip-theme', 'dry-run', 'config'] as $option) {
    $t->assertTrue(
        preg_match("/'name'\s*=>\s*'{$option}'/", $setupPhp) === 1,
        "option --{$option} is declared in the synopsis"
    );
}
