<?php

namespace TelescopeMonitor\Capture;

/** Conservative, non-executing SQL lexer. Unknown or ambiguous syntax is omitted. */
final class SqlTemplate
{
    public function capture(mixed $sql, mixed $driver, int $maxBytes = 2048): array
    {
        if (! is_string($sql) || ! in_array($driver, ['mysql', 'mariadb', 'sqlite'], true) || ! mb_check_encoding($sql, 'UTF-8') || preg_match('/[\\\\\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $sql)) {
            return ['sql_state' => 'unsupported'];
        }
        if (strlen($sql) > 16384) {
            return ['sql_state' => 'too_large'];
        }
        $tokens = [];
        $length = strlen($sql);
        $depth = 0;
        $ended = false;
        for ($p = 0; $p < $length;) {
            $char = $sql[$p];
            if (ctype_space($char)) {
                $p++;

                continue;
            }
            if (substr($sql, $p, 2) === '--' || $char === '#') {
                $p = strpos($sql, "\n", $p) ?: $length;

                continue;
            }
            if (substr($sql, $p, 2) === '/*') {
                if (substr($sql, $p, 3) === '/*!' || strcasecmp(substr($sql, $p, 4), '/*M!') === 0) {
                    return ['sql_state' => 'unsupported'];
                }
                $end = strpos($sql, '*/', $p + 2);
                if ($end === false || str_contains(substr($sql, $p + 2, $end - $p - 2), '/*')) {
                    return ['sql_state' => 'unsupported'];
                }
                $p = $end + 2;

                continue;
            }
            if ($ended) {
                return ['sql_state' => 'unsupported'];
            }
            if ($char === ';') {
                $ended = true;
                $p++;

                continue;
            }
            if ($char === '"') {
                return ['sql_state' => 'unsupported'];
            }
            if ($char === "'" || $char === '`') {
                // Ambiguous double quotes are rejected above. Backslashes are rejected because
                // NO_BACKSLASH_ESCAPES changes where a quoted literal ends.
                $quote = $char;
                $value = '';
                $closed = false;
                $p++;
                while ($p < $length) {
                    if ($sql[$p] === $quote) {
                        if (($sql[$p + 1] ?? null) === $quote) {
                            $value .= $quote;
                            $p += 2;

                            continue;
                        }
                        $p++;
                        $closed = true;
                        break;
                    }
                    $value .= $sql[$p++];
                }
                if (! $closed) {
                    return ['sql_state' => 'unsupported'];
                }
                if ($quote === '`') {
                    if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D', $value)) {
                        return ['sql_state' => 'unsupported'];
                    }
                    $tokens[] = '`'.$value.'`';
                } else {
                    if ($tokens && preg_match('/^(?:[xXbBnN]|_[A-Za-z0-9_]+)$/D', end($tokens))) {
                        array_pop($tokens);
                    }
                    $tokens[] = '?';
                }

                continue;
            }
            if (ctype_digit($char) || ($char === '.' && ctype_digit($sql[$p + 1] ?? ''))) {
                preg_match('/\G(?:0[xX][0-9a-fA-F]+|0[bB][01]+|(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?)/A', $sql, $match, 0, $p);
                if (! $match) {
                    return ['sql_state' => 'unsupported'];
                }
                $p += strlen($match[0]);
                if (isset($sql[$p]) && preg_match('/[A-Za-z_]/', $sql[$p])) {
                    return ['sql_state' => 'unsupported'];
                }
                $tokens[] = '?';

                continue;
            }
            if ($char === ':' && preg_match('/\G:[A-Za-z_][A-Za-z0-9_]*/A', $sql, $match, 0, $p)) {
                $p += strlen($match[0]);
                $tokens[] = '?';

                continue;
            }
            if (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/A', $sql, $match, 0, $p)) {
                $word = $match[0];
                $p += strlen($word);
                if (strlen($word) > 128) {
                    return ['sql_state' => 'unsupported'];
                }
                $tokens[] = in_array(strtoupper($word), ['NULL', 'TRUE', 'FALSE'], true) ? '?' : $word;

                continue;
            }
            if (! str_contains('?,.()=<>!+-*/%|&^~', $char)) {
                return ['sql_state' => 'unsupported'];
            }
            if ($char === '(') {
                $depth++;
            }
            if ($char === ')' && --$depth < 0) {
                return ['sql_state' => 'unsupported'];
            }
            $tokens[] = $char;
            $p++;
        }
        if ($depth !== 0 || ! $tokens || ! in_array(strtoupper($tokens[0]), ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) {
            return ['sql_state' => 'unsupported'];
        }
        $template = str_replace(['( ', ' )', ' ,', ' . ', '< =', '> =', '! =', '< >'], ['(', ')', ',', '.', '<=', '>=', '!=', '<>'], implode(' ', $tokens));
        if (strlen($template) > max(128, min(4096, $maxBytes))) {
            return ['sql_state' => 'too_large'];
        }

        return ['sql_template' => $template, 'sql_state' => 'retained'];
    }
}
