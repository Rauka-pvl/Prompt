<?php

namespace App\Services;

use App\Support\ImageDataUri;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiImageSimilarity
{
    private const SYSTEM_PROMPT = 'You are a fair judge in a party game. Players describe a photo in words, an AI draws a new picture from that description alone, and you score how similar the drawing is to the original photo as a whole. Judge the overall impression a person gets when looking at both pictures side by side, not pixel details. Answer with a single integer from 0 to 100 and nothing else.';

    private const PROMPT = <<<'TXT'
Image 1 is the original photo. Image 2 was drawn by an AI from a player's description.
How similar is image 2 to image 1 overall, as a whole picture? Score from 0 to 100.
Calibration:
95-100: looks like the same picture.
80-94: same subject and scene, only small differences.
60-79: clearly the same idea, but noticeable differences.
35-59: partly similar, several important things differ.
10-34: only a few things in common.
0-9: unrelated.
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
