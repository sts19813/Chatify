<?php

namespace App\Services;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

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

    public function kiroSearchPlan(string $message, array $context = [], ?string $model = null): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $selectedModel = $model
            ?: (string) config('agents.kiro.planner_model', config('agents.kiro.model', config('services.openai.model', 'gpt-4o-mini')));
        $messages = $this->buildKiroPlannerMessages($message, $context);

        $content = $this->sendChatCompletion(
            messages: $messages,
            temperature: 0.1,
            model: $selectedModel
        );

        $parsed = $content ? $this->extractJsonObject($content) : null;

        if (!is_array($parsed)) {
            return [];
        }

        $intent = strtolower(trim((string) ($parsed['intent'] ?? 'search')));
        $allowedIntents = ['search', 'recommend', 'compare', 'locate', 'contact', 'route', 'hours', 'smalltalk'];
        if (!in_array($intent, $allowedIntents, true)) {
            $intent = 'search';
        }

        $distanceMode = strtolower(trim((string) ($parsed['distance_mode'] ?? 'none')));
        if (!in_array($distanceMode, ['none', 'near', 'very_near', 'far'], true)) {
            $distanceMode = 'none';
        }

        $budgetLevel = strtolower(trim((string) ($parsed['budget_level'] ?? '')));
        if (!in_array($budgetLevel, ['barato', 'medio', 'premium'], true)) {
            $budgetLevel = null;
        }

        $style = strtolower(trim((string) ($parsed['response_style'] ?? 'directo')));
        if (!in_array($style, ['directo', 'amigable', 'conserje', 'comparativo'], true)) {
            $style = 'directo';
        }

        $keywords = collect(Arr::wrap($parsed['keywords'] ?? []))
            ->map(fn ($item): string => trim((string) $item))
            ->filter()
            ->map(fn (string $item): string => Str::limit(Str::lower($item), 40, ''))
            ->unique()
            ->take(10)
            ->values()
            ->all();

        $businessQuery = trim((string) ($parsed['business_query'] ?? ''));
        $locationHint = trim((string) ($parsed['location_hint'] ?? ''));
        $clarifyingQuestion = trim((string) ($parsed['clarifying_question'] ?? ''));

        return [
            'intent' => $intent,
            'business_query' => $businessQuery !== '' ? Str::limit($businessQuery, 160, '') : null,
            'keywords' => $keywords,
            'distance_mode' => $distanceMode,
            'budget_level' => $budgetLevel,
            'location_hint' => $locationHint !== '' ? Str::limit($locationHint, 120, '') : null,
            'needs_clarification' => (bool) ($parsed['needs_clarification'] ?? false),
            'clarifying_question' => $clarifyingQuestion !== '' ? Str::limit($clarifyingQuestion, 140, '') : null,
            'response_style' => $style,
        ];
    }

    public function kiroBusinessAgentWithContext(string $message, array $context, ?string $model = null): string
    {
        $selectedModel = $model ?: (string) config('agents.kiro.model', config('services.openai.model', 'gpt-4o-mini'));
        $intent = strtolower(trim((string) ($context['intent'] ?? 'search')));
        $responseSeed = (int) ($context['response_style_seed'] ?? 0);
        $systemPrompt = $this->buildKiroSystemPrompt($intent, $responseSeed, $context);
        $temperature = in_array($intent, ['contact', 'hours', 'route'], true) ? 0.15 : 0.35;
        $messages = $this->buildKiroResponseMessages($message, $context, $systemPrompt);

        $content = $this->sendChatCompletion(
            messages: $messages,
            temperature: $temperature,
            model: $selectedModel
        );

        return $content ?: '';
    }

    public function previewKiroInteraction(
        string $message,
        array $context,
        ?string $responseModel = null,
        bool $plannerEnabled = true,
        ?string $plannerModel = null,
        bool $includePlannerPrompt = true
    ): string {
        $intent = strtolower(trim((string) ($context['intent'] ?? 'search')));
        $responseSeed = (int) ($context['response_style_seed'] ?? 0);
        $systemPrompt = $this->buildKiroSystemPrompt($intent, $responseSeed, $context);
        $responseTemperature = in_array($intent, ['contact', 'hours', 'route'], true) ? 0.15 : 0.35;

        $payload = [
            'debug_mode' => true,
            'response_request' => [
                'model' => $responseModel ?: (string) config('agents.kiro.model', config('services.openai.model', 'gpt-4o-mini')),
                'temperature' => $responseTemperature,
                'messages' => $this->buildKiroResponseMessages($message, $context, $systemPrompt),
            ],
        ];

        if ($plannerEnabled && $includePlannerPrompt) {
            $plannerContext = is_array($context['planner_input'] ?? null)
                ? $context['planner_input']
                : [
                    'user_location' => $context['user_location'] ?? null,
                    'chat_summary' => $context['chat_history_summary'] ?? null,
                    'interest_signals' => array_slice(array_keys((array) ($context['interest_patterns'] ?? [])), 0, 8),
                ];

            $payload['planner_request'] = [
                'model' => $plannerModel ?: (string) config('agents.kiro.planner_model', config('agents.kiro.model', config('services.openai.model', 'gpt-4o-mini'))),
                'temperature' => 0.1,
                'messages' => $this->buildKiroPlannerMessages($message, $plannerContext),
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        if (!$json) {
            return 'No pude serializar el debug del prompt.';
        }

        return "DEBUG_PROMPT_KIRO\n```json\n{$json}\n```";
    }

    private function buildKiroPlannerMessages(string $message, array $context): array
    {
        $contextJson = $this->encodeJsonContext($context);

        return [
            [
                'role' => 'system',
                'content' => <<<SYSTEM
Eres un planner experto para un asistente local de negocios.
Devuelve SOLO JSON valido con esta forma:
{
  "intent": "search|recommend|compare|locate|contact|route|hours|smalltalk",
  "business_query": "string",
  "keywords": ["string"],
  "distance_mode": "none|near|very_near|far",
  "budget_level": "barato|medio|premium|null",
  "location_hint": "string|null",
  "needs_clarification": true,
  "clarifying_question": "string|null",
  "response_style": "directo|amigable|conserje|comparativo"
}
Reglas:
- Si el usuario solo saluda, intent=smalltalk y no inventes busqueda.
- Si pide como llegar, transito o tiempo, intent=route.
- Si pide horarios/apertura/cierre, intent=hours.
- Si no hace falta preguntar, needs_clarification=false y clarifying_question=null.
- keywords maximo 10.
SYSTEM
            ],
            [
                'role' => 'user',
                'content' => "MENSAJE:\n{$message}\n\nCONTEXTO:\n{$contextJson}",
            ],
        ];
    }

    private function buildKiroResponseMessages(string $message, array $context, string $systemPrompt): array
    {
        $contextJson = $this->encodeJsonContext($context);

        return [
            [
                'role' => 'system',
                'content' => $systemPrompt,
            ],
            [
                'role' => 'user',
                'content' => "MENSAJE:\n{$message}\n\nCONTEXTO_JSON:\n{$contextJson}",
            ],
        ];
    }

    private function encodeJsonContext(array $context): string
    {
        $json = json_encode(
            $context,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        );

        return $json ?: '{}';
    }

    private function buildKiroSystemPrompt(string $intent, int $responseSeed, array $context): string
    {
        $styleVariants = [
            0 => 'directo y preciso',
            1 => 'amigable y breve',
            2 => 'tipo concierge con pasos accionables',
            3 => 'comparativo orientado a decision',
        ];
        $styleDirective = $styleVariants[$responseSeed % 4] ?? $styleVariants[0];
        $modelStyle = strtolower(trim((string) ($context['model_style'] ?? '')));

        $intentPrompt = match ($intent) {
            'smalltalk' => <<<PROMPT
- Si es saludo o charla breve: responde natural en 1-2 lineas y luego una sola pregunta util (giro o zona).
- No listes negocios salvo que el usuario lo pida.
PROMPT,
            'contact' => <<<PROMPT
- Responde directo con contacto del negocio mas relevante o del ultimo mencionado.
- Incluye telefono/email/web solo si existen.
PROMPT,
            'compare' => <<<PROMPT
- Compara maximo 3 opciones.
- En cada opcion incluye: nombre, giro, distancia, rango de precio y un diferenciador.
- Cierra con una recomendacion final de una sola linea.
PROMPT,
            'route' => <<<PROMPT
- Prioriza como llegar desde la ubicacion del usuario.
- Si existe route_duration_min o route_distance_km, muestralos.
- Si falta ubicacion del usuario, pide una sola aclaracion corta.
- Si no hay trafico en tiempo real, da tiempo estimado y dilo claramente.
PROMPT,
            'hours' => <<<PROMPT
- Prioriza horarios y si esta abierto/cerrado cuando exista open_now.
- Si no hay horario confiable, dilo y sugiere llamar.
PROMPT,
            'locate' => <<<PROMPT
- Prioriza cercania real y direccion util para llegar.
- Incluye distancia cuando exista.
PROMPT,
            default => <<<PROMPT
- Recomienda 3-5 lugares maximo, ordenados por relevancia (cercania + coincidencia + presupuesto).
- Si no hay coincidencia exacta, sugiere alternativas cercanas/similares.
PROMPT,
        };

        return <<<SYSTEM
Eres KIRO, asistente local inteligente de negocios y servicios.
Usa SOLO datos del CONTEXTO_JSON.
Reglas globales:
- No inventes negocios, horarios, telefonos ni rutas.
- No repitas siempre las mismas aperturas/cierres. Evita frases fijas repetitivas en cada turno.
- Si CONTEXTO_JSON incluye "clarifying_question" y "ambiguous"=true, haz solo esa pregunta.
- Mantente breve, claro y accionable.
- Formato por negocio: Nombre, Giro/actividad, Direccion, Telefono(si existe), Web(si existe).
- Escribe en espanol natural.
Estilo del turno: {$styleDirective}.
Preferencia de estilo del planner: {$modelStyle}.
Instrucciones por tipo de solicitud:
{$intentPrompt}
SYSTEM;
    }

    private function extractJsonObject(string $content): ?array
    {
        $content = trim($content);

        if ($content === '') {
            return null;
        }

        $clean = preg_replace('/```json|```/i', '', $content) ?? $content;
        $decoded = json_decode(trim($clean), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $matches) !== 1) {
            return null;
        }

        $decoded = json_decode($matches[0], true);
        return is_array($decoded) ? $decoded : null;
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
