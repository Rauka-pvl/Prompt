<?php

namespace App\Contracts;

interface ImageGenerator
{
    /**
     * @param  string|null  $imageSize  fal preset such as square_hd or landscape_4_3
     */
    public function generate(string $prompt, string $directory, ?string $imageSize = null): string;
}
