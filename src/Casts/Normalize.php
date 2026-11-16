<?php

namespace Dakotajune\Normalize\Casts;

use BadMethodCallException;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use UnexpectedValueException;

/**
 * Eloquent attribute cast that runs a pipeline of Stringable operations over a string attribute.
 *
 * Each operation is the name of a method on {@see Stringable}, optionally followed by a colon
 * and a semicolon-separated list of arguments: 'limit:50;...' calls $string->limit('50', '...').
 * Operations run in the order they are declared, each receiving the result of the previous one.
 *
 * Rules and gotchas:
 * - Arguments are always passed as strings and cannot contain ',' or ';' (those are delimiters).
 * - Trailing semicolons pass an empty string argument (e.g., 'limit:5;' sets $end = '').
 * - Operations must be idempotent, as they execute on both get() and set(). limit() is not, for text
 *   that is cut right after a space: reading it back can add extra dots. Use NormalizeOnSet for it.
 * - Non-string values (null, ints, arrays, ...) bypass the pipeline untouched.
 * - Every operation must return a Stringable, so terminal methods such as length(), isEmpty()
 *   or explode() cannot be used.
 *
 * In a Blueprint draft this cast is written for you: `name: string normalize:trim,upper`.
 * Use {@see NormalizeOnSet} when the pipeline is not safe to run twice, or when only writes should change.
 *
 * Usage in a model:
 *
 * ```php
 * protected function casts(): array
 * {
 *     return [
 *         'email' => Normalize::using('trim', 'lower'),
 *         'bio'   => NormalizeOnSet::using('squish', 'limit:500;...'),
 *     ];
 * }
 * ```
 *
 * @implements CastsAttributes<mixed, mixed>
 *
 * @see https://laravel.com/docs/eloquent-mutators#custom-casts
 * @see \Illuminate\Support\Stringable For the available operations.
 */
class Normalize implements CastsAttributes
{
    /**
     * The parsed operation pipeline, in execution order.
     *
     * Each entry is a [method name, arguments] tuple, e.g. ['limit', ['50', '...']].
     *
     * @var array<int, array{0: string, 1: array<int, string>}>
     */
    protected array $operations;

    /**
     * Parse the raw operation strings into a method/arguments pipeline.
     *
     * Laravel instantiates this class for you when it sees a cast string such as
     * 'Dakotajune\Normalize\Casts\Normalize:trim,limit:50;...'. The part after the first colon is split on commas
     * and arrives here as separate string arguments.
     *
     * @param  string  ...$operations  Operations such as 'trim', 'lower' or 'limit:50;...'.
     */
    public function __construct(string ...$operations)
    {
        // We were handed a list of instructions written as short text, like "limit:50;...".
        // Computers can't run text directly, so we translate each one into a tidy pair:
        // "which trick to do" and "what to give that trick". We do this once up front
        // so we don't have to re-read the text every time a value is saved or loaded.
        $this->operations = array_map(function (string $operation): array {
            // Cut the instruction at the FIRST colon only. Left side = the trick's name,
            // right side = its ingredients. array_pad guarantees two slots, so an instruction
            // with no colon (like "trim") gets null for the ingredients instead of an error.
            [$method, $argString] = array_pad(explode(':', $operation, 2), 2, null);

            // If there were ingredients, split them at each semicolon into a list.
            // If there were none, the list is empty, so the trick gets nothing extra.
            return [$method, $argString !== null ? explode(';', $argString) : []];
        }, $operations);
    }

    /**
     * Build a cast definition string for use in a model's casts() method.
     *
     * Using this helper instead of a hand-written string gives you autocomplete and avoids typos
     * in the class name. Because it relies on late static binding, subclasses of this cast return
     * their own class name.
     *
     * @param  string  ...$operations  Operations such as 'trim', 'lower' or 'limit:50;...'.
     * @return string The cast definition, e.g. 'Dakotajune\Normalize\Casts\Normalize:trim,lower'.
     */
    public static function using(string ...$operations): string
    {
        // Laravel wants casts written as "ClassName:argument1,argument2".
        // This glues the class name and your operations together in exactly that shape,
        // so you don't have to remember the format. static::class means "whichever class
        // was actually called", which keeps this working if someone extends this one.
        return static::class . ':' . implode(',', $operations);
    }

    /**
     * Transform the value as it is read from the model.
     *
     * @param  Model  $model  The model the attribute belongs to (unused).
     * @param  string  $key  The attribute name.
     * @param  mixed  $value  The raw value from the database.
     * @param  array<string, mixed>  $attributes  All raw attributes of the model (unused).
     * @return mixed The normalized value, or the original value if it is not a string.
     *
     * @throws UnexpectedValueException If an operation does not return a Stringable.
     * @throws BadMethodCallException If an operation names a method Stringable does not have.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        // Someone is reading this field off the model. We clean the value on the way out,
        // so even old, messy rows already in the database look tidy to the rest of the app.
        return $this->apply($key, $value);
    }

    /**
     * Transform the value as it is written to the model.
     *
     * @param  Model  $model  The model the attribute belongs to (unused).
     * @param  string  $key  The attribute name.
     * @param  mixed  $value  The value being assigned.
     * @param  array<string, mixed>  $attributes  All raw attributes of the model (unused).
     * @return mixed The normalized value to persist, or the original value if it is not a string.
     *
     * @throws UnexpectedValueException If an operation does not return a Stringable.
     * @throws BadMethodCallException If an operation names a method Stringable does not have.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        // Someone is saving a value into this field. We clean it on the way in,
        // so the database stores the tidy version. It's the same cleaning as get() on purpose.
        // That's why every operation must be safe to run twice: cleaning something already
        // clean must change nothing.
        return $this->apply($key, $value);
    }

    /**
     * Run the configured operation pipeline over a value.
     *
     * @param  string  $key  The attribute name, used only for error messages.
     * @param  mixed  $value  The value to normalize.
     * @return mixed The normalized string, or the untouched value if it is not a string.
     *
     * @throws UnexpectedValueException If an operation does not return a Stringable.
     * @throws BadMethodCallException If an operation names a method Stringable does not have.
     */
    protected function apply(string $key, mixed $value): mixed
    {
        // Our cleaning tricks only know how to handle text. If we were given something
        // else (like null for an empty field, or a number), we hand it straight back
        // instead of breaking it.
        if (! is_string($value)) {
            return $value;
        }

        // Wrap the plain text in Laravel's "Stringable" box. The box has lots of
        // handy tricks (trim, lower, limit, ...) that we can call on the text.
        $string = Str::of($value);

        // Go through our to-do list one step at a time, in order. Each step takes the
        // text as the previous step left it, so "trim" then "lower" means trim first, then lowercase.
        foreach ($this->operations as [$method, $args]) {
            // Look up the trick by its name and run it, handing over its ingredients.
            // The "..." unpacks the list so ['50', '...'] becomes limit('50', '...').
            $string = $string->{$method}(...$args);

            // Safety check. Our pipeline needs every step to give back another
            // Stringable box so the next step has something to work on. If a trick returned
            // something else (a number from length(), a yes/no from isEmpty()), we stop loudly
            // and say which trick on which field went wrong, rather than failing mysteriously later.
            if (! $string instanceof Stringable) {
                throw new UnexpectedValueException(
                    "Normalize operation [{$method}] on [{$key}] must return a Stringable instance."
                );
            }
        }

        // All steps done. Take the text out of the Stringable box and return it
        // as a plain string, which is what the model and database expect.
        return (string) $string;
    }
}
