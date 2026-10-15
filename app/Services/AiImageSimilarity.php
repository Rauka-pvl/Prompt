<?php

namespace App\Services;

use App\Support\ImageDataUri;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiImageSimilarity
{
    private const SYSTEM_PROMPT = 'You are a strict judge in a party game. Players describe a photo in words, an AI draws a picture from the description, and you score how close the drawing is to the original photo. Answer with a single integer from 0 to 100 and nothing else.';

    private const PROMPT = 'Image 1 is the original photo. Image 2 is the generated attempt. Rate their similarity from 0 to 100, weighing: main subject and objects (50%), composition and layout (25%), colors and lighting (15%), style and mood (10%). 0 means unrelated, 100 means practically the same picture. Reply with the number only.';

    public function compare(string $originalPath, string $generatedPath): float
    {
        $key = (string) config('prompt.fal_key');

        if ($key === '') {
            throw new RuntimeException('FAL_KEY is not set.');
        }

        $response = Http::timeout(60)
            ->acceptJson()
            ->withToken($key, 'Key')
            ->post('https://fal.run/openrouter/router/vision', [
                'model' => (string) config('prompt.similarity_ai_model'),
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
