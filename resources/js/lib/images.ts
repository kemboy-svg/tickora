// Client-side preparation of event photos before upload.
//
// Phone photos are often 4–10 MB, more than many PHP installs accept by
// default (upload_max_filesize = 2M). Large images are scaled down in the
// browser first; the server still validates and re-encodes every file.

export const ACCEPTED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

const MAX_EDGE = 2400;
const SHRINK_ABOVE_BYTES = 1.5 * 1024 * 1024;

export function isAcceptedImage(file: File): boolean {
    return ACCEPTED_IMAGE_TYPES.includes(file.type);
}

function canvasToBlob(
    canvas: HTMLCanvasElement,
    type: string,
    quality: number,
): Promise<Blob | null> {
    return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
}

/**
 * Returns the file unchanged when it is small enough (or cannot be
 * processed here), otherwise a scaled-down JPEG/WebP copy.
 */
export async function prepareImage(file: File): Promise<File> {
    if (!isAcceptedImage(file) || file.size <= SHRINK_ABOVE_BYTES) {
        return file;
    }

    try {
        const bitmap = await createImageBitmap(file, {
            imageOrientation: 'from-image',
        });
        const scale = Math.min(
            1,
            MAX_EDGE / Math.max(bitmap.width, bitmap.height),
        );
        const width = Math.round(bitmap.width * scale);
        const height = Math.round(bitmap.height * scale);

        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const context = canvas.getContext('2d');

        if (!context) {
            return file;
        }

        context.drawImage(bitmap, 0, 0, width, height);
        bitmap.close();

        // PNGs may be transparent: WebP keeps that, JPEG would not.
        const type = file.type === 'image/png' ? 'image/webp' : 'image/jpeg';
        const blob = await canvasToBlob(canvas, type, 0.86);

        if (!blob || blob.size >= file.size) {
            return file;
        }

        const extension = type === 'image/webp' ? 'webp' : 'jpg';
        const name = file.name.replace(/\.[^.]+$/, '') + '.' + extension;

        return new File([blob], name, { type, lastModified: Date.now() });
    } catch {
        return file;
    }
}

export function formatBytes(bytes: number): string {
    return bytes >= 1024 * 1024
        ? `${(bytes / (1024 * 1024)).toFixed(1)} MB`
        : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}
