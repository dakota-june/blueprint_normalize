<?php

declare(strict_types=1);

use Dakotajune\Normalize\Support\ModelCastEditor;

const TRIM_UPPER = ['name' => ['operations' => ['trim', 'upper'], 'onSet' => false]];

function blueprintModel(string $casts = "            'id' => 'integer',\n"): string
{
    return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Factories\\HasFactory;\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Thing extends Model\n{\n    use HasFactory;\n\n"
        . "    /**\n     * Get the attributes that should be cast.\n     *\n     * @return array<string, string>\n     */\n    protected function casts(): array\n    {\n        return [\n{$casts}        ];\n    }\n}\n";
}

function modelWithoutCasts(string $members = ''): string
{
    return "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nclass Thing extends Model\n{\n{$members}}\n";
}

it('adds tagged entries and the import to an existing casts() method', function () {
    $result = (new ModelCastEditor())->update(blueprintModel(), 'Thing', TRIM_UPPER);

    expect($result->changed)->toBeTrue()
        ->and($result->source)
        ->toContain("            'id' => 'integer',\n            'name' => Normalize::using('trim', 'upper'), // @generated normalize\n        ];")
        ->toContain("use Illuminate\\Database\\Eloquent\\Model;\nuse Dakotajune\\Normalize\\Casts\\Normalize;")
        ->and(lints($result->source))->toBeTrue();
});

it('is idempotent and follows the draft', function () {
    $editor = new ModelCastEditor();
    $first = $editor->update(blueprintModel(), 'Thing', TRIM_UPPER)->source;

    expect($editor->update($first, 'Thing', TRIM_UPPER)->changed)->toBeFalse();

    $changed = $editor->update($first, 'Thing', ['name' => ['operations' => ['trim', 'lower'], 'onSet' => false]])->source;

    expect($changed)->toContain("Normalize::using('trim', 'lower')")->not->toContain("'upper'");

    $two = $editor->update($changed, 'Thing', TRIM_UPPER + ['bio' => ['operations' => ['squish'], 'onSet' => true]])->source;

    expect($two)->toContain("'bio' => NormalizeOnSet::using('squish'), // @generated normalize")
        ->toContain('use Dakotajune\Normalize\Casts\NormalizeOnSet;')
        ->and(lints($two))->toBeTrue();
});

it('keeps the developer\'s entries and puts a missing comma in', function () {
    $source = blueprintModel("            'id' => 'integer',\n            'meta' => 'array'\n");
    $result = (new ModelCastEditor())->update($source, 'Thing', TRIM_UPPER)->source;

    expect($result)->toContain("'meta' => 'array',\n            'name' => Normalize::using")
        ->and(lints($result))->toBeTrue();
});

it('expands an empty array and puts it back byte for byte', function () {
    $editor = new ModelCastEditor();
    $original = blueprintModel('');
    $original = str_replace("return [\n        ];", 'return [];', $original);

    $edited = $editor->update($original, 'Thing', TRIM_UPPER)->source;

    expect($edited)->toContain("return [\n            'name' => Normalize::using('trim', 'upper'), // @generated normalize\n        ];")
        ->and($editor->update($edited, 'Thing', TRIM_UPPER)->changed)->toBeFalse()
        ->and($editor->strip($edited, 'Thing', ['Dakotajune\Normalize\Casts\Normalize']))->toBe($original);
});

it('strips back to exactly what Blueprint wrote', function () {
    $editor = new ModelCastEditor();
    $original = blueprintModel();
    $edited = $editor->update($original, 'Thing', TRIM_UPPER + ['bio' => ['operations' => ['squish'], 'onSet' => true]])->source;

    expect($editor->strip($edited, 'Thing', ['Dakotajune\Normalize\Casts\Normalize', 'Dakotajune\Normalize\Casts\NormalizeOnSet']))->toBe($original);
});

it('keeps an import the model uses for something else', function () {
    $source = str_replace("use Illuminate\\Database\\Eloquent\\Model;", "use Dakotajune\\Normalize\\Casts\\Normalize;\nuse Illuminate\\Database\\Eloquent\\Model;", blueprintModel("            'id' => 'integer',\n            'other' => Normalize::using('trim'),\n"));
    $editor = new ModelCastEditor();
    $edited = $editor->update($source, 'Thing', TRIM_UPPER)->source;

    expect(substr_count($edited, 'use Dakotajune\Normalize\Casts\Normalize;'))->toBe(1)
        ->and($editor->strip($edited, 'Thing', ['Dakotajune\Normalize\Casts\Normalize']))->toBe($source);
});

it('adds a casts() method when the model has none, and takes it away again', function (string $members) {
    $editor = new ModelCastEditor();
    $original = modelWithoutCasts($members);

    $edited = $editor->update($original, 'Thing', TRIM_UPPER)->source;

    expect($edited)
        ->toContain("    protected function casts(): array\n    {\n        return [\n            'name' => Normalize::using('trim', 'upper'), // @generated normalize\n        ];\n    }\n}\n")
        ->toContain('@generated normalize Removed with its last generated cast')
        ->and(lints($edited))->toBeTrue()
        ->and($editor->update($edited, 'Thing', TRIM_UPPER)->changed)->toBeFalse()
        ->and($editor->strip($edited, 'Thing', ['Dakotajune\Normalize\Casts\Normalize']))->toBe($original);
})->with([
    'empty class' => [''],
    'with members' => ["    protected \$fillable = ['name'];\n\n    public function posts()\n    {\n        return \$this->hasMany(Post::class);\n    }\n"],
]);

it('keeps the method we added once the developer has put entries in it', function () {
    $editor = new ModelCastEditor();
    $edited = $editor->update(modelWithoutCasts(), 'Thing', TRIM_UPPER)->source;
    $edited = str_replace("        return [\n", "        return [\n            'mine' => 'array',\n", $edited);

    $stripped = $editor->strip($edited, 'Thing', ['Dakotajune\Normalize\Casts\Normalize']);

    expect($stripped)->toContain("'mine' => 'array'")
        ->toContain('protected function casts(): array')
        ->not->toContain('@generated')
        ->not->toContain('Normalize')
        ->and(lints($stripped))->toBeTrue();
});

it('writes the $casts property form for older layouts', function () {
    $editor = new ModelCastEditor();
    $original = modelWithoutCasts("    protected \$casts = [\n        'id' => 'integer',\n    ];\n");

    $edited = $editor->update($original, 'Thing', TRIM_UPPER)->source;

    expect($edited)->toContain("        'name' => Normalize::class . ':trim,upper', // @generated normalize")
        ->not->toContain('function casts')
        ->and(lints($edited))->toBeTrue()
        ->and($editor->update($edited, 'Thing', TRIM_UPPER)->changed)->toBeFalse()
        ->and($editor->strip($edited, 'Thing', ['Dakotajune\Normalize\Casts\Normalize']))->toBe($original);
});

it('does not take over a column that already has a cast of its own', function () {
    $source = blueprintModel("            'id' => 'integer',\n            'name' => 'encrypted',\n");
    $result = (new ModelCastEditor())->update($source, 'Thing', TRIM_UPPER);

    expect($result->source)->toBe($source)
        ->and($result->notices)->toHaveCount(1)
        ->and($result->notices[0])->toContain('[name] already has a cast of its own');
});

it('says so when it cannot read the casts', function (string $source, string $expected) {
    $result = (new ModelCastEditor())->update($source, 'Thing', TRIM_UPPER);

    expect($result->source)->toBe($source)
        ->and($result->changed)->toBeFalse()
        ->and($result->notices)->toHaveCount(1)
        ->and($result->notices[0])->toContain($expected);
})->with([
    'one line' => [
        "<?php\n\nnamespace App\\Models;\n\nclass Thing\n{\n    protected function casts(): array\n    {\n        return ['id' => 'integer'];\n    }\n}\n",
        'on one line',
    ],
    'merged' => [
        "<?php\n\nnamespace App\\Models;\n\nclass Thing\n{\n    protected function casts(): array\n    {\n        return array_merge(parent::casts(), []);\n    }\n}\n",
        'does not simply return an array literal',
    ],
    'no such class' => [
        "<?php\n\nnamespace App\\Models;\n\nclass Other\n{\n}\n",
        'Class [Thing] was not found',
    ],
]);

it('uses a full class name when the short name is taken', function () {
    $source = str_replace("use Illuminate\\Database\\Eloquent\\Model;", "use App\\Casts\\Normalize;\nuse Illuminate\\Database\\Eloquent\\Model;", blueprintModel());
    $edited = (new ModelCastEditor())->update($source, 'Thing', TRIM_UPPER)->source;

    expect($edited)->toContain("'name' => \\Dakotajune\\Normalize\\Casts\\Normalize::using('trim', 'upper'), // @generated normalize")
        ->not->toContain('use Dakotajune\Normalize\Casts\Normalize;')
        ->and(lints($edited))->toBeTrue();
});

it('escapes quotes in operations', function () {
    $edited = (new ModelCastEditor())->update(blueprintModel(), 'Thing', ['name' => ['operations' => ["replace:it's;its"], 'onSet' => false]])->source;

    expect($edited)->toContain("Normalize::using('replace:it\\'s;its')")
        ->and(lints($edited))->toBeTrue();
});

it('reports whether tagged entries are present', function () {
    $editor = new ModelCastEditor();

    expect($editor->hasTagged(blueprintModel()))->toBeFalse()
        ->and($editor->hasTagged($editor->update(blueprintModel(), 'Thing', TRIM_UPPER)->source))->toBeTrue();
});
