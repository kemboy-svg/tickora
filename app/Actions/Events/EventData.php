<?php

namespace App\Actions\Events;

use App\Enums\EventVisibility;
use Carbon\CarbonImmutable;

/**
 * Validated event details, with all times already converted to UTC.
 */
final readonly class EventData
{
    /**
     * @param  array{name: string, address_line: string, city: string, country_code: string, region: string|null, postal_code: string|null}  $venue
     */
    public function __construct(
        public string $title,
        public int $categoryId,
        public ?string $summary,
        public ?string $description,
        public string $timezone,
        public string $currency,
        public CarbonImmutable $startsAt,
        public CarbonImmutable $endsAt,
        public ?CarbonImmutable $salesStartsAt,
        public ?CarbonImmutable $salesEndsAt,
        public EventVisibility $visibility,
        public ?string $refundPolicy,
        public ?int $minAge,
        public array $venue,
    ) {}
}
