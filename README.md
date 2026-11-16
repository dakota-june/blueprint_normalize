# dakotajune/normalize

An Eloquent cast that cleans string attributes with [`Stringable`](https://laravel.com/docs/strings) operations, and a `normalize:` column modifier for [Laravel Blueprint](https://blueprint.laravelshift.com) drafts.

```yaml
models:
  ContactType:
    name: string normalize:trim,upper
    notes: text nullable normalize_on_set:squish,limit:500;...
```

`php artisan blueprint:build` writes the model as usual, then adds the casts:

```php
protected function casts(): array
{
    return [
        'id' => 'integer',
        'name' => Normalize::using('trim', 'upper'), // @generated normalize
        'notes' => NormalizeOnSet::using('squish', 'limit:500;...'), // @generated normalize
    ];
}
```

The migration is untouched (`name` stays a plain `string('name')`). Without Blueprint, use the cast by hand: it needs only Laravel.

## The cast

```php
use Dakotajune\Normalize\Casts\Normalize;

protected function casts(): array
{
    return [
        'email' => Normalize::using('trim', 'lower'),
        'code'  => Normalize::using('trim', 'upper'),
    ];
}
```

Each operation is the name of a `Stringable` method, optionally followed by `:` and `;`-separated arguments (`limit:50;...` calls `limit('50', '...')`). Operations run in order. Values that are not strings (null, numbers, arrays) pass through untouched.

| Class | Runs on | Operations must be idempotent |
| --- | --- | :-: |
| `Normalize` | write **and** read | yes |
| `NormalizeOnSet` | write only | no |

Reading with `Normalize` tidies rows that were stored before the cast existed. That is why its operations must give the same result when applied twice. **`limit()` does not**, for text that is cut right after a space: reading it back can add extra dots. Use `NormalizeOnSet` for `limit`, `reverse`, `append` and anything similar.

## In draft.yaml

```yaml
name: string normalize:trim,upper                 # write and read
notes: text normalize_on_set:squish,limit:500;... # write only
code: string:12 unique normalize:trim,upper       # next to other modifiers
title: string normalize:"replace:a b;c"           # quote when an argument has a space
```

| Rule | |
| --- | --- |
| Column types | `string`, `char`, `tinyText`, `text`, `mediumText`, `longText`. Any other type stops the build. |
| Operations | separated by commas; arguments by semicolons, so an argument cannot contain `,` or `;` |
| One modifier per column | `normalize:` or `normalize_on_set:`, not both |
| YAML | write the column on one line; an argument cannot contain a space followed by `#`, or a colon followed by a space |

### Checked when the draft is read

A bad pipeline stops `blueprint:build` with a message that names the column, instead of failing when a row is saved. Each operation must:

- exist on `Stringable` (macros you registered count);
- hand a `Stringable` back, so `length`, `isEmpty`, `explode` and the like are refused;
- not be `pipe`, `tap`, `dump`, `dd`, `macro`, `when*` or `unless*`;
- for `normalize:`, give the same result when applied twice, tried on sample strings. Numbers in the arguments are tried too, so `limit:50;...` is tested with a space at character 50. The message tells you to use `normalize_on_set:`.

Add your own refusals and sample strings in `config/normalize.php`.

## Who owns what

Generated entries carry `// @generated normalize`. Blueprint rewrites the model on every build and the entries are written again afterwards, in the same place, so a rebuild changes nothing. Entries you wrote yourself are never touched. If a column already has a cast of its own, it is left alone and a notice says so.

| You change | What happens |
| --- | --- |
| the operations in the draft | the tagged entry is rewritten |
| `normalize:` is removed from a column | its entry is deleted (also with `--only=normalize`) |
| a model has no `casts()` method | one is added; it goes again with its last generated entry |
| you add entries to a method we added | the method stays; only our tag leaves its docblock |
| a model still has the older `$casts` property | the entries go there as `Normalize::class . ':trim,upper'` |

`php artisan blueprint:erase` takes the entries, the method we added and the imports out again (recorded in `.blueprint_normalize`), leaving the model as Blueprint wrote it.

Run only this step with `php artisan blueprint:build --only=normalize` (after the models exist).

## Things to know

- **Casts apply to model writes only.** `DB::table()->insert()`, `upsert()` and bulk `Model::query()->update()` skip them.
- **Lookups are not normalized.** `where('name', 'foo')` will not match a stored `FOO`. Normalize the search value the same way.
- **Validation runs before the cast.** A `unique` rule sees the raw input.
- **Arguments are strings.** `limit:50` passes `'50'`; Laravel's own methods accept that.

## Config

```bash
php artisan vendor:publish --tag=normalize-config
```

`denied` (extra method names to refuse), `samples` (extra strings to try), `manifest` (where the erase record lives).

## Development

```bash
composer install
vendor/bin/pest
```
