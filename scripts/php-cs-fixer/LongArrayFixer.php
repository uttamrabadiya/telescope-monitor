<?php

namespace TelescopeMonitor\CodingStyle;

use PhpCsFixer\AbstractFixer;
use PhpCsFixer\FixerDefinition\CodeSample;
use PhpCsFixer\FixerDefinition\FixerDefinition;
use PhpCsFixer\FixerDefinition\FixerDefinitionInterface;
use PhpCsFixer\Tokenizer\CT;
use PhpCsFixer\Tokenizer\Tokens;
use SplFileInfo;

final class LongArrayFixer extends AbstractFixer
{
    public function getName(): string
    {
        return 'TelescopeMonitor/long_array';
    }

    public function getDefinition(): FixerDefinitionInterface
    {
        return new FixerDefinition(
            'Wrap arrays longer than 120 characters and use one item per line in multiline arrays.',
            [new CodeSample("<?php\n\$values = [\n    1, 2, 3,\n];\n")],
        );
    }

    public function getPriority(): int
    {
        // After array_syntax (37), before array_indentation (29).
        return 36;
    }

    public function isCandidate(Tokens $tokens): bool
    {
        return $tokens->isTokenKindFound(CT::T_ARRAY_BRACKET_OPEN);
    }

    protected function applyFix(SplFileInfo $file, Tokens $tokens): void
    {
        for ($open = $tokens->count() - 1; $open >= 0; --$open) {
            if (! $tokens[$open]->isGivenKind(CT::T_ARRAY_BRACKET_OPEN)) {
                continue;
            }

            $close = $tokens->findBlockEnd(Tokens::BLOCK_TYPE_ARRAY_BRACKET, $open);
            if ($tokens->getNextMeaningfulToken($open) === $close) {
                continue;
            }

            $code = $tokens->generatePartialCode($open, $close);
            if (strlen($code) <= 120 && ! str_contains($code, "\n")) {
                continue;
            }

            $boundaries = [$open + 1];
            for ($index = $open + 1; $index < $close; ++$index) {
                // An anonymous class can have commas in its implements list.
                if ($tokens[$index]->isGivenKind(T_IMPLEMENTS)) {
                    $index = $tokens->getNextTokenOfKind($index, ['{']);
                }

                $block = Tokens::detectBlockType($tokens[$index]);
                if ($block !== null && $block['isStart']) {
                    $index = $tokens->findBlockEnd($block['type'], $index);
                    continue;
                }

                if ($tokens[$index]->equals(',')) {
                    $boundaries[] = $index + 1;
                }
            }

            $boundaries[] = $tokens[$close - 1]->isWhitespace() ? $close - 1 : $close;
            $boundaries = array_unique($boundaries);
            rsort($boundaries);
            foreach ($boundaries as $index) {
                // Preserve existing whitespace, including the newline after // comments.
                if (! $tokens[$index]->isWhitespace() || ! str_contains($tokens[$index]->getContent(), "\n")) {
                    $tokens->ensureWhitespaceAtIndex($index, 0, "\n");
                }
            }
        }
    }
}
