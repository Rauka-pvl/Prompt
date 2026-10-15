<?php

namespace App\Contracts;

interface ImageGenerator
{
    public function generate(string $prompt, string $directory, ?string $referencePath = null): string;
}
