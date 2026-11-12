<?php

namespace TelescopeMonitor\Tests\Formatting;

use PhpCsFixer\FixerFactory;
use PhpCsFixer\RuleSet\RuleSet;
use PhpCsFixer\Tokenizer\Tokens;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SplFileInfo;

final class LongArrayFixerTest extends TestCase
{
    #[DataProvider('arrays')]
    public function test_array_layout_preserves_values_and_is_stable(string $expression, bool $multiline): void
    {
        $input = "<?php\n\nreturn ".$expression.";\n";
        $output = $this->format($input);

        $this->assertSame($multiline, str_contains(substr($output, strpos($output, '[')), "[\n"));
        $this->assertSame(eval(substr($input, 5)), eval(substr($output, 5)));
        $this->assertSame($output, $this->format($output));
    }

    public static function arrays(): iterable
    {
        yield 'short array' => ["['a', 'b']", false];
        yield 'empty array' => ['[]', false];
        yield 'long array' => [var_export([str_repeat('a', 60), str_repeat('b', 60)], true), true];
        yield 'long single-line array' => ["['".str_repeat('a', 60)."', '".str_repeat('b', 60)."']", true];
        yield 'already multiline' => ["[\n    1, 2, 3,\n]", true];
        yield 'nested arrays and call commas' => ["[\n    [1, 2], max(3, 4),\n]", true];
        yield 'commas inside strings' => ["[\n    'a,b', 'c,d',\n]", true];
        yield 'comments' => ["[\n    1, // Keep this comment.\n    2 /* a,b */, 3,\n]", true];
        yield 'anonymous class with interface commas' => [
            "[\n    (new class implements \\Countable, \\JsonSerializable { public function count(): int { return 0; } public function jsonSerialize(): mixed { return []; } })->count(), 2,\n]",
            true,
        ];
    }

    public function test_multiline_array_has_one_direct_item_per_line(): void
    {
        $this->assertSame(
            "<?php\n\nreturn [\n    [1, 2],\n    max(3, 4),\n    'a,b',\n];\n",
            $this->format("<?php\n\nreturn [\n    [1, 2], max(3, 4), 'a,b',\n];\n"),
        );
    }

    public function test_offsets_and_destructuring_are_not_wrapped(): void
    {
        $input = "<?php\n\n[\$a, \$b] = \$values;\n\$value = \$values['".str_repeat('x', 130)."'];\n";
        $this->assertSame($input, $this->format($input));
    }

    private function format(string $code): string
    {
        $config = require dirname(__DIR__, 2).'/.php-cs-fixer.dist.php';
        $factory = (new FixerFactory)
            ->registerBuiltInFixers()
            ->registerCustomFixers($config->getCustomFixers())
            ->useRuleSet(new RuleSet($config->getRules()));
        $tokens = Tokens::fromCode($code);
        foreach ($factory->getFixers() as $fixer) {
            $fixer->fix(new SplFileInfo('fixture.php'), $tokens);
            $tokens->clearEmptyTokens();
        }

        return $tokens->generateCode();
    }
}
