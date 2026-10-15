<?php

namespace App\Support;

use RuntimeException;

class ImageDataUri
{
    public static function fromPath(string $path, int $maxSide = 768): string
    {
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new RuntimeException("Не удалось прочитать файл: {$path}");
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException("Не удалось открыть изображение: {$path}");
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $scale = min(1, $maxSide / max($width, $height));
        $targetW = max(1, (int) round($width * $scale));
        $targetH = max(1, (int) round($height * $scale));

        $target = imagecreatetruecolor($targetW, $targetH);
        imagecopyresampled($target, $source, 0, 0, 0, 0, $targetW, $targetH, $width, $height);

        ob_start();
        imagejpeg($target, null, 88);
        $jpeg = (string) ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        return 'data:image/jpeg;base64,'.base64_encode($jpeg);
    }
}
