<?php
/**
 * Config loading, validation, secret redaction, dry-run and filesystem tests.
 */

declare(strict_types=1);

use WpEnvironment\Config\ConfigLoader;
use WpEnvironment\Config\ConfigValidator;
use WpEnvironment\Core\Logger;
use WpEnvironment\Core\SetupException;
use WpEnvironment\Support\CommandRunner;
use WpEnvironment\Support\Filesystem;

$root = dirname(__DIR__, 2);

/**
 * Create an isolated project root under the system temp directory.
 */
$makeProject = static function (): string {
    $dir = sys_get_temp_dir() . '/wp-env-tests/' . uniqid('project', true);

    if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Unable to create test directory: ' . $dir);
    }

    return $dir;
};

$t->suite('ConfigLoader: example configuration');

$loader    = new ConfigLoader($root);
$loaded    = $loader->load($root . '/config/setup.example.json');
$config    = $loaded['config'];
$validator = new ConfigValidator($root);
$report    = $validator->validate($config, $loaded['secrets']);

$t->assertSame($root . '/config/setup.example.json', $loaded['config_path'], 'explicit --config path is used verbatim');
$t->assertSame('6.8.2', $config['wordpress']['version'], 'example config decodes');
$t->assertSame([], $report['errors'], 'example config passes validation: ' . implode('; ', $report['errors']));
$t->assertTrue(
    strpos($report['config']['wordpress']['path'], DIRECTORY_SEPARATOR) !== false,
    'wordpress.path is resolved to an absolute path'
);
$t->assertTrue(is_array($loaded['secrets']), 'secrets array is always present');

$t->suite('ConfigLoader: legacy setup.json schema');

$legacyRoot = $makeProject();
file_put_contents($legacyRoot . '/setup.json', json_encode([
    'version'             => '6.2.2',
    'dbname'              => 'legacy_db',
    'dbuser'              => 'legacy_user',
    'dbpass'              => 'legacy_pass',
    'dbhost'              => '127.0.0.1:3307',
    'dbprefix'            => 'lg_',
    'site_url'            => 'http://legacy.test/site',
    'title'               => 'Legacy Site',
    'admin_name'          => 'legacyadmin',
    'admin_password'      => 'LegacyPass123!',
    'admin_email'         => 'legacy@example.test',
    'axioned_theme'       => 'https://example.test/theme.zip',
    'pluginListInstall'   => [['name' => 'classic-editor', 'status' => true]],
    'pluginListUninstall' => [['name' => 'hello'], 'akismet'],
    'pluginList'          => [['name' => 'acf-pro']],
], JSON_PRETTY_PRINT));
file_put_contents($legacyRoot . '/env.json', json_encode(['acf_key' => 'LEGACY-KEY-123']));

$legacyLoader = new ConfigLoader($legacyRoot);
$legacyLoaded = $legacyLoader->load();
$legacy       = $legacyLoaded['config'];

$t->assertTrue(
    strpos(implode("\n", $legacyLoaded['warnings']), 'legacy setup.json schema') !== false,
    'legacy schema detection is announced as a warning'
);
$t->assertSame('legacy_db', $legacy['database']['name'], 'dbname -> database.name');
$t->assertSame('legacy_user', $legacy['database']['user'], 'dbuser -> database.user');
$t->assertSame('legacy_pass', $legacy['database']['password'], 'dbpass -> database.password');
$t->assertSame('127.0.0.1:3307', $legacy['database']['host'], 'dbhost -> database.host');
$t->assertSame('lg_', $legacy['database']['prefix'], 'dbprefix -> database.prefix');
$t->assertSame('http://legacy.test/site', $legacy['site']['url'], 'site_url -> site.url');
$t->assertSame('Legacy Site', $legacy['site']['title'], 'title -> site.title');
$t->assertSame('legacyadmin', $legacy['admin']['username'], 'admin_name -> admin.username');
$t->assertSame('LegacyPass123!', $legacy['admin']['password'], 'admin_password -> admin.password');
$t->assertSame('6.2.2', $legacy['wordpress']['version'], 'version -> wordpress.version');
$t->assertSame('classic-editor', $legacy['plugins']['install'][0]['name'], 'pluginListInstall mapped');
$t->assertTrue($legacy['plugins']['install'][0]['activate'] === true, 'legacy status flag -> activate');
$t->assertSame(['hello', 'akismet'], $legacy['plugins']['remove'], 'pluginListUninstall mapped');
$t->assertSame('https://example.test/theme.zip', $legacy['theme']['source'], 'axioned_theme -> theme.source');
$t->assertSame('LEGACY-KEY-123', $legacyLoaded['secrets']['acf_pro_key'], 'legacy env.json acf_key -> acf_pro_key');
$t->assertContains('acf_key', implode("\n", $legacyLoaded['warnings']), 'legacy acf_key mapping is announced');

$legacyReport = $validator->validate($legacy, $legacyLoaded['secrets']);
$t->assertSame([], $legacyReport['errors'], 'normalized legacy config validates: ' . implode('; ', $legacyReport['errors']));

$t->suite('ConfigLoader: failure modes');

$emptyRoot = $makeProject();
$t->assertThrows(
    SetupException::class,
    static function () use ($emptyRoot): void {
        (new ConfigLoader($emptyRoot))->load();
    },
    'no configuration file anywhere throws SetupException',
    'config/setup.json'
);

$noConfigHints = '';
try {
    (new ConfigLoader($emptyRoot))->load();
} catch (SetupException $e) {
    $noConfigHints = implode("\n", $e->getHints());
}
$t->assertContains(
    'cp config/setup.example.json config/setup.json',
    $noConfigHints,
    'the hint gives the exact copy command'
);
$t->assertContains(
    Filesystem::parentOf($emptyRoot) . DIRECTORY_SEPARATOR . 'project',
    $noConfigHints,
    'the hint shows where a relative wordpress.path is created (next to the repository)'
);
$t->assertContains(
    'bin/wp-env',
    $noConfigHints,
    'the hint mentions the short form bin/wp-env'
);
$t->assertNotContains(
    'wp-environment-bootstrapper' . DIRECTORY_SEPARATOR . 'project',
    $noConfigHints,
    'the hint never suggests installing inside the repository'
);

$brokenRoot = $makeProject();
mkdir($brokenRoot . '/config', 0755, true);
file_put_contents($brokenRoot . '/config/setup.json', '{ "wordpress": { "version": "6.8.2", }');
$t->assertThrows(
    SetupException::class,
    static function () use ($brokenRoot): void {
        (new ConfigLoader($brokenRoot))->load();
    },
    'invalid JSON throws SetupException',
    'invalid JSON'
);

$t->assertThrows(
    SetupException::class,
    static function () use ($loader): void {
        $loader->load('/definitely/not/here/setup.json');
    },
    'missing --config file throws SetupException',
    'Configuration file not found'
);

$t->suite('ConfigValidator: collects every problem');

$bad = $config;
$bad['wordpress']['version'] = 'latest';
$bad['database']['user']     = '';
$bad['admin']['email']       = 'not-an-email';
$bad['site']['url']          = 'ftp://nope';

$badReport = (new ConfigValidator($root))->validate($bad, []);

$t->assertTrue($badReport['errors'] !== [], 'invalid config produces errors');
$t->assertTrue(count($badReport['errors']) >= 4, 'all four problems are reported, not just the first');
$t->assertContains('wordpress.version', implode("\n", $badReport['errors']), 'version error is named');
$t->assertContains('database.user', implode("\n", $badReport['errors']), 'database error is named');
$t->assertContains('admin.email', implode("\n", $badReport['errors']), 'admin error is named');
$t->assertContains('site.url', implode("\n", $badReport['errors']), 'site error is named');

$t->suite('ConfigValidator: ACF Pro licensing');

$acf = $config;
$acf['plugins']['install'][] = ['name' => 'acf-pro', 'activate' => true];

$withoutKey = (new ConfigValidator($root))->validate($acf, []);
$withKey    = (new ConfigValidator($root))->validate($acf, ['acf_pro_key' => 'my-legitimate-key']);

$t->assertTrue($withoutKey['errors'] !== [], 'acf-pro without a key fails validation');
$t->assertContains('acf_pro_key', implode("\n", $withoutKey['errors']), 'error names the missing key');
$t->assertContains('secrets/env.json', implode("\n", $withoutKey['errors']), 'error says where the key goes');
$t->assertTrue(
    in_array('acf-pro', array_column($withKey['config']['plugins']['install'], 'name'), true),
    'acf-pro accepted when a key is provided'
);
$t->assertSame([], array_filter($withKey['errors'], static function (string $e): bool {
    return stripos($e, 'acf') !== false;
}), 'no ACF related error once a key is present');

$t->suite('ConfigValidator: strictness and unknown keys');

$lenient            = $config;
$lenient['plugins'] = ['install' => [], 'remove' => [], 'strict' => false];
$typo               = $config;
$typo['databse']    = ['name' => 'x'];

$lenientReport = (new ConfigValidator($root))->validate($lenient, []);
$typoReport    = (new ConfigValidator($root))->validate($typo, []);

$t->assertTrue($lenientReport['config']['plugins']['strict'] === false, 'plugins.strict=false is honoured');
$t->assertTrue(
    stripos(implode("\n", $typoReport['warnings']), 'databse') !== false,
    'a misspelt top-level section is reported as an unknown key'
);

$t->suite('CommandRunner: secret redaction');

$logger = new Logger(false);
$runner = new CommandRunner($logger, true);

$runner->addSecret('S3cr3t-DB-password');
$runner->addSecret('S3cr3t-DB-password');
$runner->addSecret(null);
$runner->addSecret('');

$redacted = $runner->redact('password is S3cr3t-DB-password (S3cr3t-DB-password)');
$t->assertNotContains('S3cr3t-DB-password', $redacted, 'a registered secret never reaches the output');
$t->assertContains('[redacted]', $redacted, 'redaction marker is visible');

$t->assertSame('nothing to hide', $runner->redact('nothing to hide'), 'text without secrets is untouched');
$t->assertContains('[redacted]', $runner->display(['option=1', 'S3cr3t-DB-password']), 'display() redacts too');

$t->suite('CommandRunner: dry run');

$dry = new CommandRunner($logger, true);
$dry->addSecret('S3cr3t-DB-password');

$result = $dry->run(['core', 'download', '--path=/tmp/nowhere', '--dbpass=S3cr3t-DB-password']);

$t->assertTrue(!$result->wasExecuted(), 'dry run does not spawn a process');
$t->assertTrue($result->isOk(), 'dry run reports success so the plan can be printed');
$t->assertSame(0, $result->getExitCode(), 'dry run exit code is 0');
$t->assertNotContains('S3cr3t-DB-password', implode("\n", $dry->getHistory()), 'history is redacted');
$t->assertContains('core download', implode("\n", $dry->getHistory()), 'history records the planned command');

// Environment checks are explicitly allowed to run during a dry run.
$argv0 = $_SERVER['argv'][0] ?? 'wp';
$_SERVER['argv'][0] = 'wp';

try {
    $readOnly = $dry->run(['--info'], ['readonly' => true]);
} finally {
    $_SERVER['argv'][0] = $argv0;
}

$t->assertTrue($readOnly->wasExecuted(), 'readonly commands still run in dry-run mode');

$t->suite('CommandRunner: real child process');

if (($path = (static function (): ?string {
    $binary = getenv('PATH') ?: '';
    foreach (explode(PATH_SEPARATOR, $binary) as $dir) {
        if ($dir !== '' && is_file($dir . DIRECTORY_SEPARATOR . 'wp')) {
            return $dir . DIRECTORY_SEPARATOR . 'wp';
        }
    }
    return null;
})()) !== null) {
    $_SERVER['argv'][0] = 'wp';

    try {
        $live = (new CommandRunner($logger, false))->run(['--info']);
    } finally {
        $_SERVER['argv'][0] = $argv0;
    }

    $t->assertTrue($live->wasExecuted(), 'a non-dry-run command really executes');
    $t->assertTrue($live->isOk(), 'wp --info exits successfully');
    $t->assertContains('WP-CLI', $live->getStdout(), 'child process output is captured');
} else {
    echo "  skip wp binary not found on PATH (install WP-CLI to run this check)\n";
}

$t->suite('Filesystem');

$t->assertTrue(Filesystem::isAbsolute('/var/www'), 'unix absolute path detected');
$t->assertTrue(Filesystem::isAbsolute('C:\\wordpress'), 'windows absolute path detected');
$t->assertTrue(!Filesystem::isAbsolute('./wordpress'), 'relative path detected');
$t->assertSame(
    '/a' . DIRECTORY_SEPARATOR . 'c' . DIRECTORY_SEPARATOR . 'd',
    Filesystem::normalize('/a/b/../c/./d'),
    'normalize collapses . and ..'
);
$t->assertSame(
    Filesystem::resolve('./wordpress', '/srv/project'),
    '/srv' . DIRECTORY_SEPARATOR . 'project' . DIRECTORY_SEPARATOR . 'wordpress',
    'resolve() joins relative paths against the base'
);
$t->assertSame(
    Filesystem::resolve('/already/absolute', '/srv/project'),
    '/already/absolute',
    'resolve() keeps absolute paths'
);

$zipFile = sys_get_temp_dir() . '/wp-env-tests/' . uniqid('theme', true) . '.zip';

if (class_exists('ZipArchive')) {
    $zip = new ZipArchive();
    $zip->open($zipFile, ZipArchive::CREATE);
    $zip->addFromString('my-theme-folder/style.css', "/* Theme Name: Test */");
    $zip->addFromString('my-theme-folder/index.php', "<?php");
    $zip->close();

    $t->assertSame('my-theme-folder', Filesystem::zipRootSlug($zipFile), 'zip root slug detected');
    @unlink($zipFile);
} else {
    echo "  skip ZipArchive extension not available\n";
}

$t->suite('Cleanup');

foreach ([$legacyRoot, $emptyRoot, $brokenRoot] as $dir) {
    Filesystem::deleteDirectory($dir);
}

$t->assertTrue(!is_dir($legacyRoot), 'temporary project roots are removed');
