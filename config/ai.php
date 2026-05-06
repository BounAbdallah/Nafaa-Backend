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
    | Groq — limites free tier (on_demand) :
    |   llama-3.1-8b-instant      → ~20 000 TPM  ← voix (rapide, léger)
    |   llama-3.3-70b-versatile   → 12 000 TPM   ← chat/import (puissant)
    |   whisper-large-v3          → pas de limite stricte TPM
    |   whisper-large-v3-turbo    → 2× plus rapide, légèrement moins précis FR
    |
    | AI_VOICE_MODEL peut être défini séparément pour les commandes vocales.
    | S'il est absent, on utilise AI_CHAT_MODEL.
    */
    'chat_model'    => env('AI_CHAT_MODEL',    'llama-3.3-70b-versatile'),
    'voice_model'   => env('AI_VOICE_MODEL',   'llama-3.1-8b-instant'),
    'whisper_model' => env('AI_WHISPER_MODEL', 'whisper-large-v3'),

    /*
    |--------------------------------------------------------------------------
    | Whisper provider séparé (optionnel)
    |--------------------------------------------------------------------------
    | Cerebras (LLM) ne supporte pas l'audio. Si tu utilises Cerebras pour le
    | chat, configure ici un provider séparé pour Whisper (ex : Groq).
    |
    | Si AI_WHISPER_TOKEN est absent → hérite de AI_TOKEN.
    | Si AI_WHISPER_BASE_URL est absent → Groq par défaut (seul provider free
    | qui expose Whisper en API OpenAI-compatible).
    |
    | Exemple .env pour Cerebras + Groq-Whisper :
    |   AI_TOKEN=csk-XXXXXXXXX
    |   AI_BASE_URL=https://api.cerebras.ai/v1
    |   AI_CHAT_MODEL=llama-3.3-70b
    |   AI_VOICE_MODEL=llama3.1-8b
    |   AI_WHISPER_TOKEN=gsk_xxxx        ← clé Groq
    |   AI_WHISPER_BASE_URL=https://api.groq.com/openai/v1
    |   AI_WHISPER_MODEL=whisper-large-v3
    */
    'whisper_token'    => env('AI_WHISPER_TOKEN',    null),
    'whisper_base_url' => env('AI_WHISPER_BASE_URL', 'https://api.groq.com/openai/v1'),

    /*
    |--------------------------------------------------------------------------
    | Retry on 429 (rate limit)
    |--------------------------------------------------------------------------
    | max_retries : nombre de tentatives après un 429 (0 = aucun retry)
    | retry_buffer_s : secondes de tampon ajoutées au délai Groq
    */
    'max_retries'    => env('AI_MAX_RETRIES',    2),
    'retry_buffer_s' => env('AI_RETRY_BUFFER_S', 1),

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
