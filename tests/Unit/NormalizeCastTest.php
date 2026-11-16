<?php

declare(strict_types=1);

use Dakotajune\Normalize\Casts\Normalize;
use Dakotajune\Normalize\Casts\NormalizeOnSet;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;
use Illuminate\Database\Eloquent\Model;

function plainModel(): Model
{
    return new class extends Model {};
}

it('builds the cast string Laravel expects', function () {
    expect(Normalize::using('trim', 'upper'))->toBe('Dakotajune\Normalize\Casts\Normalize:trim,upper')
        ->and(NormalizeOnSet::using('limit:50;...'))->toBe('Dakotajune\Normalize\Casts\NormalizeOnSet:limit:50;...');
});

it('runs the operations in order, on read and on write', function () {
    $cast = new Normalize('trim', 'upper');

    expect($cast->get(plainModel(), 'name', '  ab  ', []))->toBe('AB')
        ->and($cast->set(plainModel(), 'name', '  ab  ', []))->toBe('AB');
});

it('passes semicolon separated arguments to the operation', function () {
    expect((new Normalize('limit:5;...'))->set(plainModel(), 'name', 'Hello World', []))->toBe('Hello...');
});

it('lets non-strings through untouched', function (mixed $value) {
    expect((new Normalize('trim', 'upper'))->set(plainModel(), 'name', $value, []))->toBe($value);
})->with([null, 12, 1.5, true, [['a']]]);

it('names the operation when it is not a Stringable method', function () {
    expect(fn () => (new Normalize('notAnOperation'))->set(plainModel(), 'name', 'x', []))
        ->toThrow(BadMethodCallException::class);
});

it('refuses an operation that does not hand a Stringable back', function () {
    expect(fn () => (new Normalize('length'))->set(plainModel(), 'name', 'x', []))
        ->toThrow(UnexpectedValueException::class, 'must return a Stringable');
});

it('has a write-only variant that Laravel will not call on read', function () {
    expect(new NormalizeOnSet('trim'))->toBeInstanceOf(CastsInboundAttributes::class)
        ->and(new Normalize('trim'))->toBeInstanceOf(CastsAttributes::class)
        ->and(new Normalize('trim'))->not->toBeInstanceOf(CastsInboundAttributes::class)
        ->and((new NormalizeOnSet('trim', 'upper'))->set(plainModel(), 'name', ' ab ', []))->toBe('AB');
});

it('is built by Eloquent from its cast string', function () {
    if (! trait_exists(Illuminate\Database\Eloquent\Concerns\HasAttributes::class)) {
        return;                                         // only the real Eloquent can show this
    }

    $model = new class extends Model
    {
        protected function casts(): array
        {
            return [
                'name' => Normalize::using('trim', 'upper'),
                'bio' => NormalizeOnSet::using('trim', 'lower'),
            ];
        }
    };

    $model->name = '  ab ';
    $model->bio = '  CD ';

    expect($model->getAttributes()['name'])->toBe('AB')
        ->and($model->name)->toBe('AB')
        ->and($model->getAttributes()['bio'])->toBe('cd');

    // A row that was stored before the cast existed: Normalize tidies it on read, NormalizeOnSet leaves it.
    $stored = $model->newFromBuilder(['name' => '  old ', 'bio' => '  OLD ']);

    expect($stored->name)->toBe('OLD')
        ->and($stored->bio)->toBe('  OLD ');
});
