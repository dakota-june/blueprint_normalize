<?php

declare(strict_types=1);

namespace Dakotajune\Normalize;

use Blueprint\Blueprint;
use Dakotajune\Normalize\Generators\NormalizeGenerator;
use Dakotajune\Normalize\Lexers\NormalizeLexer;
use Dakotajune\Normalize\Listeners\CleanUpOnErase;
use Dakotajune\Normalize\Support\NoticeBag;
use Dakotajune\Normalize\Support\OperationValidator;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\ServiceProvider;

/**
 * The cast works without Blueprint. When Blueprint is installed this also adds the `normalize:` column modifier.
 *
 * Deliberately NOT a DeferrableProvider: Blueprint's own provider already defers Blueprint::class, and two
 * deferred providers cannot both own one service. boot() must also run so the erase listener is registered
 * before `blueprint:erase` starts.
 */
final class NormalizeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/normalize.php', 'normalize');

        if (! class_exists(Blueprint::class)) {
            return;
        }

        $this->app->singleton(NoticeBag::class);

        $this->app->bind(OperationValidator::class, fn (): OperationValidator => new OperationValidator(
            (array) config('normalize.denied', []),
            (array) config('normalize.samples', []),
        ));

        // extend() runs when Blueprint is resolved, whatever order the providers loaded in.
        $this->app->extend(Blueprint::class, function (Blueprint $blueprint, $app): Blueprint {
            $blueprint->registerLexer($app->make(NormalizeLexer::class));
            $blueprint->registerGenerator($app->make(NormalizeGenerator::class));

            return $blueprint;
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/normalize.php' => config_path('normalize.php'),
            ], 'normalize-config');
        }

        if (! class_exists(Blueprint::class)) {
            return;
        }

        $events = $this->app['events'];

        // Resolve the listener only when erase really starts, not for every artisan command.
        $events->listen(CommandStarting::class, function (CommandStarting $event): void {
            if ($event->command === 'blueprint:erase') {
                $this->app->make(CleanUpOnErase::class)->handle($event);
            }
        });

        $events->listen(CommandFinished::class, function (CommandFinished $event): void {
            if (! in_array($event->command, ['blueprint:build', 'blueprint:erase'], true)) {
                return;
            }

            foreach ($this->app->make(NoticeBag::class)->pull() as $notice) {
                $event->output->writeln("<comment>normalize:</comment> {$notice}");
            }
        });
    }
}
