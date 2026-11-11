<?php

namespace App\Services\TypeSafe;

/**
 * Respuestas ya validadas de Jev. Solo decisiones tipadas y probabilidades: Jev no genera texto.
 */
final class JevResult
{
    /** @param array<string, array> $answers respuestas normalizadas por nombre de pregunta */
    public function __construct(public readonly string $model, public readonly array $answers) {}

    /** Probabilidad (0–1) de que el enunciado sea verdadero. */
    public function noul(string $key): float
    {
        return $this->answer($key, 'noul')['noul'];
    }

    /** @return array{choice: string, confidence: float, probabilities: array<string, float>} */
    public function choice(string $key): array
    {
        return $this->answer($key, 'choice');
    }

    /** @return array{score: float, confidence: float, probabilities: list<float>} */
    public function score(string $key): array
    {
        return $this->answer($key, 'score');
    }

    public function has(string $key): bool
    {
        return isset($this->answers[$key]);
    }

    private function answer(string $key, string $type): array
    {
        $answer = $this->answers[$key] ?? null;
        if (! is_array($answer) || ($answer['type'] ?? null) !== $type) {
            throw new JevException(JevException::INVALID_RESPONSE, "Jev no devolvió la respuesta \"{$key}\".");
        }

        return $answer;
    }
}
