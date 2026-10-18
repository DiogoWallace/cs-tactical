<?php

namespace App\Modules\Learning\Exceptions;

use RuntimeException;

/**
 * Regra de tentativa que impede a ação (409, docs/api/academy-v1.md#códigos-de-erro-novos). O
 * `errorCode` é estável e vai para o corpo `{message, code}`.
 */
final class QuizAttemptConflict extends RuntimeException
{
    private function __construct(public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyPassed(): self
    {
        return new self('quiz_already_passed', 'Você já foi aprovado neste quiz.');
    }

    public static function exhausted(): self
    {
        return new self('quiz_attempts_exhausted', 'Você não tem mais tentativas neste quiz.');
    }

    public static function alreadySubmitted(): self
    {
        return new self('quiz_attempt_already_submitted', 'Esta tentativa já foi enviada.');
    }
}
