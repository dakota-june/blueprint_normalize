<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Support;

use Dakotajune\Normalize\Casts\Normalize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Stringable;
use InvalidArgumentException;
use Throwable;

/**
 * Checks a pipeline when the draft is read instead of when a row is saved: every operation must exist on
 * Stringable, must hand a Stringable back, must not be one of the methods that run code or print, and (for the
 * cast that also runs on read) must give the same result when applied twice.
 *
 * "Applied twice" is tested on sample strings, plus strings built around each number in the arguments so that
 * `limit:50;...` is tried with a space at character 50. That is the case where limit() is not idempotent.
 */
final class OperationValidator
{
    /** Methods that run callbacks, print, or change Stringable itself. */
    public const DENIED = [
        'pipe', 'tap', 'dump', 'dd', 'ddd', 'macro', 'mixin', 'flushMacros', 'hasMacro',
        'jsonSerialize', 'toString', 'value', 'getIterator',
    ];

    private const SAMPLES = [
        '  Hello   World  ',
        "\tMixed CASE  text\n",
        'ÄÖÜ straße é',
        'a,b;c:d',
        '',
        '   ',
        'word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word word ',
        'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
    ];

    private ?Model $model = null;

    /**
     * @param  array<int, string>  $denied  extra method names to refuse
     * @param  array<int, string>  $samples  extra strings to try
     */
    public function __construct(
        private readonly array $denied = [],
        private readonly array $samples = [],
    ) {}

    /**
     * @param  array<int, string>  $operations
     * @param  string  $where  "Model.column", for messages
     *
     * @throws InvalidArgumentException
     */
    public function validate(array $operations, bool $mustBeIdempotent, string $where): void
    {
        if ($operations === []) {
            throw new InvalidArgumentException("{$where}: no operations were given.");
        }

        foreach ($operations as $operation) {
            $method = explode(':', $operation, 2)[0];

            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method)) {
                throw new InvalidArgumentException("{$where}: [{$operation}] is not an operation. Write a Stringable method name, optionally followed by :arg;arg.");
            }

            if ($this->isDenied($method)) {
                throw new InvalidArgumentException("{$where}: [{$method}] cannot be used as an operation.");
            }

            if (! method_exists(Stringable::class, $method) && ! Stringable::hasMacro($method)) {
                throw new InvalidArgumentException("{$where}: Stringable has no [{$method}] method.");
            }
        }

        $column = explode('.', $where, 2)[1] ?? $where;
        $samples = $this->samplesFor($operations);

        foreach ($samples as $sample) {
            $once = $this->run($operations, $column, $sample, $where);

            if ($mustBeIdempotent && $this->run($operations, $column, $once, $where) !== $once) {
                throw new InvalidArgumentException(
                    "{$where}: [" . implode(',', $operations) . '] does not give the same result when it is applied twice '
                    . '(for example on ' . json_encode(mb_substr($sample, 0, 40), JSON_UNESCAPED_UNICODE) . '), '
                    . 'but normalize: also runs when the value is read. Use normalize_on_set: so it runs only on write.'
                );
            }
        }
    }

    private function isDenied(string $method): bool
    {
        $lower = strtolower($method);

        if (str_starts_with($lower, '__') || str_starts_with($lower, 'when') || str_starts_with($lower, 'unless')) {
            return true;
        }

        return in_array($lower, array_map('strtolower', [...self::DENIED, ...$this->denied]), true);
    }

    /**
     * @param  array<int, string>  $operations
     *
     * @throws InvalidArgumentException
     */
    private function run(array $operations, string $column, string $value, string $where): string
    {
        $this->model ??= new class extends Model {};

        try {
            return (string) (new Normalize(...$operations))->set($this->model, $column, $value, []);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("{$where}: [" . implode(',', $operations) . '] failed on a sample string: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @param  array<int, string>  $operations
     * @return array<int, string>
     */
    private function samplesFor(array $operations): array
    {
        $samples = [...self::SAMPLES, ...$this->samples];

        foreach ($operations as $operation) {
            foreach (explode(';', explode(':', $operation, 2)[1] ?? '') as $argument) {
                if (! ctype_digit($argument) || (int) $argument < 1 || (int) $argument > 5000) {
                    continue;
                }

                $n = (int) $argument;

                // A space (or two) exactly where a length argument cuts the text.
                $samples[] = str_repeat(str_repeat('a', $n - 1) . ' ', 3);
                $samples[] = str_repeat(str_repeat('a', max(0, $n - 2)) . '  ', 3);
            }
        }

        return $samples;
    }
}
