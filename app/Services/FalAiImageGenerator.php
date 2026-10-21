<?php

namespace App\Services;

use App\Contracts\ImageGenerator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Text-to-image from the player's words only; the original photo never reaches the model.
 */
class FalAiImageGenerator implements ImageGenerator
{
    public function __construct(private readonly PromptTranslator $translator) {}

    public function generate(string $prompt, string $directory, ?string $imageSize = null): string
    {
        $key = (string) config('prompt.fal_key');

        if ($key === '') {
            throw new RuntimeException('FAL_KEY is not set.');
        }

        $model = trim((string) config('prompt.fal_model'), '/');
        $payload = [
            'prompt' => $this->translator->toEnglish($prompt),
            'image_size' => $imageSize ?? 'square_hd',
            'num_images' => 1,
            'output_format' => 'png',
            'enable_safety_checker' => true,
        ];

        if (str_contains($model, 'schnell')) {
            $payload['num_inference_steps'] = 4;
        } else {
            $payload['num_inference_steps'] = 28;
            $payload['guidance_scale'] = 3.5;
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
}
