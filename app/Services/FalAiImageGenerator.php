<?php

namespace App\Services;

use App\Contracts\ImageGenerator;
use App\Support\ImageDataUri;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FalAiImageGenerator implements ImageGenerator
{
    public function generate(string $prompt, string $directory, ?string $referencePath = null): string
    {
        $key = (string) config('prompt.fal_key');

        if ($key === '') {
            throw new RuntimeException('FAL_KEY is not set.');
        }

        if ($referencePath !== null && config('prompt.reference_enabled')) {
            $model = trim((string) config('prompt.reference_model'), '/');
            $payload = [
                'prompt' => $this->anchorPrompt($prompt),
                'image_url' => ImageDataUri::fromPath($referencePath, 1024),
                'strength' => max(0.5, min(1.0, (float) config('prompt.reference_strength'))),
                'num_images' => 1,
                'num_inference_steps' => 28,
                'output_format' => 'png',
            ];
        } else {
            $model = trim((string) config('prompt.fal_model'), '/');
            $payload = [
                'prompt' => $prompt,
                'image_size' => 'square_hd',
                'num_images' => 1,
                'num_inference_steps' => 4,
                'output_format' => 'png',
            ];
        }

        $response = Http::timeout(90)
            ->acceptJson()
            ->withToken($key, 'Key')
            ->post('https://fal.run/'.$model, $payload);

        if (! $response->successful()) {
            $detail = data_get($response->json(), 'detail', '');
            $detail = is_string($detail) ? $detail : (string) json_encode($detail);

            if ($response->status() === 403 || str_contains(mb_strtolower($detail), 'balance') || str_contains(mb_strtolower($detail), 'top')) {
                throw new RuntimeException(__('messages.js.fal_balance'));
            }

            throw new RuntimeException(__('messages.js.fal_error', ['status' => $response->status()]));
        }

        $imageUrl = data_get($response->json(), 'images.0.url');

        if (! is_string($imageUrl) || $imageUrl === '') {
            throw new RuntimeException(__('messages.js.fal_no_image'));
        }

        $image = Http::timeout(45)->get($imageUrl);

        if (! $image->successful() || $image->body() === '') {
            throw new RuntimeException(__('messages.js.fal_download'));
        }

        $relativePath = trim($directory, '/').'/'.Str::uuid().'.png';

        Storage::disk('public')->put($relativePath, $image->body());

        return $relativePath;
    }

    /**
     * The player's description leads; the reference photo only loosely
     * suggests the overall scene so weak prompts still land nearby.
     */
    private function anchorPrompt(string $prompt): string
    {
        $prompt = trim($prompt);

        if ($prompt === '') {
            return 'A photo loosely inspired by the reference image.';
        }

        return "{$prompt}. Photo, loosely inspired by the general scene of the reference image.";
    }
}
