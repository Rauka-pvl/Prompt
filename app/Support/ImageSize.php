<?php

namespace App\Support;

class ImageSize
{
    private const PRESETS = [
        'portrait_16_9' => 9 / 16,
        'portrait_4_3' => 3 / 4,
        'square_hd' => 1.0,
        'landscape_4_3' => 4 / 3,
        'landscape_16_9' => 16 / 9,
    ];

    /**
     * Closest fal image_size preset to the photo's proportions.
     */
    public static function presetFor(?string $path): string
    {
        $info = $path !== null && is_readable($path) ? @getimagesize($path) : false;

        if (! $info || $info[0] <= 0 || $info[1] <= 0) {
            return 'square_hd';
        }

        $ratio = $info[0] / $info[1];

        // Phone photos are stored sideways with an EXIF flag; browsers show them rotated.
        if (($info[2] ?? null) === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) (@exif_read_data($path)['Orientation'] ?? 1);

            if ($orientation >= 5 && $orientation <= 8) {
                $ratio = 1 / $ratio;
            }
        }

        $best = 'square_hd';
        $bestDistance = INF;

        foreach (self::PRESETS as $name => $presetRatio) {
            $distance = abs(log($ratio / $presetRatio));

            if ($distance < $bestDistance) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }
}
