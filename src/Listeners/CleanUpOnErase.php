<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Listeners;

use Dakotajune\Normalize\Support\ManifestStore;
use Dakotajune\Normalize\Support\ModelCastEditor;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Filesystem\Filesystem;

/**
 * `blueprint:erase` cannot undo edits to files it did not create. Just before it starts, this takes our tagged
 * cast entries (and the casts() method or imports we added) out of the models, using the manifest from the build.
 */
final class CleanUpOnErase
{
    public function __construct(
        private readonly Filesystem $files,
        private readonly ModelCastEditor $editor,
        private readonly ManifestStore $manifests,
    ) {}

    public function handle(CommandStarting $event): void
    {
        if ($event->command !== 'blueprint:erase') {
            return;
        }

        $manifest = $this->manifests->load();

        if ($manifest->isEmpty()) {
            return;
        }

        foreach ($manifest->models() as $path => $meta) {
            $absolute = base_path($path);

            if (! $this->files->exists($absolute)) {
                continue;
            }

            $source = $this->files->get($absolute);
            $stripped = $this->editor->strip($source, $meta['class'], $meta['uses']);

            if ($stripped !== $source) {
                $this->files->put($absolute, $stripped);
            }
        }

        $this->manifests->delete();
    }
}
