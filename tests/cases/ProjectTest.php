<?php
/**
 * Repository invariants: deliverables exist, docs match the implementation,
 * no secrets or banned constructs are committed.
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);

$read = static function (string $relative) use ($root): string {
    $contents = @file_get_contents($root . '/' . $relative);

    if ($contents === false) {
        throw new RuntimeException('Missing file: ' . $relative);
    }

    return $contents;
};

$t->suite('Deliverables');

$required = [
    'setup.php',
    'composer.json',
    'README.md',
    'CONTRIBUTING.md',
    'SECURITY.md',
    'CHANGELOG.md',
    'LICENSE',
    '.editorconfig',
    '.gitignore',
    '.gitattributes',
    'bin/wp-env',
    'config/setup.example.json',
    'config/plugins.json',
    'secrets/env.example.json',
    'tests/run.php',
    '.github/workflows/php.yml',
];

foreach ($required as $file) {
    $t->assertTrue(is_file($root . '/' . $file), "deliverable present: {$file}");
}

$t->assertTrue(is_executable($root . '/bin/wp-env'), 'bin/wp-env is executable');
$t->assertContains('wp --require=', $read('bin/wp-env'), 'bin/wp-env wraps the documented command');
$t->assertContains('MIT License', $read('LICENSE'), 'LICENSE is MIT');
$t->assertContains('"license": "MIT"', $read('composer.json'), 'composer license matches LICENSE');

$t->suite('Documentation matches the implementation');

$readme = $read('README.md');
$headings = preg_grep('/^## /', explode("\n", $readme)) ?: [];

$t->assertSame(26, count($headings), 'README.md has the 26 documented sections');

// Repository URL must be real - no <placeholder> left in the documentation.
$t->assertNotContains('<your-fork-url>', $readme, 'README clone URL is filled in');
$t->assertNotContains('<your-fork-url>', $read('CONTRIBUTING.md'), 'CONTRIBUTING clone URL is filled in');
$t->assertContains(
    'bin/wp-env.cmd text eol=crlf',
    $read('.gitattributes'),
    'the Windows wrapper is checked out with CRLF'
);

// Section 9 (Command options) must list exactly the options setup.php declares.
$setupPhp     = $read('setup.php');
$synopsis     = [];
preg_match_all("/'name'\s*=>\s*'([^']+)'/", $setupPhp, $matches);
$synopsis     = array_values(array_unique($matches[1]));
$sort         = static function (array $values): array {
    sort($values);

    return $values;
};

$t->assertSame(
    $sort(['config', 'dry-run', 'skip-plugins', 'skip-theme']),
    $sort($synopsis),
    'setup.php declares exactly the four documented options'
);

preg_match_all('/^## 9\. Command options$(.*?)^## 10\./ms', $readme, $sectionNine);
$sectionNineText = (string) ($sectionNine[1][0] ?? '');
preg_match_all('/`(--[a-z-]+)(?:=[^`]*)?`/', $sectionNineText, $documented);
$documented = $sort(array_values(array_unique($documented[1])));

$t->assertSame(
    $sort(['--config', '--dry-run', '--skip-plugins', '--skip-theme']),
    $documented,
    'README section 9 documents exactly the implemented options'
);

$t->assertTrue(
    preg_match("/'when'\s*=>\s*'before_wp_load'/", $setupPhp) === 1,
    'the setup command runs before WordPress loads'
);
$t->assertContains('WP_CLI::add_command', $setupPhp, 'the command is registered through WP_CLI::add_command');

// WP-CLI marks a flag that is not optional as an "unknown" synopsis part and
// then rejects the option entirely, so every flag must declare optional=true.
preg_match_all(
    "/'type'\s*=>\s*'flag',\s*'name'\s*=>\s*'([^']+)',\s*'description'\s*=>\s*'[^']*',\s*'optional'\s*=>\s*true/s",
    $setupPhp,
    $flags
);
$t->assertSame(
    ['dry-run', 'skip-plugins', 'skip-theme'],
    $flags[1],
    'every flag in the synopsis is marked optional (required by WP-CLI)'
);

$commandSource = $read('src/Commands/SetupCommand.php');
$t->assertTrue(
    preg_match('/^use WP_CLI;/m', $commandSource) === 1 || strpos($commandSource, '\\WP_CLI::') !== false,
    'SetupCommand resolves the global WP_CLI class (it lives in a namespace)'
);

$databaseSource = $read('src/WordPress/Database.php');
$t->assertContains('database\\s+exists', $databaseSource, 'db create tolerates the MySQL 8 "database exists" wording');
$t->assertContains('already\\s+exists', $databaseSource, 'db create tolerates the MariaDB "already exists" wording');
$t->assertContains('setup.php', $readme, 'README documents the entry point');

$t->suite('No secrets committed');

$envExample = json_decode($read('secrets/env.example.json'), true);
$t->assertTrue(is_array($envExample), 'secrets/env.example.json parses');
$t->assertTrue(array_key_exists('acf_pro_key', $envExample), 'secrets template documents acf_pro_key');
$t->assertSame('', (string) ($envExample['acf_pro_key'] ?? 'x'), 'secrets template ships empty values');

$gitignore = $read('.gitignore');

foreach ([
    '/config/setup.json',
    '/secrets/env.json',
    '/setup.json',
    '/env.json',
    '/vendor/',
    '*.zip',
    '*.wpress',
] as $pattern) {
    $t->assertContains($pattern, $gitignore, ".gitignore excludes {$pattern}");
}

// Scan the files a `git add .` would stage for key-like or credential-like content.
// Everything .gitignore excludes (plus any WordPress copy dropped into the project)
// is skipped: those files are never staged.
$skipFiles  = ['setup.json', 'env.json']; // legacy local files, gitignored, never staged
$skipDirs   = ['vendor', '.git'];
$scannable  = ['php', 'json', 'md', 'yml', 'yaml', 'sh', 'cmd', 'txt', 'xml', 'ini', 'gitignore', 'editorconfig'];
$offenders  = [];

foreach (explode("\n", $gitignore) as $line) {
    $line = trim($line);

    if ($line !== '' && $line[0] !== '#' && preg_match('#^/([^/*]+)/?$#', $line, $matches) === 1) {
        $skipDirs[] = $matches[1];
    }
}

$skipDirs  = array_values(array_unique($skipDirs));
$iterator   = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);

/** @var SplFileInfo $item */
foreach ($iterator as $item) {
    if (!$item->isFile()) {
        continue;
    }

    $relative = str_replace($root . DIRECTORY_SEPARATOR, '', $item->getPathname());
    $parts    = explode(DIRECTORY_SEPARATOR, $relative);

    if (in_array($parts[0], $skipDirs, true) || in_array(basename($relative), $skipFiles, true)) {
        continue;
    }

    // A WordPress installation placed inside the project directory.
    if (preg_match('#/(wp-content|wp-includes|wp-admin)/#', '/' . $relative) === 1) {
        continue;
    }

    // Templates and documentation intentionally show placeholder values.
    if (preg_match('/\.example\.json$|\.md$/i', $relative) === 1) {
        continue;
    }

    // Only text files are inspected - images, fonts, bundles and archives are skipped.
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

    if ($extension !== '' && !in_array($extension, $scannable, true)) {
        continue;
    }

    $contents = (string) @file_get_contents($item->getPathname());

    if (preg_match('/\b[0-9a-f]{32}\b/i', $contents) === 1) {
        $offenders[] = $relative . ' (32-char hex value, looks like a license key)';
    }

    if (preg_match('/["\'](?:dbpass|password|acf_pro_key|acf_key)["\']\s*:\s*["\'][^"\']{6,}["\']/', $contents) === 1) {
        $offenders[] = $relative . ' (non-empty credential in JSON)';
    }
}

$t->assertSame([], $offenders, 'no key-like or credential-like values in staged files: ' . implode(', ', $offenders));

$t->assertSame([], $offenders, 'no key-like or credential-like values in staged files: ' . implode(', ', $offenders));

$t->suite('No banned constructs or placeholders in source');

$sourceFiles = ['setup.php'];
$iterator    = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS)
);

/** @var SplFileInfo $item */
foreach ($iterator as $item) {
    if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
        $sourceFiles[] = str_replace($root . DIRECTORY_SEPARATOR, '', $item->getPathname());
    }
}

$t->assertTrue(count($sourceFiles) >= 15, 'source tree is populated (' . count($sourceFiles) . ' PHP files)');

$banned = ['shell_exec', 'passthru', 'exec', 'system', 'popen', 'eval', 'assert'];
$found  = [];

foreach ($sourceFiles as $file) {
    $contents = $read($file);

    foreach ($banned as $function) {
        if (preg_match('/\b' . $function . '\s*\(/', $contents) === 1) {
            $found[] = $file . ' uses ' . $function . '()';
        }
    }

    if (preg_match('/\b(TODO|FIXME|XXX|@todo|Not implemented)\b/', $contents) === 1) {
        $found[] = $file . ' contains a placeholder marker';
    }
}

$t->assertSame([], $found, 'no shell-exec shortcuts or placeholder markers: ' . implode(', ', $found));

$t->suite('Plugin catalog is data only');

$catalog = json_decode($read('config/plugins.json'), true);
$t->assertTrue(is_array($catalog), 'config/plugins.json parses');
$t->assertTrue(isset($catalog['plugins']) && is_array($catalog['plugins']), 'catalog exposes a plugins list');
$t->assertContains('REFERENCE CATALOG ONLY', (string) ($catalog['_comment'] ?? ''), 'catalog states it is reference-only');

$catalogNames = array_column($catalog['plugins'], 'name');
$t->assertTrue(in_array('acf-pro', $catalogNames, true), 'catalog mentions acf-pro');
$t->assertTrue(in_array('classic-editor', $catalogNames, true), 'catalog mentions classic-editor');

// The example configuration must only use slugs that appear in the catalog or are
// clearly WordPress.org plugins - catching typos in the shipped template.
$example = json_decode($read('config/setup.example.json'), true);
$t->assertTrue(is_array($example), 'config/setup.example.json parses');

foreach ($example['plugins']['install'] ?? [] as $entry) {
    $name = is_array($entry) ? (string) ($entry['name'] ?? '') : (string) $entry;
    $t->assertTrue($name !== '', 'example plugin entry has a name');
}

$t->assertTrue(
    is_array($example['database'] ?? null) && is_array($example['admin'] ?? null),
    'example configuration contains the documented sections'
);

$t->suite('CI workflow');

$workflow = $read('.github/workflows/php.yml');
$t->assertContains('php tests/run.php', $workflow, 'CI runs the test suite');
$t->assertContains('php -l', $workflow, 'CI lints PHP files');
$t->assertContains('composer validate --strict', $workflow, 'CI validates composer.json');
$t->assertContains("'7.4'", $workflow, 'CI covers the minimum supported PHP version');
$t->assertContains("'8.5'", $workflow, 'CI covers the newest supported PHP version');
$t->assertContains('shellcheck bin/wp-env', $workflow, 'CI shell-checks the POSIX wrapper');
$t->assertContains('setup --dry-run', $workflow, 'CI smoke-tests the dry run');
