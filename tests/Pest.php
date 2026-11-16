<?php

declare(strict_types=1);

use Dakotajune\Normalize\Tests\TestCase;

uses(TestCase::class)->in('Feature', 'Unit');

function lints(string $source): bool
{
    try {
        token_get_all($source, TOKEN_PARSE);

        return true;
    } catch (ParseError) {
        return false;
    }
}

function read(string $path): string
{
    return file_get_contents(base_path($path));
}

/**
 * Every model file, keyed by forward-slash path, for "nothing changed" comparisons.
 *
 * @return array<string, string>
 */
function snapshot(): array
{
    $files = [];
    $root = str_replace('\\', '/', base_path()) . '/';

    foreach (glob(base_path('app/Models') . '/*.php') ?: [] as $file) {
        $files[substr(str_replace('\\', '/', $file), strlen($root))] = file_get_contents($file);
    }

    ksort($files);

    return $files;
}
