<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Hugging Face credentials
    |--------------------------------------------------------------------------
    | Get a free token at https://huggingface.co/settings/tokens
    | (read-only is enough for the Inference API).
    */
    'hf_token'    => env('HF_TOKEN', ''),
    'hf_base_url' => env('HF_BASE_URL', 'https://router.huggingface.co/v1'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    | Llama 3.3 70B works well for FR + tool-calling.
    | Whisper turbo is the fastest STT with proper FR support.
    */
    'chat_model'    => env('AI_CHAT_MODEL',    'meta-llama/Llama-3.3-70B-Instruct'),
    'whisper_model' => env('AI_WHISPER_MODEL', 'openai/whisper-large-v3-turbo'),

    'timeout' => env('AI_TIMEOUT', 60),

    /*
    |--------------------------------------------------------------------------
    | Audio uploads
    |--------------------------------------------------------------------------
    */
    'max_audio_seconds' => 30,
    'max_audio_kb'      => 5_120,
];
