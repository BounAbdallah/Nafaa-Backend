<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AI Provider credentials
    |--------------------------------------------------------------------------
    | Par défaut : Groq (gratuit, généreux, OpenAI-compatible).
    | Obtiens une clé gratuite sur https://console.groq.com → API Keys
    |
    | Pour revenir à Hugging Face, remplace les valeurs dans .env :
    |   AI_TOKEN=hf_xxxx
    |   AI_BASE_URL=https://router.huggingface.co/v1
    |   AI_CHAT_MODEL=meta-llama/Llama-3.3-70B-Instruct
    |   AI_WHISPER_MODEL=openai/whisper-large-v3
    */
    'token'    => env('AI_TOKEN',    env('HF_TOKEN', '')),
    'base_url' => env('AI_BASE_URL', 'https://api.groq.com/openai/v1'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Groq :
    |   Chat    → llama-3.3-70b-versatile  (tool-calling + FR)
    |   Whisper → whisper-large-v3          (meilleure précision FR)
    |            ou whisper-large-v3-turbo  (2× plus rapide, légèrement moins précis)
    */
    'chat_model'    => env('AI_CHAT_MODEL',    'llama-3.3-70b-versatile'),
    'whisper_model' => env('AI_WHISPER_MODEL', 'whisper-large-v3'),

    'timeout' => env('AI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Audio uploads
    |--------------------------------------------------------------------------
    */
    'max_audio_seconds' => 30,
    'max_audio_kb'      => 5_120,

    // Rétrocompatibilité — HF_TOKEN reste lisible si AI_TOKEN absent
    'hf_token'    => env('HF_TOKEN', ''),
    'hf_base_url' => env('HF_BASE_URL', 'https://router.huggingface.co/v1'),
];
