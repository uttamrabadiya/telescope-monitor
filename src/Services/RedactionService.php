<?php

namespace TelescopeMonitor\Services;

final class RedactionService
{
    public function text(string $text, array $patterns = []): string
    {
        $replacements = [
            '~\b(Bearer\s+)[a-z0-9._\~+/=-]+~i' => '$1[REDACTED]',
            '~((?:[a-z][a-z0-9+.-]*://)[^\s:/@]+:)[^\s@]+(?:@|$)~i' => '$1[REDACTED]@',
            '~((?:["\']?)(?:password|passwd|secret|token|api[_-]?key|authorization|access[_-]?token|client[_-]?secret)(?:["\']?)\s*[:=]\s*)(?:\[REDACTED\]|"[^"]*"|\'[^\']*\'|[^\s,;&}\]]+)~i' => '$1[REDACTED]',
            '~\beyJ[a-zA-Z0-9_-]+(?:\.[a-zA-Z0-9_-]*){0,2}\b~' => '[REDACTED]',
            '~\b(?:sk|pk)_(?:live|test)_[a-zA-Z0-9]+\b~' => '[REDACTED]',
            '~\b(?:gh[pousr]_[a-zA-Z0-9]+|github_pat_[a-zA-Z0-9_]+)\b~' => '[REDACTED]',
        ];
        foreach ($replacements as $pattern => $replacement) {
            $text = preg_replace($pattern, $replacement, $text) ?? '[REDACTED]';
        }
        foreach ($patterns as $pattern) {
            preg_match_all('/\[REDACTED\]/', $text, $markers, PREG_OFFSET_CAPTURE);
            $ranges = $markers[0];
            $cursor = 0;
            $count = 0;
            if (@preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                $text = '[REDACTED]';

                continue;
            }
            $out = '';
            $previous = 0;
            foreach ($matches[0] as [$value,$offset]) {
                $end = $offset + strlen($value);
                while (isset($ranges[$cursor]) && $ranges[$cursor][1] + strlen($ranges[$cursor][0]) < $offset) {
                    $cursor++;
                }
                $protected = isset($ranges[$cursor]) && $offset >= $ranges[$cursor][1] && $end <= $ranges[$cursor][1] + strlen($ranges[$cursor][0]);
                $out .= substr($text, $previous, $offset - $previous).($protected ? $value : '[REDACTED]');
                $previous = $end;
            }
            $text = $out.substr($text, $previous);
        }

        return $text;
    }
}
