<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class AIService
{
    public function classify(string $message): array
    {
        try {

            $response = Http::timeout(120) // 2 minutos
                ->connectTimeout(30)
                ->post(
                    env('FLASK_API_URL') . '/classify',
                    ['message' => $message]
                );

            return $response->json() ?? [
                'success' => false,
                'intent' => 'unknown',
                'data' => []
            ];

        } catch (\Exception $e) {

            \Log::error('AI Timeout Error: ' . $e->getMessage());

            return [
                'success' => false,
                'intent' => 'unknown',
                'data' => []
            ];
        }
    }
}
