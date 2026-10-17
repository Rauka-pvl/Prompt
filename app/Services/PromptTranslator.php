<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * FLUX understands English far better than Russian or Kazakh, so player text
 * is translated before generation. Any failure falls back to the original text.
 */
class PromptTranslator
{
    private const SYSTEM_PROMPT = 'You translate party-game descriptions of a photo into an English prompt for an image generator. The text may be Russian, Kazakh or a mix. Translate faithfully: keep every detail the player said, do not add objects, do not guess what the subject is, do not name anything the player did not name. Fix obvious speech-recognition typos. Output only the English prompt, one line.';

    public function toEnglish(string $prompt): string
    {
        $prompt = trim($prompt, " \t\n\r\0\x0B,.;");
        $key = (string) config('prompt.fal_key');

        if ($prompt === '' || $key === '' || ! config('prompt.translate_enabled')) {
            return $prompt;
        }

        try {
            $response = Http::timeout(20)
                ->acceptJson()
                ->withToken($key, 'Key')
                ->post('https://fal.run/openrouter/router', [
                    'model' => (string) config('prompt.translate_model'),
                    'system_prompt' => self::SYSTEM_PROMPT,
                    'prompt' => $prompt,
                    'temperature' => 0,
                    'max_tokens' => 400,
                ]);

            $output = trim((string) data_get($response->json(), 'output', ''), " \t\n\r\0\x0B\"'");

            return $response->successful() && $output !== '' ? $output : $prompt;
        } catch (\Throwable $exception) {
            report($exception);

            return $prompt;
        }
    }
}
