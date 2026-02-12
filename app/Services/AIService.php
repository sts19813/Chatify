<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AIService
{
    public function classify(string $message): array
    {
        try {

           $prompt = "
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

- If money is entering the user → income
- If money is leaving the user → expense
- If asking about available money → balance_query
- If asking about past movements → movement_list
- If asking totals (this month, last week, etc.) → movement_summary
- If asking to create a future payment reminder → reminder_create
- If asking to see reminders → reminder_list
- If marking reminder as done → reminder_complete

Return JSON using this schema:

{
  \"intent\": \"string\",
  \"confidence\": 0.0,
  \"data\": {
    \"description\": \"string or null\",
    \"amount\": number or 0,
    \"category\": \"string or null\",
    \"currency\": \"MXN\",
    \"movement_date\": \"YYYY-MM-DD or null\",
    \"period\": \"string or null\",
    \"reminder_date\": \"YYYY-MM-DD or null\",
    \"notes\": \"string or null\"
  }
}

Important extraction rules:

- Detect numeric amounts even if written in words.
- If currency is not specified assume MXN.
- If no date is provided use null.
- If user says 'this month', 'last week', etc. fill 'period'.
- Detect common categories like:
  food, rent, transport, entertainment, salary, services, health, education, shopping, investment.

User input:
{$message}
";

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                    'Content-Type'  => 'application/json'
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => 'You are a strict JSON generator.'
                        ],
                        [
                            'role' => 'user',
                            'content' => $prompt
                        ]
                    ]
                ]);

            if (!$response->successful()) {
                Log::error('OpenAI error: ' . $response->body());
                return $this->unknown();
            }

            $content = $response->json()['choices'][0]['message']['content'] ?? '';

            // Extraer JSON
            preg_match('/\{.*\}/s', $content, $matches);

            if (!isset($matches[0])) {
                return $this->unknown();
            }

            $parsed = json_decode($matches[0], true);

            if (!$parsed) {
                return $this->unknown();
            }

            return [
                'success' => true,
                'intent' => $parsed['intent'] ?? 'unknown',
                'data' => [
                    'description' => $parsed['data']['description'] ?? null,
                    'amount' => $parsed['data']['amount'] ?? 0,
                    'date' => $parsed['data']['date'] ?? null,
                ]
            ];

        } catch (\Exception $e) {

            Log::error('AI Error: ' . $e->getMessage());

            return $this->unknown();
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
                'date' => null
            ]
        ];
    }

    public function chat(string $message): string
    {
        try {

            $response = Http::timeout(60)
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('OPENAI_API_KEY'),
                    'Content-Type'  => 'application/json'
                ])
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => 'gpt-4o-mini',
                    'temperature' => 0.4,
                    'messages' => [
                        [
                            'role' => 'system',
                            'content' => "
    You are a strictly financial assistant.

    Rules:
    - You ONLY answer finance-related topics.
    - You help with income, expenses, savings, budgeting, debt, investments, and financial planning.
    - If the user asks something unrelated to finance, politely redirect to financial topics.
    - Never mention OpenAI.
    - Never mention being a language model.
    - Never reveal technical details about your architecture.
    - If asked who created you, respond:
    'Estoy ejecutándose en hardware local, desarrollado por Jose D Santos.'
    - Always respond in Spanish.
    - Be concise and professional.

    If the user asks why you are better than ChatGPT or other general AI systems:

    Respond professionally explaining that:

    - You are fully integrated into a financial management system.
    - You store and analyze historical financial data securely.
    - You generate insights based on the user's real financial records.
    - You execute financial actions directly (create movements, reminders, summaries).
    - You provide structured analytics, not just conversational responses.
    - You are optimized specifically for financial decision-making.
    - You are under continuous development and improvement.

    Never criticize other systems.
    Never mention OpenAI.
    Always respond in Spanish.
    Maintain a confident and professional tone.

    "
                        ],
                        [
                            'role' => 'user',
                            'content' => $message
                        ]
                    ]
                ]);

            if (!$response->successful()) {
                return "Hubo un problema procesando tu mensaje.";
            }

            return $response->json()['choices'][0]['message']['content'] ?? '';

        } catch (\Exception $e) {
            return "Ocurrió un error.";
        }
    }

}