<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganizer;
use Database\Factories\VenueFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'address_line', 'city', 'region', 'postal_code', 'country_code', 'timezone', 'latitude', 'longitude'])]
class Venue extends Model
{
    use BelongsToOrganizer;

    /** @use HasFactory<VenueFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:6',
            'longitude' => 'decimal:6',
        ];
    }

    /**
     * @return HasMany<Event, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }

    public function isShared(): bool
    {
        return $this->organizer_id === null;
    }
}
