<?php

declare(strict_types=1);

namespace WpEnvironment\Commands;

use Throwable;
use WP_CLI;
use WpEnvironment\Config\ConfigLoader;
use WpEnvironment\Config\ConfigValidator;
use WpEnvironment\Core\Environment;
use WpEnvironment\Core\Logger;
use WpEnvironment\Core\SetupException;

/**
 * The `wp setup` command (registered by setup.php).
 *
 * Supported options:
 *
 *   --dry-run          Validate and print the plan; change nothing.
 *   --config=<path>    Use an explicit configuration file.
 *   --skip-plugins     Skip plugin installation/activation/removal.
 *   --skip-theme       Skip theme installation/activation/cleanup.
 */
final class SetupCommand
{
    /**
     * @param string[] $args       Positional arguments (unused).
     * @param array    $assocArgs  Parsed command options.
     */
    public function __invoke(array $args, array $assocArgs): void
    {
        $logger  = new Logger();
        $dryRun  = !empty($assocArgs['dry-run']);
        $configPath = isset($assocArgs['config']) ? (string) $assocArgs['config'] : null;

        try {
            // 1. Load configuration + secrets.
            $loaded = (new ConfigLoader())->load($configPath);

            foreach ($loaded['warnings'] as $warning) {
                $logger->warning($warning);
            }

            // 2. Validate the schema and normalize values.
            $result = (new ConfigValidator())->validate($loaded['config'], $loaded['secrets']);

            foreach ($result['warnings'] as $warning) {
                $logger->warning($warning);
            }

            if ($result['errors'] !== []) {
                throw new SetupException(
                    sprintf('Configuration is invalid (%d error(s)).', count($result['errors'])),
                    array_merge($result['errors'], [
                        'Template: config/setup.example.json',
                        'Full documentation: README.md (section "Configuration").',
                    ])
                );
            }

            // 3. Execute, or print the plan for --dry-run.
            $environment = new Environment($result['config'], $loaded['secrets'], $logger, $dryRun);

            $flags = [
                'skip_plugins' => $this->skipPluginsRequested($assocArgs),
                'skip_theme'   => !empty($assocArgs['skip-theme']),
            ];

            if ($dryRun) {
                $environment->dryRun($flags);
            } else {
                $environment->run($flags);
            }
        } catch (SetupException $exception) {
            $logger->error($exception->getMessage());

            foreach ($exception->getHints() as $hint) {
                $logger->error('  -> ' . $hint);
            }

            $logger->blank();
            WP_CLI::halt(1);
        } catch (Throwable $exception) {
            $logger->error('Unexpected error: ' . get_class($exception) . ': ' . $exception->getMessage());
            $logger->error('  -> ' . $exception->getFile() . ':' . $exception->getLine());
            $logger->error('  -> If this looks like a bug, please report it with the command you ran.');
            $logger->blank();
            WP_CLI::halt(1);
        }
    }

    /**
     * Whether the user asked to skip plugin steps.
     *
     * WP-CLI reserves --skip-plugins as a global parameter: it is consumed
     * during bootstrap and stripped from the arguments handed to the command,
     * so it must be read back from the runner configuration. The value keeps
     * its documented meaning here (skip plugin installation/removal), which
     * matches what the global flag already did to the bootstrap.
     *
     * @param array $assocArgs Parsed command options.
     */
    private function skipPluginsRequested(array $assocArgs): bool
    {
        if (!empty($assocArgs['skip-plugins'])) {
            return true;
        }

        try {
            // Runner::$config is private and exposed through __get(), so it must
            // be read directly - isset() on the property would always be false.
            $runner = WP_CLI::get_runner();
            $config = is_object($runner) ? $runner->config : null;
        } catch (Throwable $exception) {
            $config = null;
        }

        return is_array($config) && !empty($config['skip-plugins']);
    }
}
