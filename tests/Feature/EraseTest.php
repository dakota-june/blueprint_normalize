<?php

declare(strict_types=1);

use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

function erase(): void
{
    event(new CommandStarting('blueprint:erase', new ArrayInput([]), new NullOutput()));
}

it('puts the models back exactly as Blueprint wrote them', function () {
    $this->build($this->draft(), ['models']);
    $baseline = snapshot();

    $this->build($this->draft());
    expect(snapshot())->not->toBe($baseline)
        ->and(file_exists(base_path('.blueprint_normalize')))->toBeTrue();

    erase();

    expect(snapshot())->toBe($baseline)
        ->and(file_exists(base_path('.blueprint_normalize')))->toBeFalse();
});

it('removes a casts() method it added, and leaves a developer\'s edits alone', function () {
    $this->build($this->draft(['Account' => ['title' => 'string']]), ['models']);
    $path = base_path('app/Models/Account.php');
    file_put_contents($path, (string) preg_replace('~\n    /\*\*\n     \* Get the attributes that should be cast.*?\n    }\n~s', '', file_get_contents($path)));
    $baseline = file_get_contents($path);

    $this->build($this->draft(['Account' => ['title' => 'string normalize:trim']]), ['normalize']);
    expect(file_get_contents($path))->not->toBe($baseline);

    erase();

    expect(file_get_contents($path))->toBe($baseline);
});

it('does nothing when there is no manifest', function () {
    $this->build($this->draft(), ['models']);
    $baseline = snapshot();

    erase();

    expect(snapshot())->toBe($baseline);
});
