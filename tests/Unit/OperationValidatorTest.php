<?php

declare(strict_types=1);

use Dakotajune\Normalize\Support\OperationValidator;
use Illuminate\Support\Stringable;

it('accepts ordinary pipelines', function (array $operations) {
    (new OperationValidator())->validate($operations, true, 'Post.title');

    expect(true)->toBeTrue();
})->with([
    'trim and upper' => [['trim', 'upper']],
    'squish and lower' => [['squish', 'lower']],
    'title' => [['trim', 'title']],
    'replace with arguments' => [['replace:foo;bar']],
]);

it('rejects what is not a Stringable method', function () {
    expect(fn () => (new OperationValidator())->validate(['trim', 'uper'], true, 'Post.title'))
        ->toThrow(InvalidArgumentException::class, 'Post.title: Stringable has no [uper] method.');
});

it('rejects text that is not an operation name', function (string $operation) {
    expect(fn () => (new OperationValidator())->validate([$operation], true, 'Post.title'))
        ->toThrow(InvalidArgumentException::class, 'is not an operation');
})->with(['space' => ['tr im'], 'bracket' => ['trim()'], 'dash' => ['to-upper'], 'leading digit' => ['1up']]);

it('refuses methods that run callbacks or print', function (string $operation) {
    expect(fn () => (new OperationValidator())->validate([$operation], false, 'Post.title'))
        ->toThrow(InvalidArgumentException::class, 'cannot be used as an operation');
})->with(['pipe' => ['pipe:strtoupper'], 'tap' => ['tap'], 'dump' => ['dump'], 'when' => ['whenEmpty'], 'unless' => ['unlessEmpty'], 'macro' => ['macro']]);

it('refuses operations that do not return a Stringable', function () {
    expect(fn () => (new OperationValidator())->validate(['trim', 'length'], true, 'Post.title'))
        ->toThrow(InvalidArgumentException::class, 'must return a Stringable');
});

it('refuses extra methods from config', function () {
    expect(fn () => (new OperationValidator(['upper']))->validate(['upper'], true, 'Post.title'))
        ->toThrow(InvalidArgumentException::class, 'cannot be used');
});

it('accepts a macro', function () {
    Stringable::macro('normalizeTestShout', function () {
        return $this->upper();
    });

    (new OperationValidator())->validate(['trim', 'normalizeTestShout'], true, 'Post.title');

    expect(true)->toBeTrue();
});

it('wants an operation that runs on read to give the same result twice', function () {
    expect(fn () => (new OperationValidator())->validate(['reverse'], true, 'Post.title'))
        ->toThrow(InvalidArgumentException::class, 'normalize_on_set');

    // The same pipeline is fine when it only runs on write.
    (new OperationValidator())->validate(['reverse'], false, 'Post.title');

    expect(true)->toBeTrue();
});

it('catches limit() cutting text right after a space, wherever the draft puts the limit', function (string $limit) {
    expect(fn () => (new OperationValidator())->validate(['squish', "limit:{$limit};..."], true, 'Post.bio'))
        ->toThrow(InvalidArgumentException::class, 'normalize_on_set');

    (new OperationValidator())->validate(['squish', "limit:{$limit};..."], false, 'Post.bio');

    expect(true)->toBeTrue();
})->with(['50', '64', '255', '500', '11']);

it('rejects an empty pipeline', function () {
    expect(fn () => (new OperationValidator())->validate([], true, 'Post.title'))
        ->toThrow(InvalidArgumentException::class, 'no operations');
});
