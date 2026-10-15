<?php

namespace App\Services;

use App\Contracts\ImageGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MockImageGenerator implements ImageGenerator
{
    public function generate(string $prompt, string $directory, ?string $referencePath = null): string
    {
        $width = 768;
        $height = 768;
        $image = imagecreatetruecolor($width, $height);

        if ($image === false) {
            throw new RuntimeException('Не удалось создать изображение.');
        }

        $hash = md5($prompt !== '' ? $prompt : Str::random(8));
        $palette = $this->paletteFromHash($hash, $image);

        $this->fillBackground($image, $width, $height, $palette);
        $this->drawShapes($image, $hash, $width, $height, $palette);
        $this->drawPrompt($image, $prompt, $width, $height, $palette['ink']);

        $relativePath = trim($directory, '/').'/'.Str::uuid().'.png';
        $absolutePath = Storage::disk('public')->path($relativePath);

        Storage::disk('public')->makeDirectory(dirname($relativePath));

        if (! imagepng($image, $absolutePath)) {
            imagedestroy($image);
            throw new RuntimeException('Не удалось сохранить сгенерированное изображение.');
        }

        imagedestroy($image);

        return $relativePath;
    }

    /**
     * @return array{bg: int, mid: int, accent: int, ink: int}
     */
    private function paletteFromHash(string $hash, \GdImage $image): array
    {
        $r = hexdec(substr($hash, 0, 2));
        $g = hexdec(substr($hash, 2, 2));
        $b = hexdec(substr($hash, 4, 2));

        return [
            'bg' => imagecolorallocate($image, max(12, intdiv($r, 4)), max(10, intdiv($g, 5)), max(28, intdiv($b, 3))),
            'mid' => imagecolorallocate($image, min(180, $r), min(140, $g), min(220, $b + 40)),
            'accent' => imagecolorallocate($image, min(255, $r + 80), min(220, $g + 40), min(160, $b)),
            'ink' => imagecolorallocate($image, 248, 244, 232),
        ];
    }

    /**
     * @param  array{bg: int, mid: int, accent: int, ink: int}  $palette
     */
    private function fillBackground(\GdImage $image, int $width, int $height, array $palette): void
    {
        imagefilledrectangle($image, 0, 0, $width, $height, $palette['bg']);

        for ($i = 0; $i < 8; $i++) {
            $color = $i % 2 === 0 ? $palette['mid'] : $palette['accent'];
            imagefilledellipse(
                $image,
                random_int(0, $width),
                random_int(0, $height),
                random_int(80, 280),
                random_int(80, 280),
                $color
            );
        }
    }

    /**
     * @param  array{bg: int, mid: int, accent: int, ink: int}  $palette
     */
    private function drawShapes(\GdImage $image, string $hash, int $width, int $height, array $palette): void
    {
        imagesetthickness($image, 6);

        for ($i = 0; $i < 5; $i++) {
            $x1 = hexdec(substr($hash, $i * 2, 2)) % $width;
            $y1 = hexdec(substr($hash, $i * 2 + 2, 2)) % $height;
            $x2 = hexdec(substr($hash, $i * 2 + 4, 2)) % $width;
            $y2 = hexdec(substr($hash, $i * 2 + 6, 2)) % $height;
            imageline($image, $x1, $y1, $x2, $y2, $palette['ink']);
        }
    }

    private function drawPrompt(\GdImage $image, string $prompt, int $width, int $height, int $ink): void
    {
        $text = $prompt !== '' ? $prompt : 'пусто';
        $font = $this->fontPath();
        $boxX = 48;
        $boxY = (int) ($height * 0.62);
        $boxW = $width - 96;
        $boxH = (int) ($height * 0.32);
        $overlay = imagecolorallocatealpha($image, 8, 8, 16, 55);

        if ($overlay !== false) {
            imagefilledrectangle($image, $boxX - 16, $boxY - 24, $boxX + $boxW + 16, $boxY + $boxH, $overlay);
        }

        if ($font === null) {
            imagestring($image, 5, $boxX, $boxY, substr($text, 0, 80), $ink);

            return;
        }

        $wrapped = $this->wrapText($text, $font, 22, $boxW);

        imagettftext($image, 18, 0, $boxX, $boxY, $ink, $font, 'MOCK PROMPT');
        imagettftext($image, 22, 0, $boxX, $boxY + 42, $ink, $font, $wrapped);
    }

    private function wrapText(string $text, string $font, int $size, int $maxWidth): string
    {
        $words = preg_split('/\s+/u', $text) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $trial = $current === '' ? $word : $current.' '.$word;
            $box = imagettfbbox($size, 0, $font, $trial);
            $lineWidth = $box ? abs($box[2] - $box[0]) : 0;

            if ($lineWidth > $maxWidth && $current !== '') {
                $lines[] = $current;
                $current = $word;
            } else {
                $current = $trial;
            }

            if (count($lines) >= 5) {
                break;
            }
        }

        if ($current !== '' && count($lines) < 5) {
            $lines[] = $current;
        }

        return implode("\n", $lines);
    }

    private function fontPath(): ?string
    {
        $candidates = [
            resource_path('fonts/DejaVuSans.ttf'),
            '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
            '/System/Library/Fonts/Supplemental/Arial.ttf',
            '/Library/Fonts/Arial Unicode.ttf',
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/truetype/liberation/LiberationSans-Regular.ttf',
        ];

        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }

        return null;
    }
}
