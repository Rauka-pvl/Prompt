<?php

namespace App\Services;

use App\Support\ImageDataUri;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiImageSimilarity
{
    private const SYSTEM_PROMPT = 'You are a fair judge in a party game. Players describe a photo in words, an AI draws a picture from the description, and you score how close the drawing is to the original photo. Judge what a person would notice at a glance, not pixel details. Answer with a single integer from 0 to 100 and nothing else.';

    private const PROMPT = <<<'TXT'
Image 1 is the original photo. Image 2 is the generated attempt.
Weigh: main subject (50%), composition and pose (25%), colors and lighting (15%), setting and mood (10%).
Calibration:
90-100: almost the same picture.
75-89: same kind of subject in a similar scene, only minor differences (model, breed, small objects).
55-74: same kind of subject, but clear differences in pose, setting or colors.
30-54: different subject, but similar scene, composition or colors.
0-29: mostly unrelated.
Reply with the number only.
TXT;

    public function compare(string $originalPath, string $generatedPath, ?string $model = null): float
    {
        $key = (string) config('prompt.fal_key');

        if ($key === '') {
            throw new RuntimeException('FAL_KEY is not set.');
        }

        $response = Http::timeout(45)
            ->acceptJson()
            ->withToken($key, 'Key')
            ->post('https://fal.run/openrouter/router/vision', [
                'model' => $model ?? (string) config('prompt.similarity_ai_model'),
                'system_prompt' => self::SYSTEM_PROMPT,
                'prompt' => self::PROMPT,
                'image_urls' => [
                    ImageDataUri::fromPath($originalPath, 512),
                    ImageDataUri::fromPath($generatedPath, 512),
                ],
                'temperature' => 0,
                'max_tokens' => 10,
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('AI similarity request failed: '.$response->status());
        }

        $output = (string) data_get($response->json(), 'output', '');

        if (! preg_match('/\d+(?:[.,]\d+)?/', $output, $match)) {
            throw new RuntimeException('AI similarity returned an unparseable answer: '.$output);
        }

        $score = (float) str_replace(',', '.', $match[0]);

        return round(max(0, min(100, $score)), 2);
    }
}
