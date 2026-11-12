<?php

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use TelescopeMonitor\CodingStyle\LongArrayFixer;

require_once __DIR__.'/scripts/php-cs-fixer/LongArrayFixer.php';

$finder = Finder::create()
    ->in([
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/scripts/php-cs-fixer',
        __DIR__.'/src',
        __DIR__.'/tests',
        __DIR__.'/workbench/app',
        __DIR__.'/workbench/routes',
    ])
    ->append([
        __FILE__,
        __DIR__.'/phpstan-bootstrap.php',
        __DIR__.'/workbench/build.php',
    ]);

return (new Config)
    ->registerCustomFixers([new LongArrayFixer])
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'TelescopeMonitor/long_array' => true,
        'array_indentation' => true,
        'array_syntax' => ['syntax' => 'short'],
        'braces_position' => [
            'allow_single_line_anonymous_functions' => false,
            'anonymous_classes_opening_brace' => 'next_line_unless_newline_at_signature_end',
        ],
        'concat_space' => ['spacing' => 'none'],
        'new_with_parentheses' => ['anonymous_class' => false, 'named_class' => false],
        'no_extra_blank_lines' => ['tokens' => ['extra', 'use']],
        'no_unused_imports' => true,
        'not_operator_with_successor_space' => true,
        'single_line_empty_body' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays']],
        'whitespace_after_comma_in_array' => ['ensure_single_space' => true],
    ])
    ->setIndent('    ')
    ->setCacheFile(__DIR__.'/.php-cs-fixer.cache')
    ->setFinder($finder);
