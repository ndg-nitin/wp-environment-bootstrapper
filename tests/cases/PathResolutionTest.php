<?php
/**
 * WordPress installation path resolution.
 *
 * The bootstrapper repository is the *tool* directory, so a relative
 * `wordpress.path` resolves against the directory that contains it - projects
 * are created as siblings of the repository, never inside it. Absolute paths
 * are used exactly as configured.
 */

declare(strict_types=1);

use WpEnvironment\Config\ConfigValidator;
use WpEnvironment\Support\Filesystem;

/**
 * A configuration that is valid apart from wordpress.path.
 */
$validConfig = static function (string $path): array {
    return [
        'wordpress' => ['version' => '6.8.2', 'path' => $path],
        'database'  => [
            'name'     => 'wp_path_test',
            'user'     => 'root',
            'password' => '',
            'host'     => 'localhost',
            'prefix'   => 'wp_',
        ],
        'site'      => ['url' => 'http://localhost/site', 'title' => 'Path Test Site'],
        'admin'     => [
            'username' => 'admin',
            'password' => 'Not-A-Placeholder-Password-1',
            'email'    => 'admin@example.com',
        ],
        'plugins'   => ['install' => [], 'remove' => [], 'strict' => true],
        'theme'     => ['source' => '', 'activate' => true, 'remove_default_themes' => true],
    ];
};

/**
 * Validate one wordpress.path against a bootstrapper installed at $toolRoot.
 */
$resolveFor = static function (string $path, string $toolRoot) use ($validConfig): array {
    return (new ConfigValidator($toolRoot))->validate($validConfig($path), []);
};

$t->suite('Path resolution: relative paths land NEXT TO the bootstrapper');

$toolRoot = '/var/www/html/wp-environment-bootstrapper';

$report = $resolveFor('project', $toolRoot);
$t->assertSame([], $report['errors'], 'relative "project" validates: ' . implode('; ', $report['errors']));
$t->assertSame(
    '/var/www/html/project',
    $report['config']['wordpress']['path'],
    'relative "project" resolves next to the bootstrapper'
);
$t->assertTrue(
    strpos($report['config']['wordpress']['path'], $toolRoot . '/') === false,
    'the install path is never .../wp-environment-bootstrapper/project'
);
$t->assertNotContains(
    'wp-environment-bootstrapper/project',
    $report['config']['wordpress']['path'],
    'the acceptance case "project" must not resolve inside the repository'
);

$t->assertSame(
    '/var/www/html/clients/project',
    $resolveFor('clients/project', $toolRoot)['config']['wordpress']['path'],
    'nested relative path resolves next to the bootstrapper'
);
$t->assertSame(
    '/var/www/html/wordpress',
    $resolveFor('./wordpress', $toolRoot)['config']['wordpress']['path'],
    '"./wordpress" is a sibling, not a folder of the tool'
);
$t->assertSame(
    '/var/www/html',
    $resolveFor('.', $toolRoot)['config']['wordpress']['path'],
    '"." is the sibling directory itself'
);
$t->assertSame(
    '/var/www/html/project',
    $resolveFor('project', $toolRoot . '/')['config']['wordpress']['path'],
    'a bootstrapper path with a trailing separator resolves identically'
);
$t->assertSame(
    '/home/user/tools/client-site',
    $resolveFor('client-site', '/home/user/tools/wp-environment-bootstrapper')['config']['wordpress']['path'],
    'the rule is generic: another bootstrapper location gives another sibling'
);
$t->assertSame(
    '/var/www/html/project',
    $resolveFor('project', '/var/www/html/wp-environment-bootstrapper/')['config']['wordpress']['path'],
    'the tool location in /var/www/html gives /var/www/html/project'
);

$t->suite('Path resolution: absolute paths are used as configured');

$t->assertSame(
    '/srv/wordpress',
    $resolveFor('/srv/wordpress', $toolRoot)['config']['wordpress']['path'],
    'an absolute path is used exactly as configured'
);
$t->assertSame(
    '/srv/wordpress',
    $resolveFor('/srv/sites/../wordpress', $toolRoot)['config']['wordpress']['path'],
    'an absolute path keeps its meaning, only normalised (no "." / ".." left)'
);
$t->assertSame(
    [],
    $resolveFor('/srv/wordpress', $toolRoot)['errors'],
    'an absolute path outside the repository raises no error'
);

$windowsPath = Filesystem::resolve('C:\\sites\\wp', $toolRoot);
$t->assertSame(
    'C:/sites/wp',
    str_replace('\\', '/', $windowsPath),
    'a Windows absolute path is not rebased onto the bootstrapper directory'
);
$t->assertTrue(Filesystem::isAbsolute($windowsPath), 'the resolved Windows path stays absolute');

$t->suite('Path resolution: installs inside the repository are rejected');

$inside = $resolveFor('/var/www/html/wp-environment-bootstrapper/wordpress', $toolRoot);
$t->assertTrue($inside['errors'] !== [], 'an absolute path inside the repository is an error');
$t->assertContains(
    'inside the bootstrapper repository',
    implode("\n", $inside['errors']),
    'the error explains that the repository is not an install target'
);
$t->assertContains(
    '/var/www/html/project',
    implode("\n", $inside['errors']),
    'the error suggests the sibling directory to use instead'
);

$insideRelative = $resolveFor('wp-environment-bootstrapper/wordpress', $toolRoot);
$t->assertTrue($insideRelative['errors'] !== [], 'a relative path that resolves inside is an error too');
$t->assertContains(
    'inside the bootstrapper repository',
    implode("\n", $insideRelative['errors']),
    'relative install inside the repository is explained'
);

$repositoryTarget = $resolveFor('wp-environment-bootstrapper', $toolRoot);
$t->assertTrue(
    $repositoryTarget['errors'] !== [],
    'naming the repository itself as wordpress.path is rejected'
);
$t->assertSame(
    $toolRoot,
    $repositoryTarget['config']['wordpress']['path'],
    'the repository itself resolves to the repository - and is rejected'
);

$parentTarget = $resolveFor('.', $toolRoot);
$t->assertSame(
    [],
    $parentTarget['errors'],
    'the directory containing the bootstrapper is a valid install target'
);
$t->assertSame(
    '/var/www/html',
    $parentTarget['config']['wordpress']['path'],
    'installing into the sibling directory itself is allowed'
);

$t->suite('Path resolution: the real repository resolves to its parent');

$realValidator = new ConfigValidator();
$realReport    = $realValidator->validate($validConfig('project'), []);
$repository    = Filesystem::projectRoot();

$t->assertSame(
    Filesystem::parentOf($repository) . DIRECTORY_SEPARATOR . 'project',
    $realReport['config']['wordpress']['path'],
    'for this checkout the project lands next to the repository'
);
$t->assertTrue(
    strpos($realReport['config']['wordpress']['path'], $repository . DIRECTORY_SEPARATOR) !== 0,
    'the real install path is outside the bootstrapper repository'
);
$t->assertSame(
    [],
    $realReport['errors'],
    'the example shape validates against the real repository: ' . implode('; ', $realReport['errors'])
);

$t->suite('Path resolution: Filesystem::parentOf');

$t->assertSame('/var/www/html', Filesystem::parentOf('/var/www/html/wp-environment-bootstrapper'), 'parent of a directory');
$t->assertSame('/var/www/html', Filesystem::parentOf('/var/www/html/wp-environment-bootstrapper/'), 'trailing separator is ignored');
$t->assertSame('/var/www/html', Filesystem::parentOf('/var/www/html/wp-environment-bootstrapper/.'), '"." is collapsed first');
$t->assertSame('/var/www/html', Filesystem::parentOf('/var/www/html/site/../wp-environment-bootstrapper'), '".." is collapsed first');
$t->assertSame(
    str_replace('\\', '/', dirname(sys_get_temp_dir())),
    str_replace('\\', '/', Filesystem::parentOf(sys_get_temp_dir())),
    'the system temp directory resolves to its parent'
);

$t->suite('Path resolution: the resolved path is what is checked and executed');

$setupCommand = file_get_contents(dirname(__DIR__, 2) . '/src/Commands/SetupCommand.php');
$environment  = file_get_contents(dirname(__DIR__, 2) . '/src/Core/Environment.php');

$t->assertContains(
    "new Environment(\$result['config']",
    (string) $setupCommand,
    'SetupCommand hands the validated (resolved) configuration to Environment'
);
$t->assertContains(
    '$this->validator->check($this->database, (string) $this->config[\'wordpress\'][\'path\'])',
    (string) $environment,
    'the environment check runs against the resolved install path'
);
$t->assertContains(
    "'--path=' . \$this->config['wordpress']['path']",
    (string) $environment,
    'WP-CLI is started with the resolved install path'
);
