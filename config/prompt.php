<?php

return [
    'admin_password' => env('ADMIN_PASSWORD', 'njjh-gjk-2000'),
    'fal_key' => env('FAL_KEY'),
    'fal_model' => env('FAL_MODEL', 'fal-ai/flux/schnell'),

    // Original photo is secretly passed as an img2img reference.
    // strength: 1.0 ignores the original, lower values copy it more.
    'reference_enabled' => (bool) env('FAL_REFERENCE_ENABLED', true),
    'reference_model' => env('FAL_REFERENCE_MODEL', 'fal-ai/flux/dev/image-to-image'),
    'reference_strength' => (float) env('FAL_REFERENCE_STRENGTH', 0.7),

    // Speech-to-text for Kazakh/Russian/mixed speech; fallback is tried if the main model fails.
    'stt_model' => env('PROMPT_STT_MODEL', 'fal-ai/elevenlabs/speech-to-text'),
    'stt_fallback_model' => env('PROMPT_STT_FALLBACK_MODEL', 'fal-ai/whisper'),

    // pixel | perceptual | ai (ai falls back to perceptual on failure)
    'similarity' => env('PROMPT_SIMILARITY', 'ai'),
    'similarity_ai_model' => env('PROMPT_SIMILARITY_AI_MODEL', 'google/gemini-2.5-flash'),
];
