<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Support;

use Dakotajune\Normalize\Casts\Normalize;
use Dakotajune\Normalize\Casts\NormalizeOnSet;
use Dakotajune\Normalize\Support\Concerns\LocatesMembers;
use Dakotajune\Normalize\Support\Concerns\ReadsTokens;
use Dakotajune\Normalize\Support\Concerns\RemovesImports;
use Dakotajune\Normalize\Support\Concerns\ResolvesNames;
use PhpToken;

/**
 * Keeps tagged cast entries in a model's casts() method (or `$casts` property on older layouts):
 *
 *     'name' => Normalize::using('trim', 'upper'), // @generated normalize
 *
 * Only lines carrying the tag are ever touched; every other entry is the developer's. Each run removes our
 * lines and writes the current set at the end of the array, so a rebuild is byte-identical and a column that
 * left the draft loses its entry. A column that already has an untagged cast is left alone, with a notice.
 *
 * When the model has no casts() method (Blueprint omits it when no column needs a cast) one is added, with a
 * tagged docblock so that it can be removed again with its last generated entry.
 */
final class ModelCastEditor
{
    use LocatesMembers;
    use ReadsTokens;
    use RemovesImports;
    use ResolvesNames;

    public const MARKER = '@generated normalize';

    public const USE_PATTERN = '/\{\{ use:([A-Za-z_][A-Za-z0-9_\\\\]*) \}\}/';

    /** A whole line that ends in an array entry followed by our tag. */
    private const TAG_LINE = '~^[ \t]*\S[^\n]*,[ \t]*// @generated normalize[ \t]*\n?~m';

    private const DOC_TAG_LINE = '~^[ \t]*\* @generated normalize[^\n]*\n~m';

    /**
     * @param  array<string, array{operations: array<int, string>, onSet: bool}>  $columns  column => cast
     */
    public function update(string $source, string $class, array $columns): EditResult
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $body = $classIdx === null ? null : $this->classBody($tokens, $classIdx);

        if ($classIdx === null || $body === null) {
            return new EditResult($source, false, ["Class [{$class}] was not found, so its casts were not updated."]);
        }

        [$classOpen, $classClose] = $body;
        $target = $this->castsArray($tokens, $classOpen, $classClose);

        if (is_string($target)) {
            return new EditResult($source, false, ["{$class}: {$target}"]);
        }

        $namespace = $this->namespaceOf($source);
        $known = $this->imports($tokens, $classIdx) + [$class => ltrim(($namespace !== null ? $namespace . '\\' : '') . $class, '\\')];
        $newImports = [];
        $notices = [];
        $edits = [];

        if ($target === null) {
            // No casts() method and no $casts property: add the method (only when there is something to put in it).
            if ($columns === []) {
                return new EditResult($source, false);
            }

            $lines = $this->lines($columns, 'method', '            ', [], $class, $namespace, $known, $newImports, $notices);

            if ($lines === '') {
                return new EditResult($source, false, $notices);
            }

            $closePos = $tokens[$classClose]->pos;
            $prefix = rtrim(substr($source, 0, $closePos));
            $edits[] = [strlen($prefix), $closePos - strlen($prefix), (str_ends_with($prefix, '{') ? "\n" : "\n\n") . $this->method($lines) . "\n"];
        } else {
            $open = $tokens[$target['open']];
            $sliceStart = $open->pos + 1;
            $sliceLength = $tokens[$target['close']]->pos - $sliceStart;
            $slice = substr($source, $sliceStart, $sliceLength);
            $cleaned = (string) preg_replace(self::TAG_LINE, '', $slice);

            if (trim($cleaned) !== '' && ! str_contains($cleaned, "\n")) {
                return new EditResult($source, false, ["{$class}: the cast list is written on one line, so the generated casts were not added. Put one entry per line, or add them by hand."]);
            }

            $leading = $this->leadingWhitespace($source, $tokens[$target['anchor']]->pos);
            $lines = $this->lines($columns, $target['kind'], $leading . '    ', $this->keys($cleaned), $class, $namespace, $known, $newImports, $notices);

            if ($lines === '') {
                $replacement = trim($cleaned) === '' ? '' : $cleaned;
            } elseif (trim($cleaned) === '') {
                $replacement = "\n" . $lines . $leading;
            } else {
                $head = rtrim($cleaned);
                $replacement = (str_ends_with($head, ',') ? $head : $head . ',') . "\n" . $lines . $leading;
            }

            $edits[] = [$sliceStart, $sliceLength, $replacement];
        }

        if ($newImports !== []) {
            $edits[] = $this->importEdit($tokens, $classIdx, array_values(array_unique($newImports)));
        }

        $result = $this->apply($source, $edits);

        if ($this->hasTagged($source)) {
            // A cast class that no entry refers to any more (a column went from normalize_on_set: to normalize:)
            // leaves its import behind; take it out. Only when we had written entries before.
            $result = $this->removeImportsIfUnused($result, [Normalize::class, NormalizeOnSet::class]);
        }

        return new EditResult($result, $result !== $source, $notices);
    }

    /**
     * Removes every tagged entry, the casts() method we added if nothing else is in it, and the imports we needed.
     *
     * @param  array<int, string>  $uses  Class names the entries referred to
     */
    public function strip(string $source, string $class, array $uses = []): string
    {
        $result = $this->update($source, $class, [])->source;
        $result = $this->removeCreatedMethod($result, $class);

        return $result === $source ? $source : $this->removeImportsIfUnused($result, $uses);
    }

    /** True when the source still carries tagged entries. */
    public function hasTagged(string $source): bool
    {
        return (bool) preg_match(self::TAG_LINE, $source);
    }

    /**
     * @param  array<string, array{operations: array<int, string>, onSet: bool}>  $columns
     * @param  array<int, string>  $existingKeys
     * @param  array<string, string>  $known
     * @param  array<int, string>  $newImports
     * @param  array<int, string>  $notices
     */
    private function lines(array $columns, string $kind, string $indent, array $existingKeys, string $class, ?string $namespace, array &$known, array &$newImports, array &$notices): string
    {
        $lines = '';

        foreach ($columns as $column => $cast) {
            if (in_array($column, $existingKeys, true)) {
                $notices[] = "{$class}: [{$column}] already has a cast of its own, so normalize was not applied. Remove that cast to let the draft decide.";

                continue;
            }

            $castClass = $this->expand('{{ use:' . ($cast['onSet'] ? NormalizeOnSet::class : Normalize::class) . ' }}', $namespace, $known, $newImports);

            $value = $kind === 'method'
                ? $castClass . '::using(' . implode(', ', array_map($this->quote(...), $cast['operations'])) . ')'
                : $castClass . "::class . '" . addcslashes(':' . implode(',', $cast['operations']), "'\\") . "'";

            $lines .= $indent . $this->quote((string) $column) . ' => ' . $value . ', // ' . self::MARKER . "\n";
        }

        return $lines;
    }

    private function quote(string $value): string
    {
        return "'" . addcslashes($value, "'\\") . "'";
    }

    private function method(string $lines): string
    {
        return "    /**\n"
            . "     * Get the attributes that should be cast.\n"
            . "     *\n"
            . "     * @return array<string, string>\n"
            . '     * ' . self::MARKER . " Removed with its last generated cast. Delete this tag to keep the method.\n"
            . "     */\n"
            . "    protected function casts(): array\n"
            . "    {\n"
            . "        return [\n"
            . $lines
            . "        ];\n"
            . '    }';
    }

    /** Keys that are already written in the array text, one entry per line. @return array<int, string> */
    private function keys(string $array): array
    {
        preg_match_all('~^\s*([\'"])((?:(?!\1).)*)\1\s*=>~m', $array, $matches);

        return $matches[2];
    }

    /**
     * Where the casts live.
     *
     * @param  array<int, PhpToken>  $tokens
     * @return array{kind: string, open: int, close: int, anchor: int}|string|null  null = no casts yet, string = why we cannot edit it
     */
    private function castsArray(array $tokens, int $classOpen, int $classClose): array|string|null
    {
        $method = $this->locateMethod($tokens, $classOpen, $classClose, 'casts');

        if ($method !== null) {
            [, , , $braceOpen, $braceClose] = $method;
            $return = $this->nextSignificant($tokens, $braceOpen);
            $bracket = $return === null ? null : $this->nextSignificant($tokens, $return);

            if ($return === null || $return >= $braceClose || ! $tokens[$return]->is(T_RETURN)
                || $bracket === null || $tokens[$bracket]->text !== '[') {
                return 'casts() does not simply return an array literal, so the generated casts were not added. Add them by hand.';
            }

            $close = $this->matchBracket($tokens, $bracket);
            $after = $close === null ? null : $this->nextSignificant($tokens, $close);

            if ($close === null || $after === null || $tokens[$after]->text !== ';') {
                return 'casts() does not simply return an array literal, so the generated casts were not added. Add them by hand.';
            }

            return ['kind' => 'method', 'open' => $bracket, 'close' => $close, 'anchor' => $return];
        }

        $depth = 0;

        for ($i = $classOpen; $i < $classClose; $i++) {
            if ($tokens[$i]->text === '{') {
                $depth++;
            } elseif ($tokens[$i]->text === '}') {
                $depth--;
            }

            if ($depth !== 1 || ! $tokens[$i]->is(T_VARIABLE) || $tokens[$i]->text !== '$casts') {
                continue;
            }

            $equals = $this->nextSignificant($tokens, $i);
            $bracket = $equals === null ? null : $this->nextSignificant($tokens, $equals);

            if ($equals === null || $tokens[$equals]->text !== '=' || $bracket === null || $tokens[$bracket]->text !== '[') {
                return 'the $casts property is not an array literal, so the generated casts were not added. Add them by hand.';
            }

            $close = $this->matchBracket($tokens, $bracket);

            return $close === null
                ? 'the $casts property could not be read, so the generated casts were not added.'
                : ['kind' => 'property', 'open' => $bracket, 'close' => $close, 'anchor' => $i];
        }

        return null;
    }

    /** Removes the casts() method this package added, once nothing but its tag is left in it. */
    private function removeCreatedMethod(string $source, string $class): string
    {
        $tokens = PhpToken::tokenize($source);
        $classIdx = $this->findClass($tokens, $class);
        $body = $classIdx === null ? null : $this->classBody($tokens, $classIdx);
        $method = $body === null ? null : $this->locateMethod($tokens, $body[0], $body[1], 'casts');

        if ($method === null) {
            return $source;
        }

        [$functionIdx, , , $braceOpen, $braceClose] = $method;
        $docIdx = $this->docblockOf($tokens, $functionIdx);

        if ($docIdx === null || ! str_contains($tokens[$docIdx]->text, self::MARKER)) {
            return $source;                                     // Blueprint's or the developer's own method
        }

        $inner = substr($source, $tokens[$braceOpen]->pos + 1, $tokens[$braceClose]->pos - $tokens[$braceOpen]->pos - 1);

        if (! preg_match('~^\s*return\s*\[\s*\]\s*;\s*$~', $inner)) {
            // The developer added entries to it: keep the method, drop only our tag from its docblock.
            $doc = $tokens[$docIdx];

            return substr_replace($source, (string) preg_replace(self::DOC_TAG_LINE, '', $doc->text), $doc->pos, strlen($doc->text));
        }

        $start = $this->lineStart($source, $tokens[$docIdx]->pos);
        $end = $tokens[$braceClose]->pos + 1;

        if (($source[$end] ?? '') === "\n") {
            $end++;
        }

        if ($start >= 2 && $source[$start - 1] === "\n" && $source[$start - 2] === "\n") {
            $start--;                                           // the blank line we put above it
        }

        return substr_replace($source, '', $start, $end - $start);
    }
}
