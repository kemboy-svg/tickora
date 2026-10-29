<?php

namespace App\Http\Requests\Concerns;

use App\Support\LocalTime;

/**
 * Normalises datetime-local values before validation, so a browser that
 * submits seconds is not told its date is in the wrong format.
 */
trait NormalizesLocalTimes
{
    /**
     * @return list<string>
     */
    abstract protected function localTimeFields(): array;

    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach ($this->localTimeFields() as $field) {
            if ($this->has($field)) {
                $normalized[$field] = LocalTime::normalizeInput($this->input($field));
            }
        }

        $this->merge($normalized);
    }
}
