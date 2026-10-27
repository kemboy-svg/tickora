<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a model an opaque ULID "public_id" used in URLs, API responses and
 * anything a browser can see. Internal auto-increment IDs never leave the
 * server, so they cannot be enumerated or guessed.
 */
trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function (self $model): void {
            if (empty($model->getAttribute('public_id'))) {
                $model->setAttribute('public_id', Str::lower((string) Str::ulid()));
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
