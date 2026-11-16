<?php

declare(strict_types=1);

namespace Dakotajune\Normalize\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsInboundAttributes;

/**
 * {@see Normalize}, but only when a value is written.
 *
 * Reading returns the stored value untouched, so the pipeline runs once per write instead of on every read
 * and does not have to be idempotent. Use it for operations such as `limit:50;...` or `append:!`, and when
 * rows that are already stored should be left as they are.
 *
 * Laravel does not call get() for a class that implements {@see CastsInboundAttributes}; the inherited get()
 * is never reached. In a Blueprint draft this is `normalize_on_set:`.
 */
final class NormalizeOnSet extends Normalize implements CastsInboundAttributes {}
