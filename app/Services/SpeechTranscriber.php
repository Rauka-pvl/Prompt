<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Server-side speech-to-text for Kazakh, Russian and mixed speech.
 * The language is never forced, so code-switching inside one phrase survives.
 */
class SpeechTranscriber
{
    private const WHISPER_HINT = 'Қазақша және орысша аралас сөйлеу. Смешанная речь на казахском и русском языках. Фотоны сипаттау: машина, үй, адам, түсі.';

    public function transcribe(UploadedFile $audio): string
    {
        $key = (string) config('prompt.fal_key');

        if ($key === '') {
            throw new RuntimeException('FAL_KEY is not set.');
        }

        [$mime, $extension] = $this->audioType($audio);
        $body = (string) file_get_contents($audio->getRealPath());
        $dataUri = 'data:'.$mime.';base64,'.base64_encode($body);
        $hostedUrl = null;

        $models = array_values(array_unique(array_filter([
            (string) config('prompt.stt_model'),
            (string) config('prompt.stt_fallback_model'),
        ])));

        $lastError = null;

        foreach ($models as $model) {
            $model = trim($model, '/');

            try {
                // Uploading to fal storage costs several seconds, so only Whisper (which rejects data URIs) gets it.
                $audioUrl = str_contains($model, 'whisper')
                    ? ($hostedUrl ??= $this->upload($key, $body, $mime, $extension))
                    : $dataUri;

                $text = $this->callModel($key, $model, $audioUrl);

                if ($text !== '') {
                    return $text;
                }
            } catch (\Throwable $exception) {
                report($exception);
                $lastError = $exception;
            }
        }

        throw $lastError ?? new RuntimeException('Empty transcription.');
    }

    private function callModel(string $key, string $model, string $audioUrl): string
    {
        $payload = str_contains($model, 'whisper')
            ? [
                'audio_url' => $audioUrl,
                'task' => 'transcribe',
                'chunk_level' => 'none',
                'prompt' => self::WHISPER_HINT,
            ]
            : [
                'audio_url' => $audioUrl,
                'tag_audio_events' => false,
                'diarize' => false,
            ];

        $response = Http::timeout(40)
            ->acceptJson()
            ->withToken($key, 'Key')
            ->post('https://fal.run/'.$model, $payload);

        if (! $response->successful()) {
            throw new RuntimeException("Speech-to-text {$model} failed: ".$response->status());
        }

        return $this->normalize((string) data_get($response->json(), 'text', ''));
    }

    private function normalize(string $text): string
    {
        $text = preg_replace('/\([^)]*\)|\[[^\]]*\]/u', ' ', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function audioType(UploadedFile $audio): array
    {
        $mime = $audio->getMimeType() ?: 'audio/webm';

        if (str_starts_with($mime, 'video/')) {
            $mime = 'audio/'.substr($mime, 6);
        }

        $extension = match (true) {
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'wav') => 'wav',
            str_contains($mime, 'mpeg') => 'mp3',
            default => 'm4a',
        };

        return [$mime, $extension];
    }

    private function upload(string $key, string $body, string $mime, string $extension): string
    {
        $initiate = Http::timeout(20)
            ->acceptJson()
            ->withToken($key, 'Key')
            ->post('https://rest.alpha.fal.ai/storage/upload/initiate?storage_type=fal-cdn-v3', [
                'content_type' => $mime,
                'file_name' => 'speech.'.$extension,
            ]);

        $uploadUrl = $initiate->json('upload_url');
        $fileUrl = $initiate->json('file_url');

        if ($initiate->successful() && is_string($uploadUrl) && is_string($fileUrl)) {
            $put = Http::timeout(30)->withBody($body, $mime)->put($uploadUrl);

            if ($put->successful()) {
                return $fileUrl;
            }
        }

        return 'data:'.$mime.';base64,'.base64_encode($body);
    }
}
