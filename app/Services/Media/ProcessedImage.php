<?php

namespace App\Services\Media;

final readonly class ProcessedImage
{
    public function __construct(
        public string $contents,
        public string $mimeType,
        public string $extension,
        public int $width,
        public int $height,
    ) {}

    public function size(): int
    {
        return strlen($this->contents);
    }
}
