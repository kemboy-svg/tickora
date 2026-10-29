<?php

namespace App\Services\Media;

use GdImage;
use Illuminate\Validation\ValidationException;

/**
 * Decodes an uploaded photo and writes a fresh copy. Re-encoding means the
 * stored file is pixels only: camera metadata (including GPS location) is
 * dropped, and anything smuggled inside the original file is not carried
 * over. Phone photos are turned upright and scaled down to a sensible size.
 *
 * Only JPEG, PNG and WebP are accepted; the output keeps the input format.
 */
class ImageProcessor
{
    /** Refuse to decode anything bigger than this, to avoid memory blow-ups. */
    private const MAX_PIXELS = 50_000_000;

    public function __construct(private readonly int $maxEdge = 2400) {}

    /**
     * @param  string  $field  validation key for error messages
     */
    public function process(string $path, string $field = 'images'): ProcessedImage
    {
        $info = @getimagesize($path);

        if ($info === false) {
            $this->fail($field, 'This file is not an image we can read.');
        }

        [$width, $height, $type] = $info;

        if ($width < 1 || $height < 1 || $width * $height > self::MAX_PIXELS) {
            $this->fail($field, 'This image is too large to process.');
        }

        $image = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default => false,
        };

        if (! $image instanceof GdImage) {
            $this->fail($field, 'Upload JPG, PNG or WebP images.');
        }

        if ($type === IMAGETYPE_JPEG) {
            $image = $this->orient($image, $path);
        }

        $image = $this->fit($image);

        return $this->encode($image, $type);
    }

    /**
     * Apply the EXIF orientation phones record instead of rotating pixels,
     * since the re-encoded copy will not carry that tag.
     */
    private function orient(GdImage $image, string $path): GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);
        $orientation = is_array($exif) && isset($exif['Orientation']) && is_numeric($exif['Orientation'])
            ? (int) $exif['Orientation']
            : 1;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }

    private function fit(GdImage $image): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $longest = max($width, $height);

        if ($longest <= $this->maxEdge) {
            return $image;
        }

        $scale = $this->maxEdge / $longest;
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newWidth, $newHeight);

        if (! $resized instanceof GdImage) {
            return $image;
        }

        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $resized;
    }

    private function encode(GdImage $image, int $type): ProcessedImage
    {
        ob_start();

        $ok = match ($type) {
            IMAGETYPE_JPEG => (function () use ($image): bool {
                imageinterlace($image, true);

                return imagejpeg($image, null, 85);
            })(),
            IMAGETYPE_PNG => (function () use ($image): bool {
                imagesavealpha($image, true);

                return imagepng($image, null, 6);
            })(),
            default => imagewebp($image, null, 82),
        };

        $contents = (string) ob_get_clean();

        if (! $ok || $contents === '') {
            $this->fail('images', 'We could not process this image. Try a different file.');
        }

        [$mime, $extension] = match ($type) {
            IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
            IMAGETYPE_PNG => ['image/png', 'png'],
            default => ['image/webp', 'webp'],
        };

        return new ProcessedImage($contents, $mime, $extension, imagesx($image), imagesy($image));
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
