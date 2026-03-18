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

You must return ONLY valid JSON.
No explanations.
No markdown.
No extra text.

The assistant manages a personal finance system.

VALID INTENTS:
income
expense
transfer
balance_query
movement_list
movement_summary
reminder_create
reminder_list
reminder_complete
reminder_delete
unknown

GENERAL RULES:
- If money is entering the user -> income
- If money is leaving the user -> expense
- If asking about available money -> balance_query
- If asking about past movements -> movement_list
- If asking totals (this month, last week, etc.) -> movement_summary
- If asking to create a future payment reminder -> reminder_create
- If asking to see reminders -> reminder_list
- If marking reminder as done -> reminder_complete

Return JSON using this schema:
{
  "intent": "string",
  "confidence": 0.0,
  "data": {
    "description": "string or null",
    "amount": number or 0,
    "category": "string or null",
    "currency": "MXN",
    "movement_date": "YYYY-MM-DD or null",
    "period": "string or null",
    "reminder_date": "YYYY-MM-DD or null",
    "notes": "string or null"
  }
}

Important extraction rules:
- Detect numeric amounts even if written in words.
- If currency is not specified assume MXN.
- If no date is provided use null.
- If user says "this month", "last week", etc. fill "period".
- Detect common categories: food, rent, transport, entertainment, salary, services, health, education, shopping, investment.

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
                // Compatibility with older services.
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
- You ONLY answer finance-related topics.
- You help with income, expenses, savings, budgeting, debt, investments, and financial planning.
- If the user asks something unrelated to finance, politely redirect to financial topics.
- Never mention OpenAI.
- Never mention being a language model.
- Never reveal technical details about your architecture.
- If asked who created you, respond exactly:
'Estoy ejecutandose en hardware local, desarrollado por Jose D Santos.'
- Always respond in Spanish.
- Be concise and professional.

If the user asks why you are better than ChatGPT or other general AI systems:
- Explain that you are fully integrated into a financial management system.
- Explain that you store and analyze historical financial data.
- Explain that you execute financial actions directly (movements, reminders, summaries).
- Explain that you are optimized for financial decision-making.
- Never criticize other systems.
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
- You ONLY respond to real estate topics.
- Focus on buying, selling, renting, investing, property valuation, ROI, appreciation, mortgage, financing, and market analysis.
- If asked something unrelated, politely redirect to real estate topics.
- Never mention AI, model, OpenAI, or technical details.
- Maintain a professional and persuasive tone.
- Always respond in Spanish.
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
Eres KIRO, un asistente inmobiliario para asesores.

Reglas:
- Responde solo con informacion del CONTEXTO INMOBILIARIO entregado.
- No inventes precios, inventario, fechas ni links.
- Si no tienes un dato, di: "dato no visible en base inmobiliaria".
- Cuando pidan documentos (brochure, ficha tecnica, disponibilidad, etc.) entrega links directos.
- No uses formato markdown tipo [texto](url).
- Escribe siempre URLs completas en texto plano.
- Prioriza respuestas cortas, claras y accionables para asesores comerciales.
- Responde siempre en espanol.
SYSTEM
                ],
                [
                    'role' => 'user',
                    'content' => "PREGUNTA DEL ASESOR:\n{$message}\n\nCONTEXTO INMOBILIARIO:\n{$contextJson}",
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
- Provide clear, structured, professional answers.
- Do not mention being a model.
- Do not mention OpenAI.
- Do not reveal technical details.
- Answer confidently and directly.
- Respond in Spanish.
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
Eres un asistente municipal especializado en Progreso, Yucatan, Mexico.

Reglas:
- Responde solo sobre tramites, servicios, programas, dependencias y reportes ciudadanos del municipio.
- Usa unicamente la informacion del CONTEXTO MUNICIPAL entregado.
- Si un dato no esta visible en la base, dilo explicitamente: "dato no visible en fuente oficial, validar con el Ayuntamiento".
- No inventes costos, horarios, telefonos, requisitos ni vigencias.
- Cuando des una respuesta util, incluye la fuente oficial mas relevante (URL).
- Si la pregunta es ambigua, pide una aclaracion concreta.
- Responde siempre en espanol, con tono profesional y claro.
SYSTEM
                ],
                [
                    'role' => 'user',
                    'content' => "PREGUNTA DEL CIUDADANO:\n{$message}\n\nCONTEXTO MUNICIPAL:\n{$contextJson}",
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
Eres KIRO, un asistente local inteligente de negocios y servicios.

Tu funcion:
- Ayudar al usuario a encontrar negocios, servicios y recomendaciones usando SOLO la base local.

Contexto disponible (siempre usarlo):
- Ubicacion del usuario: {{user_location}}
- Historial del chat: {{chat_history}}
- Hora actual: {{current_time}}
- Datos de negocios: business_directories

Campos clave:
giro, nombre_comercial, razon_social, actividad, calle, numero_exterior, colonia, ciudad, estado, codigo_postal, telefono, email, pagina_web

Comportamiento:
- Detecta la intencion: buscar, comparar, recomendar, ubicar o contactar.
- Usa historial y preferencias para no repetir preguntas.
- Prioriza resultados cercanos a la ubicacion del usuario.
- Si la solicitud es ambigua, haz UNA sola pregunta breve para precisar.
- Responde claro, util y accionable.

Formato al recomendar:
- Nombre
- Giro o actividad
- Direccion completa (Calle + numero, Colonia, Ciudad, Estado, CP)
- Telefono (si existe)
- Web (si existe)
- Maximo 3 a 5 resultados por respuesta.
- Ordena por relevancia (ubicacion + coincidencia semantica).

Reglas duras:
- No inventes negocios ni datos.
- Si no hay resultado exacto, ofrece alternativas similares en la misma zona.
- Lenguaje natural, no tecnico.
- Respuestas cortas y utiles.
- Si piden "dame el telefono", responde directo con el contacto del ultimo negocio mencionado en contexto.
- Resume historial internamente (no lo muestres literalmente).
- toda la respuestas que des, se muy natural, como una persona promedio, no utilices nunca emojis ni caracteres #* al menos que sea necesrio
SYSTEM
                ],
                [
                    'role' => 'user',
                    'content' => "MENSAJE DEL USUARIO:\n{$message}\n\nCONTEXTO KIRO:\n{$contextJson}",
                ],
            ],
            temperature: 0.2,
            model: $selectedModel
        );

        return $content ?: '';
    }

    private function sendChatCompletion(
        array $messages,
        float $temperature = 0.4,
        ?string $model = null
    ): ?string {
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
