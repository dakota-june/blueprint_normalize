<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Models;

/** One `normalize:` / `normalize_on_set:` modifier from the draft. */
final class NormalizedColumn
{
    /** @param  array<int, string>  $operations  e.g. ['trim', 'limit:50;...'] */
    public function __construct(
        public readonly string $model,
        public readonly string $column,
        public readonly array $operations,
        public readonly bool $onSet,
    ) {}

    public function modifier(): string
    {
        return $this->onSet ? 'normalize_on_set' : 'normalize';
    }
}
