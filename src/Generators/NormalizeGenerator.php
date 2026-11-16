<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Generators;

use Blueprint\Blueprint;
use Blueprint\Contracts\Generator;
use Blueprint\Models\Model;
use Blueprint\Tree;
use Dakotajune\Normalize\Casts\Normalize;
use Dakotajune\Normalize\Casts\NormalizeOnSet;
use Dakotajune\Normalize\Lexers\NormalizeLexer;
use Dakotajune\Normalize\Models\NormalizedColumn;
use Dakotajune\Normalize\Support\Manifest;
use Dakotajune\Normalize\Support\ManifestStore;
use Dakotajune\Normalize\Support\ModelCastEditor;
use Dakotajune\Normalize\Support\NoticeBag;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Puts a tagged cast entry in the model for every `normalize:` / `normalize_on_set:` column of the draft.
 *
 * Blueprint rewrites each model on every build, so the entries are added again after it has run (generators
 * run in registration order, ours last) and written to the same place, which makes a rebuild identical.
 * Models are listed as `updated` ([label, path] pairs, as Blueprint prints and records them); nothing is created,
 * so `blueprint:erase` never deletes anything of ours.
 */
final class NormalizeGenerator implements Generator
{
    /** Column types a Stringable pipeline makes sense on. */
    private const STRING_TYPES = ['string', 'char', 'tinytext', 'text', 'mediumtext', 'longtext'];

    private readonly NoticeBag $notices;

    private readonly ManifestStore $manifests;

    private readonly ModelCastEditor $editor;

    // The Generator contract fixes the first parameter; the rest are optional collaborators.
    public function __construct(
        private readonly Filesystem $files,
        ?NoticeBag $notices = null,
        ?ManifestStore $manifests = null,
        ?ModelCastEditor $editor = null,
    ) {
        $this->notices = $notices ?? new NoticeBag();
        $this->manifests = $manifests ?? new ManifestStore($files);
        $this->editor = $editor ?? new ModelCastEditor();
    }

    /** @return array{created: array<int,string>, updated: array<int,string>} */
    public function output(Tree $tree): array
    {
        $output = ['created' => [], 'updated' => []];

        /** @var array<string, array<string, NormalizedColumn>> $registry */
        $registry = $tree->toArray()[NormalizeLexer::REGISTRY_KEY] ?? [];

        $previous = $this->manifests->load();
        $next = new Manifest();

        foreach ($registry as $modelName => $columns) {
            $model = $tree->modelForContext((string) $modelName);

            if ($model === null) {
                throw new InvalidArgumentException("Model [{$modelName}] was not found in the draft.");
            }

            $fqcn = $tree->fqcnForContext((string) $modelName);
            $class = Str::afterLast($fqcn, '\\');
            $path = $this->relativePath($fqcn);

            $entries = [];

            foreach ($columns as $column => $normalized) {
                $this->assertStringColumn($model, $normalized);
                $entries[(string) $column] = ['operations' => $normalized->operations, 'onSet' => $normalized->onSet];
            }

            if (! $this->files->exists(base_path($path))) {
                $this->notices->add("{$class} was not normalized: the model file {$path} does not exist. Build the models first.");

                continue;
            }

            $source = $this->files->get(base_path($path));
            $result = $this->editor->update($source, $class, $entries);

            foreach ($result->notices as $notice) {
                $this->notices->add($notice);
            }

            if ($result->changed) {
                $this->files->put(base_path($path), $result->source);
                $output['updated'][] = ['Model', $path];
            }

            $next->recordModel($path, $class, [Normalize::class, NormalizeOnSet::class]);
        }

        $this->cleanStale($previous, $next, $output);
        $this->manifests->save($next);

        return $output;
    }

    /** @return array<int, string> */
    public function types(): array
    {
        return ['normalize'];
    }

    /** A model that had entries last time but no longer has any in the draft gets them taken out again. */
    private function cleanStale(Manifest $previous, Manifest $next, array &$output): void
    {
        foreach ($previous->models() as $path => $meta) {
            if (isset($next->models()[$path]) || ! $this->files->exists(base_path($path))) {
                continue;
            }

            $source = $this->files->get(base_path($path));
            $stripped = $this->editor->strip($source, $meta['class'], $meta['uses']);

            if ($stripped !== $source) {
                $this->files->put(base_path($path), $stripped);
                $output['updated'][] = ['Model', $path];
            }
        }
    }

    private function assertStringColumn(Model $model, NormalizedColumn $normalized): void
    {
        $columns = $model->columns();

        if (! isset($columns[$normalized->column])) {
            throw new InvalidArgumentException("{$normalized->model}.{$normalized->column}: the column was not found on the model.");
        }

        $type = $columns[$normalized->column]->dataType();

        if (! in_array(strtolower($type), self::STRING_TYPES, true)) {
            throw new InvalidArgumentException(
                "{$normalized->model}.{$normalized->column} is a {$type} column, but {$normalized->modifier()}: only works on "
                . 'string columns (string, char, tinyText, text, mediumText, longText).'
            );
        }
    }

    /** Path relative to the project root, built with Blueprint's own helpers. */
    private function relativePath(string $fullyQualifiedClassName): string
    {
        return Blueprint::appPath() . '/'
            . str_replace('\\', '/', Blueprint::relativeNamespace($fullyQualifiedClassName))
            . '.php';
    }
}
