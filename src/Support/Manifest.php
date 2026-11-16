<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Support;

/**
 * What this package changed, so `blueprint:erase` can undo exactly that and nothing else.
 *
 * models: path => [class, uses]   models that got tagged cast entries; uses = the cast classes they import
 */
final class Manifest
{
    /** @var array<string, array{class: string, uses: array<int, string>}> */
    private array $models = [];

    /** @param  array<int, string>  $uses */
    public function recordModel(string $path, string $class, array $uses): void
    {
        $this->models[$path] = ['class' => $class, 'uses' => array_values(array_unique($uses))];
    }

    /** @return array<string, array{class: string, uses: array<int, string>}> */
    public function models(): array
    {
        return $this->models;
    }

    public function isEmpty(): bool
    {
        return $this->models === [];
    }

    public function toJson(): string
    {
        return json_encode(['models' => $this->models], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }

    public static function fromJson(?string $json): self
    {
        $manifest = new self();
        $data = $json === null ? [] : json_decode($json, true);

        if (is_array($data)) {
            $manifest->models = (array) ($data['models'] ?? []);
        }

        return $manifest;
    }
}
