<?php

return [
    'admin_password' => env('ADMIN_PASSWORD', 'njjh-gjk-2000'),
    'fal_key' => env('FAL_KEY'),
    // Text-to-image from the player's words only; the original photo is never sent.
    'fal_model' => env('FAL_MODEL', 'fal-ai/flux/dev'),

    // Speech-to-text for Kazakh/Russian/mixed speech; fallback is tried if the main model fails.
    'stt_model' => env('PROMPT_STT_MODEL', 'fal-ai/elevenlabs/speech-to-text'),
    'stt_fallback_model' => env('PROMPT_STT_FALLBACK_MODEL', 'fal-ai/whisper'),

    // Player text is translated to English before generation (FLUX understands English best).
    'translate_enabled' => (bool) env('PROMPT_TRANSLATE_ENABLED', true),
    'translate_model' => env('PROMPT_TRANSLATE_MODEL', 'google/gemini-2.5-flash'),

    // Similarity is always judged by a vision LLM; the backup model is tried if the main one fails.
    'similarity_ai_model' => env('PROMPT_SIMILARITY_AI_MODEL', 'google/gemini-2.5-flash'),
    'similarity_ai_backup_model' => env('PROMPT_SIMILARITY_AI_BACKUP_MODEL', 'openai/gpt-4o-mini'),
];
