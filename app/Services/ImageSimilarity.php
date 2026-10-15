<?php

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

class ImageSimilarity
{
    private const SAMPLE = 32;

    private const HISTOGRAM_BINS = 4;

    public function __construct(private readonly AiImageSimilarity $ai) {}

    public function compare(string $pathA, string $pathB): float
    {
        return match (config('prompt.similarity')) {
            'pixel' => $this->pixel($pathA, $pathB),
            'perceptual' => $this->perceptual($pathA, $pathB),
            default => $this->viaAi($pathA, $pathB),
        };
    }

    public function viaAi(string $pathA, string $pathB): float
    {
        if (blank(config('prompt.fal_key'))) {
            return $this->perceptual($pathA, $pathB);
        }

        try {
            return $this->ai->compare($pathA, $pathB);
        } catch (\Throwable $exception) {
            report($exception);

            return $this->perceptual($pathA, $pathB);
        }
    }

    /**
     * Average RGB distance between 32×32 thumbnails.
     */
    public function pixel(string $pathA, string $pathB): float
    {
        $a = $this->sample($pathA, self::SAMPLE, self::SAMPLE);
        $b = $this->sample($pathB, self::SAMPLE, self::SAMPLE);

        $distance = 0.0;
        $pixels = self::SAMPLE * self::SAMPLE;

        for ($i = 0; $i < $pixels; $i++) {
            $distance += hypot(
                hypot($a[$i][0] - $b[$i][0], $a[$i][1] - $b[$i][1]),
                $a[$i][2] - $b[$i][2]
            );
        }

        $maxDistance = $pixels * hypot(hypot(255, 255), 255);
        $similarity = (1 - ($distance / $maxDistance)) * 100;

        return round(max(0, min(100, $similarity)), 2);
    }

    /**
     * Half color palette (histogram intersection), half shape (difference hash).
     */
    public function perceptual(string $pathA, string $pathB): float
    {
        $colors = $this->histogramSimilarity(
            $this->sample($pathA, self::SAMPLE, self::SAMPLE),
            $this->sample($pathB, self::SAMPLE, self::SAMPLE),
        );

        $hashA = $this->differenceHash($this->sample($pathA, 9, 8));
        $hashB = $this->differenceHash($this->sample($pathB, 9, 8));
        $matching = count(array_filter(array_map(fn ($x, $y) => $x === $y, $hashA, $hashB)));

        // Unrelated images match ~50% of hash bits by chance, so rescale 0.5..1 → 0..1.
        $shape = max(0, ($matching / count($hashA) - 0.5) / 0.5);

        return round(max(0, min(100, ($colors * 0.5 + $shape * 0.5) * 100)), 2);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $a
     * @param  list<array{0: int, 1: int, 2: int}>  $b
     */
    private function histogramSimilarity(array $a, array $b): float
    {
        $histA = $this->histogram($a);
        $histB = $this->histogram($b);
        $intersection = 0.0;

        foreach ($histA as $bin => $value) {
            $intersection += min($value, $histB[$bin]);
        }

        return $intersection;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $pixels
     * @return list<float>
     */
    private function histogram(array $pixels): array
    {
        $bins = self::HISTOGRAM_BINS;
        $histogram = array_fill(0, $bins ** 3, 0.0);
        $step = intdiv(256, $bins);

        foreach ($pixels as [$r, $g, $b]) {
            $index = intdiv($r, $step) * $bins * $bins + intdiv($g, $step) * $bins + intdiv($b, $step);
            $histogram[$index]++;
        }

        $total = count($pixels);

        return array_map(fn ($count) => $count / $total, $histogram);
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $pixels  9×8 sample
     * @return list<bool>
     */
    private function differenceHash(array $pixels): array
    {
        $gray = array_map(fn ($p) => 0.299 * $p[0] + 0.587 * $p[1] + 0.114 * $p[2], $pixels);
        $bits = [];

        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $bits[] = $gray[$y * 9 + $x] < $gray[$y * 9 + $x + 1];
            }
        }

        return $bits;
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}>
     */
    private function sample(string $path, int $width, int $height): array
    {
        if (! is_readable($path)) {
            throw new InvalidArgumentException("Файл недоступен: {$path}");
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Не удалось прочитать файл: {$path}");
        }

        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw new RuntimeException("Не удалось открыть изображение: {$path}");
        }

        $sample = imagecreatetruecolor($width, $height);

        if ($sample === false) {
            imagedestroy($source);
            throw new RuntimeException('Не удалось создать образец изображения.');
        }

        imagecopyresampled(
            $sample,
            $source,
            0,
            0,
            0,
            0,
            $width,
            $height,
            imagesx($source),
            imagesy($source)
        );

        $pixels = [];

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorat($sample, $x, $y);
                $pixels[] = [
                    ($color >> 16) & 0xFF,
                    ($color >> 8) & 0xFF,
                    $color & 0xFF,
                ];
            }
        }

        imagedestroy($source);
        imagedestroy($sample);

        return $pixels;
    }
}
