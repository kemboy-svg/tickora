<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Event photos. The first image (lowest sort_order) is the cover; its path
 * is also copied to events.cover_image_key so listings can show covers
 * without loading every event's images.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_images', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('mime_type', 40);
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['event_id', 'sort_order']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE event_images ADD CONSTRAINT event_images_mime_check CHECK (mime_type IN ('image/jpeg','image/png','image/webp'))");
            DB::statement('ALTER TABLE event_images ADD CONSTRAINT event_images_dimensions_check CHECK (width > 0 AND height > 0 AND size_bytes > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('event_images');
    }
};
