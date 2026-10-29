<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A photo of an event, stored re-encoded (metadata stripped) on the media
 * disk. Written only by ManageEventImages, so nothing is mass assignable.
 *
 * @property int $id
 * @property string $public_id
 * @property int $event_id
 * @property string $disk
 * @property string $path
 * @property string $mime_type
 * @property int $width
 * @property int $height
 * @property int $size_bytes
 * @property int $sort_order
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class EventImage extends Model
{
    use HasPublicId;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width' => 'integer',
            'height' => 'integer',
            'size_bytes' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function url(): string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    /**
     * @return array{id: string, url: string, width: int, height: int}
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->public_id,
            'url' => $this->url(),
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
