<?php

namespace App\Services\Agent;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente de un modelo de lenguaje abierto con API compatible con OpenAI (/chat/completions con
 * herramientas). Funciona con Ollama en local y con proveedores que sirven modelos abiertos.
 * Nunca registra la API key ni el contenido de las conversaciones.
 */
final class LlmClient
{
    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) config('services.agent');
    }

    public function isConfigured(): bool
    {
        return ($this->config['base_url'] ?? '') !== '';
    }

    public function model(): string
    {
        return (string) $this->config['model'];
    }

    /**
     * @param  list<array>  $messages  mensajes en formato OpenAI
     * @param  list<array>  $tools  definiciones de herramientas (type=function)
     * @return array{content: string, tool_calls: list<array{id: string, name: string, arguments: array, raw: string}>, message: array}
     */
    public function chat(array $messages, array $tools = []): array
    {
        if (! $this->isConfigured()) {
            throw new LlmException('not_configured', 'El agente no está configurado.');
        }

        $payload = [
            'model' => $this->model(),
            'messages' => $messages,
            'temperature' => 0.3,
            'stream' => false,
        ];
        if ($tools !== []) {
            $payload['tools'] = $tools;
            $payload['tool_choice'] = 'auto';
        }
        if (($reasoning = (string) ($this->config['reasoning'] ?? '')) !== '') {
            $payload['reasoning_effort'] = $reasoning;
        }

        $response = $this->send($payload);
        $message = $response['choices'][0]['message'] ?? null;
        if (! is_array($message)) {
            throw new LlmException('invalid_response', 'El modelo devolvió una respuesta sin mensaje.');
        }

        $calls = [];
        foreach ((array) ($message['tool_calls'] ?? []) as $i => $call) {
            $name = (string) ($call['function']['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $raw = $call['function']['arguments'] ?? '{}';
            $arguments = is_array($raw) ? $raw : json_decode((string) $raw, true);
            $calls[] = [
                'id' => (string) ($call['id'] ?? 'call_'.$i),
                'name' => $name,
                'arguments' => is_array($arguments) ? $arguments : [],
                'raw' => is_string($raw) ? $raw : json_encode($raw),
            ];
        }

        return [
            'content' => self::clean((string) ($message['content'] ?? '')),
            'tool_calls' => $calls,
            'message' => $message,
        ];
    }

    /** Quita el razonamiento interno si el modelo lo deja en el texto (<think>…</think>). */
    public static function clean(string $content): string
    {
        $content = preg_replace('/<think>.*?<\/think>/su', '', $content) ?? $content;
        $content = preg_replace('/^.*<\/think>/su', '', $content) ?? $content;

        return trim($content);
    }

    private function send(array $payload): array
    {
        $url = $this->config['base_url'].'/chat/completions';
        $attempts = 0;

        while (true) {
            $attempts++;
            try {
                $request = Http::acceptJson()->asJson()->timeout(max(10, (int) $this->config['timeout']))->connectTimeout(5);
                if (! empty($this->config['api_key'])) {
                    $request = $request->withToken($this->config['api_key']);
                }
                $response = $request->post($url, $payload);
            } catch (ConnectionException) {
                if ($attempts < 2) {
                    continue;
                }
                throw new LlmException('unreachable', 'No se pudo conectar con el modelo del agente.');
            }

            if ($response->successful()) {
                return (array) $response->json();
            }

            if (in_array($response->status(), [429, 500, 502, 503], true) && $attempts < 2) {
                usleep(800_000);

                continue;
            }

            throw new LlmException('http_error', 'El modelo del agente respondió con un error.', $response->status());
        }
    }
}
