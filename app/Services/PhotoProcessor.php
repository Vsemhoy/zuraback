<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class PhotoProcessor
{
    public function compress(UploadedFile $file, int $limit = 2000): string
    {
        $size = @getimagesize($file->getRealPath());
        if (! $size || $size[0] * $size[1] > 24000000) {
            throw ValidationException::withMessages(['file' => 'Фотография должна быть не больше 24 мегапикселей.']);
        }
        $image = @imagecreatefromstring(file_get_contents($file->getRealPath()));
        if (! $image) {
            throw ValidationException::withMessages(['file' => 'Не удалось прочитать изображение.']);
        }
        try {
            if ($file->getMimeType() === 'image/jpeg' && function_exists('exif_read_data')) {
                $orientation = (int) ((@exif_read_data($file->getRealPath()))['Orientation'] ?? 1);
                if (in_array($orientation, [2, 4, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                $angle = match ($orientation) {
                    3, 4 => 180, 5, 6 => -90, 7, 8 => 90, default => 0
                };
                if ($angle) {
                    $rotated = imagerotate($image, $angle, 0);
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
            $ratio = min(1, $limit / max(imagesx($image), imagesy($image)));
            $preview = imagecreatetruecolor(max(1, (int) round(imagesx($image) * $ratio)), max(1, (int) round(imagesy($image) * $ratio)));
            try {
                imagealphablending($preview, false);
                imagesavealpha($preview, true);
                imagecopyresampled($preview, $image, 0, 0, 0, 0, imagesx($preview), imagesy($preview), imagesx($image), imagesy($image));
                ob_start();
                try {
                    $ok = imagewebp($preview, null, 85);
                    $bytes = ob_get_contents();
                } finally {
                    ob_end_clean();
                }
                if (! $ok || ! $bytes) {
                    throw ValidationException::withMessages(['file' => 'Не удалось сжать фотографию.']);
                }

                return $bytes;
            } finally {
                imagedestroy($preview);
            }
        } finally {
            imagedestroy($image);
        }
    }
}
