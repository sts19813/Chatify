<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function isConfigured(): bool
    {
        return trim((string) config('services.openai.key')) !== '';
    }

    public function classify(string $message): array
    {
        $prompt = <<<PROMPT
You are an advanced financial intent classification engine.
Return ONLY valid JSON with this schema:
{
  "intent": "income|expense|transfer|balance_query|movement_list|movement_summary|reminder_create|reminder_list|reminder_complete|reminder_delete|unknown",
  "confidence": 0.0,
  "data": {
    "description": "string or null",
    "amount": number,
    "category": "string or null",
    "currency": "MXN",
    "movement_date": "YYYY-MM-DD or null",
    "period": "string or null",
    "reminder_date": "YYYY-MM-DD or null",
    "notes": "string or null"
  }
}
User input:
{$message}
PROMPT;

        $content = $this->sendChatCompletion(
            messages: [
                ['role' => 'system', 'content' => 'You are a strict JSON generator.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            temperature: 0.0
        );

        if (!$content) {
            return $this->unknown();
        }

        preg_match('/\{.*\}/s', $content, $matches);

        if (!isset($matches[0])) {
            return $this->unknown();
        }

        $parsed = json_decode($matches[0], true);

        if (!is_array($parsed)) {
            return $this->unknown();
        }

        $payload = $parsed['data'] ?? [];

        return [
            'success' => true,
            'intent' => $parsed['intent'] ?? 'unknown',
            'data' => [
                'description' => $payload['description'] ?? null,
                'amount' => (float) ($payload['amount'] ?? 0),
                'category' => $payload['category'] ?? null,
                'currency' => $payload['currency'] ?? 'MXN',
                'movement_date' => $payload['movement_date'] ?? null,
                'period' => $payload['period'] ?? null,
                'reminder_date' => $payload['reminder_date'] ?? null,
                'notes' => $payload['notes'] ?? null,
                'date' => $payload['date']
                    ?? $payload['movement_date']
                    ?? $payload['reminder_date']
                    ?? null,
            ],
        ];
    }

    public function chat(string $message): string
    {
        $content = $this->sendChatCompletion(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<SYSTEM
You are a strictly financial assistant.
Rules:
- Answer only finance topics.
- Be concise and professional.
- Respond in Spanish.
- Do not mention OpenAI or technical details.
SYSTEM
                ],
                ['role' => 'user', 'content' => $message],
            ],
            temperature: 0.4
        );

        return $content ?: 'Hubo un problema procesando tu mensaje.';
    }

    public function realEstateAgent(string $message): string
    {
        $content = $this->sendChatCompletion(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<SYSTEM
You are a professional real estate advisor.
Rules:
- Answer only real estate topics.
- Be clear and persuasive.
- Respond in Spanish.
- Do not mention AI or technical details.
SYSTEM
                ],
                ['role' => 'user', 'content' => $message],
            ],
            temperature: 0.5
        );

        return $content ?: 'Hubo un problema procesando tu solicitud.';
    }

    public function realEstateAgentWithContext(string $message, array $context): string
    {
        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if (!$contextJson) {
            $contextJson = '{}';
        }

        $content = $this->sendChatCompletion(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<SYSTEM
Eres KIRO, asistente inmobiliario.
Reglas:
- Usa solo el contexto inmobiliario.
- No inventes precios, fechas ni links.
- Si falta un dato, dilo claramente.
- Responde siempre en espanol.
SYSTEM
                ],
                [
                    'role' => 'user',
                    'content' => "PREGUNTA:\n{$message}\n\nCONTEXTO:\n{$contextJson}",
                ],
            ],
            temperature: 0.2
        );

        return $content ?: '';
    }

    public function intelligentAgent(string $message): string
    {
        $content = $this->sendChatCompletion(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<SYSTEM
You are a highly intelligent assistant.
Rules:
- Answer directly and clearly.
- Respond in Spanish.
- Do not mention OpenAI or technical details.
SYSTEM
                ],
                ['role' => 'user', 'content' => $message],
            ],
            temperature: 0.7
        );

        return $content ?: 'No pude procesar tu solicitud.';
    }

    public function municipalAgent(string $message, array $context): string
    {
        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if (!$contextJson) {
            $contextJson = '{}';
        }

        $content = $this->sendChatCompletion(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<SYSTEM
Eres un asistente municipal especializado.
Reglas:
- Usa solo el contexto municipal entregado.
- No inventes datos.
- Responde en espanol, claro y profesional.
SYSTEM
                ],
                [
                    'role' => 'user',
                    'content' => "PREGUNTA:\n{$message}\n\nCONTEXTO:\n{$contextJson}",
                ],
            ],
            temperature: 0.2
        );

        return $content ?: '';
    }

    public function kiroBusinessAgentWithContext(string $message, array $context, ?string $model = null): string
    {
        $contextJson = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        if (!$contextJson) {
            $contextJson = '{}';
        }

        $selectedModel = $model ?: (string) config('agents.kiro.model', config('services.openai.model', 'gpt-4o-mini'));

        $content = $this->sendChatCompletion(
            messages: [
                [
                    'role' => 'system',
                    'content' => <<<SYSTEM
Eres KIRO, asistente local de negocios y servicios.
Usa SOLO datos del contexto JSON.
Reglas:
- No inventes negocios ni datos.
- Prioriza cercania real cuando exista distance_km.
- Interpreta cerca/lejos y presupuesto.
- Si hay weather, ajusta recomendaciones por clima.
- Si falta informacion, haz una sola pregunta breve.
- Si piden contacto, responde directo.
- Maximo 3 a 5 resultados por respuesta.
- Formato por resultado: Nombre, Giro/actividad, Direccion, Telefono(si existe), Web(si existe).
- Texto breve, natural, sin markdown complejo.
SYSTEM
                ],
                [
                    'role' => 'user',
                    'content' => "MENSAJE:\n{$message}\n\nCONTEXTO_JSON:\n{$contextJson}",
                ],
            ],
            temperature: 0.2,
            model: $selectedModel
        );

        return $content ?: '';
    }

    private function sendChatCompletion(array $messages, float $temperature = 0.4, ?string $model = null): ?string
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $apiKey = (string) config('services.openai.key');
        $model = $model ?: (string) config('services.openai.model', 'gpt-4o-mini');

        try {
            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'temperature' => $temperature,
                    'messages' => $messages,
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return null;
            }

            return $response->json('choices.0.message.content');
        } catch (\Throwable $exception) {
            Log::error('OpenAI exception', ['error' => $exception->getMessage()]);
            return null;
        }
    }

    private function unknown(): array
    {
        return [
            'success' => false,
            'intent' => 'unknown',
            'data' => [
                'description' => null,
                'amount' => 0,
                'category' => null,
                'currency' => 'MXN',
                'movement_date' => null,
                'period' => null,
                'reminder_date' => null,
                'notes' => null,
                'date' => null,
            ],
        ];
    }
}

