<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Tests;

use Blueprint\Blueprint;
use Blueprint\BlueprintServiceProvider;
use Dakotajune\Normalize\NormalizeServiceProvider;
use Dakotajune\Normalize\Support\NoticeBag;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /** Models the fixtures create; removed again after each test. */
    private const MODELS = ['ContactType', 'Plain', 'Account'];

    private string $originalDirectory = '';

    protected function getPackageProviders($app): array
    {
        return [BlueprintServiceProvider::class, NormalizeServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Blueprint's own generators write to paths relative to the working directory; ours use base_path().
        // Make them the same place, or models would land in the package folder.
        $this->originalDirectory = (string) getcwd();
        chdir(base_path());

        $this->clean();
    }

    protected function tearDown(): void
    {
        $this->clean();
        chdir($this->originalDirectory);

        parent::tearDown();
    }

    private function clean(): void
    {
        foreach (self::MODELS as $model) {
            @unlink(base_path("app/Models/{$model}.php"));
        }

        @unlink(base_path('.blueprint_normalize'));
        @unlink(base_path('.blueprint'));

        $this->app->make(NoticeBag::class)->pull();
    }

    /**
     * Runs real Blueprint lexers over an already-parsed draft, then the generators of the given types.
     *
     * @param  array<int, string>  $only  'models' = Blueprint's model generator, 'normalize' = ours
     * @return array{created?: array<int, mixed>, updated?: array<int, mixed>}
     */
    protected function build(array $draft, array $only = ['models', 'normalize']): array
    {
        $blueprint = $this->app->make(Blueprint::class);

        return $blueprint->generate($blueprint->analyze($draft), $only);
    }

    /** @return array<int, string> */
    protected function notices(): array
    {
        return $this->app->make(NoticeBag::class)->pull();
    }

    /**
     * The draft as Blueprint's YAML parser would hand it to the lexers.
     *
     * @param  array<string, array<string, string>>|null  $models  replaces the fixture's `models:` section when given
     */
    protected function draft(?array $models = null): array
    {
        $draft = require __DIR__ . '/fixtures/draft.php';

        if ($models !== null) {
            $draft['models'] = $models;
        }

        return $draft;
    }
}
