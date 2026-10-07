<?php
/**
 * Environment checks (`Core\Validator`) reported before anything is changed.
 */

declare(strict_types=1);

use WpEnvironment\Core\Logger;
use WpEnvironment\Core\Validator;
use WpEnvironment\Support\CommandRunner;
use WpEnvironment\Support\Filesystem;
use WpEnvironment\WordPress\Database;

$checksRoot = sys_get_temp_dir() . '/wp-env-tests/validator';

$cleanup = static function () use ($checksRoot): void {
    if (is_dir($checksRoot)) {
        Filesystem::deleteDirectory($checksRoot);
    }
};
$cleanup();

$t->suite('Validator: four labelled checks');

$installPath = $checksRoot . DIRECTORY_SEPARATOR . 'wordpress';
$logger      = new Logger(false);
$runner      = new CommandRunner($logger);
$secretPass  = 'Validator-Secret-Value-9137';

$database = new Database(
    [
        'name'     => 'wp_validator_probe',
        'user'     => 'wp_no_such_user',
        'password' => $secretPass,
        'host'     => '127.0.0.1:3306',
        'prefix'   => 'wp_',
    ],
    $installPath,
    $runner,
    $logger
);

$validator = new Validator($runner);
$checks    = $validator->check($database, $installPath);

$t->assertSame(4, count($checks), 'exactly four environment checks are reported');
$t->assertSame(
    ['PHP', 'WP-CLI', 'Install path', 'Database'],
    array_column($checks, 'label'),
    'checks keep their documented labels and order'
);

foreach ($checks as $check) {
    $t->assertTrue(
        in_array($check['status'], ['ok', 'warn', 'fail'], true),
        'check "' . $check['label'] . '" reports a known status'
    );
    $t->assertTrue(is_string($check['detail']), 'check "' . $check['label'] . '" carries a detail');
    $t->assertTrue(is_array($check['hints']), 'check "' . $check['label'] . '" carries hints');
    $t->assertTrue(
        $check['status'] !== 'fail' || $check['hints'] !== [],
        'failing check "' . $check['label'] . '" explains what to do'
    );
}

$t->assertSame('ok', $checks[0]['status'], 'PHP ' . PHP_VERSION . ' passes the version check');
$t->assertSame(PHP_VERSION, $checks[0]['detail'], 'PHP check reports the running version');

$installCheck = $checks[2];
$t->assertSame('ok', $installCheck['status'], 'install path that does not exist yet is writable via its parent');
$t->assertContains($installPath, $installCheck['detail'], 'install path check names the resolved path');

$databaseCheck = $checks[3];
$t->assertTrue(
    in_array($databaseCheck['status'], ['fail', 'warn'], true),
    'bogus credentials never pass the database pre-flight (status: ' . $databaseCheck['status'] . ')'
);
$t->assertTrue($databaseCheck['hints'] !== [], 'failing database pre-flight carries hints');

if ($databaseCheck['status'] === 'fail') {
    $t->assertContains(
        'Verify the database credentials',
        implode(' ', $databaseCheck['hints']),
        'database failure carries an actionable hint'
    );
}

$everything = (string) json_encode($checks);
$t->assertTrue(
    strpos($everything, $secretPass) === false,
    'the database password never appears in any check result'
);
$t->assertContains('writable', $installCheck['detail'], 'install path check states whether the path is writable');

$t->suite('Validator: unwritable install path is fatal');

$blocked = $validator->check($database, '');
$t->assertSame('fail', $blocked[2]['status'], 'an empty install path cannot be written to');
$t->assertTrue($blocked[2]['hints'] !== [], 'unwritable install path explains how to fix it');
$t->assertContains('wordpress.path', implode(' ', $blocked[2]['hints']), 'hint points at the wordpress.path setting');

$readOnlyDir = $checksRoot . DIRECTORY_SEPARATOR . 'readonly';
Filesystem::ensureDirectory($readOnlyDir);
chmod($readOnlyDir, 0500);

if (function_exists('posix_geteuid')) {
    $runningAsRoot = posix_geteuid() === 0;
} elseif (is_readable('/proc/self/status')) {
    $runningAsRoot = preg_match('/^Uid:\s+0/m', (string) file_get_contents('/proc/self/status')) === 1;
} else {
    $runningAsRoot = null;
}

if (!$runningAsRoot) {
    $blockedDir = $validator->check($database, $readOnlyDir . DIRECTORY_SEPARATOR . 'wp');
    $t->assertSame('fail', $blockedDir[2]['status'], 'a directory nobody may write is reported as a failure');
} else {
    echo "  skip unwritable-directory probe (running as root, permissions are bypassed)\n";
}

chmod($readOnlyDir, 0755);
$cleanup();

$t->suite('Validator: WP-CLI check');

$wpCliCheck = (new Validator($runner))->check($database, $installPath)[1];
$t->assertTrue(
    in_array($wpCliCheck['status'], ['ok', 'fail'], true),
    'WP-CLI check is either ok or fail (status: ' . $wpCliCheck['status'] . ')'
);

if ($wpCliCheck['status'] === 'fail') {
    $t->assertContains('wp-cli.org', implode(' ', $wpCliCheck['hints']), 'missing WP-CLI points at the installer');
} else {
    $t->assertTrue(
        preg_match('/^\d+\.\d+(\.\d+)?/', $wpCliCheck['detail']) === 1,
        'WP-CLI check reports the detected version (' . $wpCliCheck['detail'] . ')'
    );
}
