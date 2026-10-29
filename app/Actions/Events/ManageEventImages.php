<?php

namespace App\Actions\Events;

use App\Models\Event;
use App\Models\EventImage;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Media\ImageProcessor;
use App\Services\Media\ProcessedImage;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Adds, removes and reorders an event's photos. The first photo is the
 * cover, and its path is mirrored to events.cover_image_key.
 *
 * Uploads are processed (decoded and re-encoded) before anything is
 * saved, so a bad file never leaves a half-created event behind.
 */
class ManageEventImages
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly AuditLogger $audit,
        private readonly Config $config,
    ) {}

    /**
     * Decode and re-encode uploads. Throws a ValidationException naming the
     * offending file ("images.2") if one cannot be processed.
     *
     * @param  array<array-key, UploadedFile>  $files
     * @return list<ProcessedImage>
     */
    public function prepare(array $files): array
    {
        $processed = [];

        foreach (array_values($files) as $i => $file) {
            $path = $file->getRealPath();

            if ($path === false) {
                throw ValidationException::withMessages(["images.{$i}" => 'This image failed to upload. Please try again.']);
            }

            $processed[] = $this->processor->process($path, "images.{$i}");
        }

        return $processed;
    }

    /**
     * @param  list<ProcessedImage>  $images
     * @return list<EventImage>
     */
    public function attach(User $actor, Event $event, array $images): array
    {
        Gate::forUser($actor)->authorize('update', $event);

        if ($images === []) {
            return [];
        }

        $max = $this->maxImages();
        $existing = $event->images()->count();

        if ($existing + count($images) > $max) {
            $left = max(0, $max - $existing);

            throw ValidationException::withMessages([
                'images' => $left === 0
                    ? "An event can have up to {$max} images. Remove one to add another."
                    : "An event can have up to {$max} images; you can add {$left} more.",
            ]);
        }

        $disk = $this->disk();
        $written = [];

        try {
            foreach ($images as $image) {
                $path = 'events/'.$event->public_id.'/'.Str::lower((string) Str::ulid()).'.'.$image->extension;
                Storage::disk($disk)->put($path, $image->contents, ['visibility' => 'public']);
                $written[] = $path;
            }

            return DB::transaction(function () use ($actor, $event, $images, $written, $disk): array {
                $next = (int) $event->images()->max('sort_order') + ($event->images()->exists() ? 1 : 0);
                $created = [];

                foreach ($images as $i => $image) {
                    $model = new EventImage;
                    $model->event_id = $event->id;
                    $model->disk = $disk;
                    $model->path = $written[$i];
                    $model->mime_type = $image->mimeType;
                    $model->width = $image->width;
                    $model->height = $image->height;
                    $model->size_bytes = $image->size();
                    $model->sort_order = $next + $i;
                    $model->save();
                    $created[] = $model;
                }

                $this->syncCover($event);

                $this->audit->record('event.images_added', $event, [
                    'count' => count($created),
                    'images' => array_map(fn (EventImage $m): string => $m->public_id, $created),
                ], actor: $actor);

                return $created;
            });
        } catch (Throwable $e) {
            Storage::disk($disk)->delete($written);

            throw $e;
        }
    }

    public function remove(User $actor, Event $event, EventImage $image): void
    {
        Gate::forUser($actor)->authorize('update', $event);
        abort_unless($image->event_id === $event->id, 404);

        DB::transaction(function () use ($actor, $event, $image): void {
            $image->delete();
            $this->syncCover($event);

            $this->audit->record('event.image_removed', $event, ['image' => $image->public_id], actor: $actor);
        });

        Storage::disk($image->disk)->delete($image->path);
    }

    /**
     * Move an image to the front, making it the cover.
     */
    public function makeCover(User $actor, Event $event, EventImage $image): void
    {
        Gate::forUser($actor)->authorize('update', $event);
        abort_unless($image->event_id === $event->id, 404);

        DB::transaction(function () use ($actor, $event, $image): void {
            $order = $event->images()->get()
                ->sortBy(fn (EventImage $m): int => $m->id === $image->id ? -1 : $m->sort_order)
                ->values();

            foreach ($order as $position => $model) {
                if ($model->sort_order !== $position) {
                    $model->sort_order = $position;
                    $model->save();
                }
            }

            $this->syncCover($event);

            $this->audit->record('event.cover_changed', $event, ['image' => $image->public_id], actor: $actor);
        });
    }

    private function syncCover(Event $event): void
    {
        $cover = $event->images()->first();
        $event->cover_image_key = $cover?->path;
        $event->save();
    }

    private function disk(): string
    {
        $disk = $this->config->get('tickora.media_disk', 'public');

        return is_string($disk) && $disk !== '' ? $disk : 'public';
    }

    private function maxImages(): int
    {
        $max = $this->config->get('tickora.max_event_images', 8);

        return is_numeric($max) && (int) $max > 0 ? (int) $max : 8;
    }
}
