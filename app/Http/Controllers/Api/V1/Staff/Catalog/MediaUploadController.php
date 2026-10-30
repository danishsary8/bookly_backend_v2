<?php

namespace App\Http\Controllers\Api\V1\Staff\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MediaUploadController extends Controller
{
    /** Allowed MIME types for uploaded catalog images. */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /** Allowed file extensions. */
    private const ALLOWED_EXTS = [
        'jpg',
        'jpeg',
        'png',
        'webp',
    ];

    /** Max file size: 5 MB (in bytes). */
    private const MAX_BYTES = 5 * 1024 * 1024;

    /** Min / Max image pixel dimensions. */
    private const MIN_DIM = 100;

    private const MAX_DIM = 4000;

    /**
     * Upload and securely validate a media image (book cover, author photo).
     */
    public function upload(Request $request): JsonResponse
    {
        $file = $request->file('image') ?? $request->file('file');

        if (! $file || ! $file->isValid()) {
            throw ValidationException::withMessages([
                'image' => ['Please select a valid image file to upload.'],
            ]);
        }

        // 1. File size check
        if ($file->getSize() > self::MAX_BYTES) {
            throw ValidationException::withMessages([
                'image' => ['The image must not exceed 5 MB in size.'],
            ]);
        }

        // 2. Extension check
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, self::ALLOWED_EXTS, true)) {
            throw ValidationException::withMessages([
                'image' => ['The image must be a file of type: jpg, jpeg, png, webp.'],
            ]);
        }

        // 3. MIME inspection from actual file content (not just client headers)
        $realMime = $file->getMimeType();
        if (! in_array($realMime, self::ALLOWED_MIMES, true)) {
            throw ValidationException::withMessages([
                'image' => ['Invalid image file format. Only JPEG, PNG, and WebP are allowed.'],
            ]);
        }

        // 4. Magic bytes & dimension verification via getimagesize
        $imageInfo = @getimagesize($file->getRealPath());
        if ($imageInfo === false) {
            throw ValidationException::withMessages([
                'image' => ['The uploaded file is not a valid or recognizable image.'],
            ]);
        }

        [$width, $height] = $imageInfo;
        if ($width < self::MIN_DIM || $height < self::MIN_DIM) {
            throw ValidationException::withMessages([
                'image' => ["The image resolution is too small ({$width}x{$height}px). Minimum is ".self::MIN_DIM.'x'.self::MIN_DIM.'px.'],
            ]);
        }

        if ($width > self::MAX_DIM || $height > self::MAX_DIM) {
            throw ValidationException::withMessages([
                'image' => ["The image resolution is too large ({$width}x{$height}px). Maximum is ".self::MAX_DIM.'x'.self::MAX_DIM.'px.'],
            ]);
        }

        // 5. Store with a random UUID filename on the media disk (Cloudflare R2 in production, see config/filesystems.php)
        $disk = config('filesystems.media_disk');
        $filename = (string) Str::uuid().'.'.$ext;
        $folder = 'covers/'.date('Y/m');
        $path = $file->storeAs($folder, $filename, $disk);

        $url = Storage::disk($disk)->url($path);

        return response()->json([
            'url' => $url,
            'path' => $path,
            'filename' => $filename,
            'width' => $width,
            'height' => $height,
            'size_bytes' => $file->getSize(),
            'mime' => $realMime,
        ], 201);
    }
}
