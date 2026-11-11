<?php

namespace App\Services\TypeSafe;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente HTTP de Jev (TypeSafe AI, modelo System One): POST /v1/systemone.
 * Docs: https://docs.typesafe.ai/go/api.md · Esquema: https://api.typesafe.ai/openapi.json
 *
 * - La API key solo vive en el servidor; nunca se registra ni se devuelve.
 * - Reintentos limitados solo para 429 (límite) y 529 (saturado), y una vez ante fallos de red.
 * - Cada respuesta se valida contra las preguntas enviadas antes de que el código la use.
 */
class JevClient
{
    private const MAX_ATTEMPTS = 3;

    private const MAX_BACKOFF_MS = 3000;

    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('services.typesafe', []);
    }

    public function isConfigured(): bool
    {
        return filled($this->config['api_key'] ?? null);
    }

    /**
     * @param  array  $state  contexto que todas las preguntas comparten (objeto JSON con campos con nombre)
     * @param  array<string, array>  $questions  preguntas por nombre (noul, choice o score)
     */
    public function ask(array $state, array $questions): JevResult
    {
        if (! $this->isConfigured()) {
            throw new JevException(JevException::NOT_CONFIGURED, 'Falta TYPESAFE_API_KEY en el .env del backend.');
        }
        if ($questions === []) {
            throw new JevException(JevException::INVALID_RESPONSE, 'No hay preguntas para Jev.');
        }

        $payload = [
            'model' => (string) ($this->config['model'] ?? 'jev-latest'),
            'state' => $state,
            'questions' => $questions,
        ];

        $response = $this->send($payload);

        return $this->validate($response->json(), $questions);
    }

    private function send(array $payload): Response
    {
        $networkRetried = false;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $response = Http::withToken((string) $this->config['api_key'])
                    ->acceptJson()
                    ->asJson()
                    ->timeout((int) ($this->config['timeout'] ?? 20))
                    ->post((string) ($this->config['endpoint'] ?? 'https://api.typesafe.ai/v1/systemone'), $payload);
            } catch (ConnectionException) {
                if ($networkRetried) {
                    throw new JevException(JevException::UNAVAILABLE, 'No se pudo conectar con TypeSafe AI.');
                }
                $networkRetried = true;

                continue;
            }

            if ($response->successful()) {
                return $response;
            }

            $status = $response->status();
            if (in_array($status, [429, 529], true) && $attempt < self::MAX_ATTEMPTS) {
                usleep($this->backoffMs($response, $attempt) * 1000);

                continue;
            }

            throw match (true) {
                $status === 401 => new JevException(JevException::REJECTED, 'TypeSafe AI rechazó la API key (HTTP 401). Revisa TYPESAFE_API_KEY.', 401),
                in_array($status, [400, 413, 415, 422], true) => new JevException(JevException::REJECTED, "TypeSafe AI rechazó la solicitud (HTTP {$status}).", $status),
                default => new JevException(JevException::UNAVAILABLE, "TypeSafe AI no está disponible (HTTP {$status}).", $status),
            };
        }

        throw new JevException(JevException::UNAVAILABLE, 'TypeSafe AI no respondió a tiempo.');
    }

    /** Respeta retry-after si viene (acotado); si no, espera creciente corta. */
    private function backoffMs(Response $response, int $attempt): int
    {
        $retryAfter = $response->header('Retry-After');
        $ms = is_numeric($retryAfter) ? (int) ((float) $retryAfter * 1000) : 400 * (2 ** ($attempt - 1));

        return max(0, min($ms, self::MAX_BACKOFF_MS));
    }

    /** Valida tipo, rango y opciones de cada respuesta frente a la pregunta enviada. */
    private function validate(mixed $body, array $questions): JevResult
    {
        $invalid = fn (string $why) => new JevException(JevException::INVALID_RESPONSE, "Respuesta de Jev inválida: {$why}.");

        if (! is_array($body) || ! is_array($body['answers'] ?? null) || ! is_string($body['model'] ?? null)) {
            throw $invalid('estructura');
        }

        $answers = [];
        foreach ($questions as $key => $question) {
            $answer = $body['answers'][$key] ?? null;
            $type = $question['type'];
            if (! is_array($answer) || ($answer['type'] ?? null) !== $type) {
                throw $invalid("falta {$key}");
            }

            $answers[$key] = match ($type) {
                'noul' => ['type' => 'noul', 'noul' => $this->probability($answer['noul'] ?? null) ?? throw $invalid("{$key}.noul")],
                'choice' => $this->choice($answer, array_keys($question['criteria']), $key, $invalid),
                'score' => $this->score($answer, count($question['criteria']), $key, $invalid),
                default => throw $invalid("tipo {$type} no soportado"),
            };
        }

        return new JevResult($body['model'], $answers);
    }

    private function choice(array $answer, array $options, string $key, \Closure $invalid): array
    {
        $choice = $answer['choice'] ?? null;
        $confidence = $this->probability($answer['confidence'] ?? null);
        $probabilities = [];
        foreach ((array) ($answer['probabilities'] ?? []) as $option => $p) {
            $option = (string) $option;
            if (! in_array($option, $options, true) || ($p = $this->probability($p)) === null) {
                throw $invalid("{$key}.probabilities");
            }
            $probabilities[$option] = $p;
        }

        if (! is_string($choice) || ! in_array($choice, $options, true) || $confidence === null || $probabilities === []) {
            throw $invalid($key);
        }

        return ['type' => 'choice', 'choice' => $choice, 'confidence' => $confidence, 'probabilities' => $probabilities];
    }

    private function score(array $answer, int $levels, string $key, \Closure $invalid): array
    {
        $score = $answer['score'] ?? null;
        $confidence = $this->probability($answer['confidence'] ?? null);
        if (! is_numeric($score) || (float) $score < 0 || (float) $score > $levels - 1 || $confidence === null) {
            throw $invalid($key);
        }

        // json_decode convierte las claves "0", "1"... en enteros; se normaliza a lista por nivel.
        $probabilities = array_fill(0, $levels, 0.0);
        foreach ((array) ($answer['probabilities'] ?? []) as $level => $p) {
            if (! is_numeric($level) || (int) $level < 0 || (int) $level >= $levels || ($p = $this->probability($p)) === null) {
                throw $invalid("{$key}.probabilities");
            }
            $probabilities[(int) $level] = $p;
        }

        return ['type' => 'score', 'score' => (float) $score, 'confidence' => $confidence, 'probabilities' => $probabilities];
    }

    private function probability(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 && (float) $value <= 1 ? (float) $value : null;
    }
}
