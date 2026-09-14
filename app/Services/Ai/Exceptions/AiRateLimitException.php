<?php

namespace App\Services\Ai\Exceptions;

/**
 * Levée quand l'API AI renvoie un 429 (rate limit dépassé).
 * Permet à l'orchestrateur de basculer immédiatement sur le fallback local.
 */
class AiRateLimitException extends \RuntimeException
{
    public function __construct(string $message, public readonly float $retryAfterSeconds = 5.0)
    {
        parent::__construct($message);
    }
}
