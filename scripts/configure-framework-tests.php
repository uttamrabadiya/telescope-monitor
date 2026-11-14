<?php

// Run only in a disposable checkout: dependency resolution rewrites its lockfile.
$major = (int) ($argv[1] ?? 0);
$telescope = $argv[2] ?? 'latest';
$versions = [
    8 => ['^8.83.29', '^6.48', '^9.6'],
    9 => ['^9.52.23', '^7.57', '^9.6'],
    10 => ['^10.50.4', '^8.38', '^10.5'],
    11 => ['^11.57.1', '^9.15', '^11.5'],
    12 => ['^12.72.0', '^10.8', '^11.5'],
    13 => ['^13.42.0', '^11.0', '^12.0'],
];
if (! isset($versions[$major]) || ($telescope !== 'latest' && ! preg_match('/^5\.\d+\.\d+$/D', $telescope))) {
    fwrite(STDERR, "Usage: php scripts/configure-framework-tests.php <8|9|10|11|12|13> [5.x.y|latest]\n");
    exit(1);
}
$path = dirname(__DIR__).'/composer.json';
$composer = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
[$framework, $testbench, $phpunit] = $versions[$major];
$composer['require']['laravel/framework'] = $framework;
$composer['require']['laravel/telescope'] = $telescope === 'latest' ? '^5.19.0' : $telescope;
$composer['require-dev']['orchestra/testbench'] = $testbench;
$composer['require-dev']['phpunit/phpunit'] = $phpunit;
// Static analysis runs separately against the maintained development framework.
unset($composer['require-dev']['larastan/larastan'], $composer['require-dev']['laravel/framework']);
file_put_contents($path, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo "Configured Laravel {$major} / Telescope {$telescope} / Testbench {$testbench} / PHPUnit {$phpunit}\n";
