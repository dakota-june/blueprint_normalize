<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Lexers;

use Blueprint\Contracts\Lexer;
use Dakotajune\Normalize\Models\NormalizedColumn;
use Dakotajune\Normalize\Support\OperationValidator;
use InvalidArgumentException;

/**
 * Reads the `normalize:` and `normalize_on_set:` column modifiers from the `models:` section of the draft.
 *
 *     models:
 *       ContactType:
 *         name: string normalize:trim,upper
 *         bio: text normalize_on_set:squish,limit:500;...
 *
 * Blueprint's own model lexer keeps only the modifiers it knows and silently drops the rest, so nothing here
 * reaches the migration. We read the raw tokens instead. Registry key: `normalize`.
 */
final class NormalizeLexer implements Lexer
{
    public const REGISTRY_KEY = 'normalize';

    private const MODIFIERS = ['normalize' => false, 'normalize_on_set' => true];

    /** Keys of a model that are not columns. */
    private const RESERVED = ['relationships', 'indexes', 'meta'];

    public function __construct(private readonly OperationValidator $validator) {}

    /** @return array<string, array<string, array<string, NormalizedColumn>>> */
    public function analyze(array $tokens): array
    {
        $registry = [];

        foreach ((array) ($tokens['models'] ?? []) as $model => $columns) {
            if (! is_array($columns)) {
                continue;
            }

            foreach ($columns as $column => $definition) {
                if (! is_string($definition) || in_array(strtolower((string) $column), self::RESERVED, true)) {
                    continue;
                }

                $found = $this->modifiers((string) $model, (string) $column, $definition);

                if ($found !== null) {
                    $registry[(string) $model][(string) $column] = $found;
                }
            }
        }

        return [self::REGISTRY_KEY => $registry];
    }

    private function modifiers(string $model, string $column, string $definition): ?NormalizedColumn
    {
        $where = "{$model}.{$column}";
        $found = null;

        // The same split Blueprint uses: on whitespace, but not inside quotes.
        foreach ((array) preg_split('#("|\').*?\1(*SKIP)(*FAIL)|\s+#', $definition) as $token) {
            $token = (string) $token;
            $name = strtolower(explode(':', $token, 2)[0]);

            if (! array_key_exists($name, self::MODIFIERS)) {
                continue;
            }

            if ($found !== null) {
                throw new InvalidArgumentException("{$where}: use either normalize: or normalize_on_set:, once.");
            }

            if (! str_contains($token, ':')) {
                throw new InvalidArgumentException("{$where}: {$name} needs operations, e.g. {$name}:trim,upper.");
            }

            $operations = $this->operations(substr($token, strlen($name) + 1), $where, $name);
            $onSet = self::MODIFIERS[$name];

            $this->validator->validate($operations, ! $onSet, $where);

            $found = new NormalizedColumn($model, $column, $operations, $onSet);
        }

        return $found;
    }

    /** @return array<int, string> */
    private function operations(string $list, string $where, string $modifier): array
    {
        $list = trim($list);

        // normalize:"replace:a b;c" keeps its spaces.
        if (strlen($list) >= 2 && in_array($list[0], ['"', "'"], true) && str_ends_with($list, $list[0])) {
            $list = substr($list, 1, -1);
        }

        $operations = array_map('trim', explode(',', $list));

        if (in_array('', $operations, true)) {
            throw new InvalidArgumentException("{$where}: {$modifier} has an empty operation. Separate operations with single commas.");
        }

        return $operations;
    }
}
