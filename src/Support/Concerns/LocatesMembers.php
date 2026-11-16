<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Support\Concerns;

use PhpToken;

/** Finds a class body and the methods and docblocks inside it. Needs ReadsTokens. */
trait LocatesMembers
{
    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{0:int,1:int}|null  [open, close] token indexes of the class body braces
     */
    private function classBody(array $tokens, int $classIdx): ?array
    {
        for ($i = $classIdx, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->text === '{') {
                $close = $this->matchBrace($tokens, $i);

                return $close === null ? null : [$i, $close];
            }
        }

        return null;
    }

    /**
     * @param  array<int, PhpToken>  $tokens
     * @return array{0:int,1:int,2:int,3:int,4:int}|null  function, params open, params close, body open, body close
     */
    private function locateMethod(array $tokens, int $classOpen, int $classClose, string $method): ?array
    {
        $depth = 0;

        for ($i = $classOpen; $i < $classClose; $i++) {
            if ($tokens[$i]->text === '{') {
                $depth++;
            } elseif ($tokens[$i]->text === '}') {
                $depth--;
            }

            if ($depth !== 1 || ! $tokens[$i]->is(T_FUNCTION)) {
                continue;                               // only members of this class, not closures inside methods
            }

            $name = $this->nextSignificant($tokens, $i);

            if ($name === null || ! $tokens[$name]->is(T_STRING) || strcasecmp($tokens[$name]->text, $method) !== 0) {
                continue;
            }

            $paramsOpen = $this->nextSignificant($tokens, $name);
            $paramsClose = $paramsOpen === null ? null : $this->matchParenTokens($tokens, $paramsOpen);

            if ($paramsClose === null) {
                return null;
            }

            for ($j = $paramsClose + 1; $j < $classClose; $j++) {
                if ($tokens[$j]->text === ';') {
                    return null;                        // abstract / interface method: no body
                }

                if ($tokens[$j]->text === '{') {
                    $braceClose = $this->matchBrace($tokens, $j);

                    return $braceClose === null ? null : [$i, $paramsOpen, $paramsClose, $j, $braceClose];
                }
            }

            return null;
        }

        return null;
    }

    /** The docblock directly above a method (modifiers in between are skipped), or null. @param  array<int, PhpToken>  $tokens */
    private function docblockOf(array $tokens, int $functionIdx): ?int
    {
        for ($i = $functionIdx - 1; $i >= 0; $i--) {
            $token = $tokens[$i];

            if ($token->is(T_DOC_COMMENT)) {
                return $i;
            }

            if ($token->is([T_WHITESPACE, T_PUBLIC, T_PROTECTED, T_PRIVATE, T_FINAL, T_STATIC, T_ABSTRACT, T_ATTRIBUTE])) {
                continue;
            }

            return null;
        }

        return null;
    }

    /** Leading whitespace of the line that contains $offset, whatever else is on that line. */
    private function leadingWhitespace(string $source, int $offset): string
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");
        $line = substr($source, $lineStart === false ? 0 : $lineStart + 1);

        preg_match('/^[ \t]*/', $line, $m);

        return $m[0];
    }

    /** Offset of the first character of the line that contains $offset. */
    private function lineStart(string $source, int $offset): int
    {
        $lineStart = strrpos(substr($source, 0, $offset), "\n");

        return $lineStart === false ? 0 : $lineStart + 1;
    }

    /**
     * Index of the `]` that closes the `[` at $open, or null.
     *
     * @param  array<int, PhpToken>  $tokens
     */
    private function matchBracket(array $tokens, int $open): ?int
    {
        $depth = 0;

        for ($i = $open, $n = count($tokens); $i < $n; $i++) {
            if ($tokens[$i]->text === '[') {
                $depth++;
            } elseif ($tokens[$i]->text === ']') {
                $depth--;

                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return null;
    }
}
