<?php

declare(strict_types=1);

use Blueprint\Blueprint;

it('adds tagged casts for the columns that ask for them, and nothing else', function () {
    $this->build($this->draft());
    $model = read('app/Models/ContactType.php');

    expect($model)
        ->toContain("'name' => Normalize::using('trim', 'upper'), // @generated normalize")
        ->toContain("'notes' => NormalizeOnSet::using('squish', 'limit:500;...'), // @generated normalize")
        ->toContain('use Dakotajune\Normalize\Casts\Normalize;')
        ->toContain('use Dakotajune\Normalize\Casts\NormalizeOnSet;')
        ->not->toContain("'sort' => Normalize")
        ->not->toContain("'published_at' => Normalize")
        ->and(lints($model))->toBeTrue()
        ->and(read('app/Models/Plain.php'))->not->toContain('@generated normalize')->not->toContain('Normalize');
});

it('lists the models it edited as updated', function () {
    $output = $this->build($this->draft());

    expect($output['updated'])->toContain('app/Models/ContactType.php')
        ->not->toContain('app/Models/Plain.php');
});

it('does not leak into the column modifiers Blueprint hands on to migrations', function () {
    $blueprint = $this->app->make(Blueprint::class);
    $tree = $blueprint->analyze($this->draft());
    $column = $tree->toArray()['models']['ContactType']->columns()['name'];

    expect($column->modifiers())->toBe([])
        ->and($column->dataType())->toBe('string');
});

it('works on a column that names no type, and on every string type', function (string $definition) {
    $this->build($this->draft(['Account' => ['title' => $definition]]));

    expect(read('app/Models/Account.php'))->toContain("'title' => Normalize::using('trim'), // @generated normalize");
})->with([
    'no type' => ['normalize:trim'],
    'string' => ['string normalize:trim'],
    'with a length and nullable' => ['string:100 nullable normalize:trim'],
    'char' => ['char:4 normalize:trim'],
    'text' => ['text normalize:trim'],
    'mediumtext' => ['mediumtext normalize:trim'],
    'longtext' => ['longtext normalize:trim'],
    'modifier first' => ['normalize:trim string'],
    'upper case modifier' => ['string NORMALIZE:trim'],
]);

it('keeps spaces inside quoted arguments', function () {
    $this->build($this->draft(['Account' => ['title' => 'string normalize:"replace:a b;c"']]));

    expect(read('app/Models/Account.php'))->toContain("Normalize::using('replace:a b;c')");
});

it('is identical on every rebuild', function () {
    $this->build($this->draft());
    $first = snapshot();

    $this->build($this->draft(), ['normalize']);
    expect(snapshot())->toBe($first);

    $this->build($this->draft());
    expect(snapshot())->toBe($first);
});

it('follows the draft when only normalize runs', function () {
    $this->build($this->draft());

    $draft = $this->draft();
    $draft['models']['ContactType']['name'] = 'string normalize:trim,lower';
    $draft['models']['ContactType']['notes'] = 'text nullable';

    $this->build($draft, ['normalize']);
    $model = read('app/Models/ContactType.php');

    expect($model)->toContain("Normalize::using('trim', 'lower')")
        ->not->toContain("'upper'")
        ->not->toContain('NormalizeOnSet')
        ->and(lints($model))->toBeTrue();
});

it('takes the entries out again when the draft stops asking for them', function () {
    $this->build($this->draft());

    $draft = $this->draft();
    $draft['models']['ContactType']['name'] = 'string';
    $draft['models']['ContactType']['notes'] = 'text nullable';

    $this->build($draft, ['normalize']);
    $model = read('app/Models/ContactType.php');

    expect($model)->not->toContain('@generated normalize')
        ->not->toContain('Dakotajune\Normalize')
        ->and(file_exists(base_path('.blueprint_normalize')))->toBeFalse();
});

it('leaves a cast the developer wrote for the same column alone', function () {
    $this->build($this->draft(['Account' => ['title' => 'string']]));
    $path = base_path('app/Models/Account.php');
    file_put_contents($path, str_replace("'id' => 'integer',", "'id' => 'integer',\n            'title' => 'encrypted',", file_get_contents($path)));
    $before = file_get_contents($path);

    $this->build($this->draft(['Account' => ['title' => 'string normalize:trim']]), ['normalize']);

    expect(file_get_contents($path))->toBe($before)
        ->and(array_filter($this->notices(), fn (string $n) => str_contains($n, 'already has a cast of its own')))->toHaveCount(1);
});

it('says so when the model file is not there yet', function () {
    $this->build($this->draft(), ['normalize']);

    expect(array_filter($this->notices(), fn (string $n) => str_contains($n, 'ContactType was not normalized')))->toHaveCount(1)
        ->and(file_exists(base_path('app/Models/ContactType.php')))->toBeFalse();
});

it('does nothing for a draft without the modifier', function () {
    $draft = $this->draft(['Plain' => ['label' => 'string']]);
    $output = $this->build($draft);

    expect($output['updated'] ?? [])->not->toContain('app/Models/Plain.php')
        ->and(file_exists(base_path('.blueprint_normalize')))->toBeFalse();
});

it('rejects a column that is not a string', function (string $definition, string $type) {
    expect(fn () => $this->build($this->draft(['Account' => ['amount' => $definition]])))
        ->toThrow(InvalidArgumentException::class, "is a {$type} column");
})->with([
    'integer' => ['integer normalize:trim', 'integer'],
    'boolean' => ['boolean normalize:trim', 'boolean'],
    'enum' => ['enum:a,b normalize:upper', 'enum'],
    'timestamp' => ['timestamp normalize:trim', 'timestamp'],
    'json' => ['json normalize:trim', 'json'],
]);

it('rejects bad pipelines when the draft is read', function (string $definition, string $message) {
    expect(fn () => $this->build($this->draft(['Account' => ['title' => $definition]])))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown operation' => ['string normalize:trim,uper', 'Stringable has no [uper] method'],
    'bare modifier' => ['string normalize', 'needs operations'],
    'bare modifier, write only' => ['string normalize_on_set', 'needs operations'],
    'empty operation' => ['string normalize:trim,,upper', 'empty operation'],
    'both modifiers' => ['string normalize:trim normalize_on_set:upper', 'either normalize: or normalize_on_set:'],
    'twice' => ['string normalize:trim normalize:upper', 'either normalize: or normalize_on_set:'],
    'terminal method' => ['string normalize:trim,length', 'must return a Stringable'],
    'runs code' => ['string normalize:pipe:strtoupper', 'cannot be used as an operation'],
    'not idempotent' => ['string normalize:reverse', 'normalize_on_set'],
    'limit on read' => ['text normalize:squish,limit:100;...', 'normalize_on_set'],
]);

it('accepts the write-only modifier for what normalize: refuses', function () {
    $this->build($this->draft(['Account' => ['title' => 'string normalize_on_set:reverse', 'bio' => 'text normalize_on_set:squish,limit:100;...']]));

    expect(read('app/Models/Account.php'))
        ->toContain("'title' => NormalizeOnSet::using('reverse'), // @generated normalize")
        ->toContain("'bio' => NormalizeOnSet::using('squish', 'limit:100;...'), // @generated normalize");
});

it('puts a casts() method on a model that has none', function () {
    $this->build($this->draft(['Account' => ['title' => 'string']]));
    $path = base_path('app/Models/Account.php');

    // Make the model one that Blueprint left without casts.
    $withoutCasts = (string) preg_replace('~\r?\n    /\*\*\n     \* Get the attributes that should be cast.*?\n    }\r?\n~s', '', file_get_contents($path));
    file_put_contents($path, $withoutCasts);

    $this->build($this->draft(['Account' => ['title' => 'string normalize:trim']]), ['normalize']);
    $model = file_get_contents($path);

    expect($model)->toContain("'title' => Normalize::using('trim'), // @generated normalize")
        ->toContain('protected function casts(): array')
        ->and(lints($model))->toBeTrue();

    $snapshot = snapshot();
    $this->build($this->draft(['Account' => ['title' => 'string normalize:trim']]), ['normalize']);
    expect(snapshot())->toBe($snapshot);
});

it('reads the modifier from real draft.yaml text', function () {
    if (! class_exists(Symfony\Component\Yaml\Parser::class)) {
        return;                                         // only the real YAML parser can show this
    }

    $yaml = <<<'YAML'
models:
  Account:
    title: string normalize:trim,upper
    bio: text nullable normalize_on_set:squish,limit:500;...
    note: string:100 normalize:"replace:a b;c"
YAML;

    $this->build($this->app->make(Blueprint::class)->parse($yaml));
    $model = read('app/Models/Account.php');

    expect($model)
        ->toContain("'title' => Normalize::using('trim', 'upper'), // @generated normalize")
        ->toContain("'bio' => NormalizeOnSet::using('squish', 'limit:500;...'), // @generated normalize")
        ->toContain("'note' => Normalize::using('replace:a b;c'), // @generated normalize")
        ->and(lints($model))->toBeTrue();
});
