<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AgentRouterService
{
    public function __construct(
        private readonly AgentRegistryService $registry,
        private readonly AIService $aiService,
        private readonly MovementService $movementService,
        private readonly ReminderService $reminderService,
        private readonly MunicipalKnowledgeService $municipalKnowledgeService,
    ) {
    }

    public function resolveReply(int $receiverId, int $senderId, string $message): ?string
    {
        $agent = $this->registry->resolveByUserId($receiverId);

        if (!$agent) {
            return null;
        }

        try {
            return match ($agent['agent_type']) {
                'finance' => $this->replyFromFinanceAgent($senderId, $message),
                'real_estate' => $this->aiService->realEstateAgent($message),
                'municipal' => $this->replyFromMunicipalAgent($message, $agent),
                'intelligent' => $this->aiService->intelligentAgent($message),
                default => $this->aiService->intelligentAgent($message),
            };
        } catch (\Throwable $exception) {
            Log::error('Agent router error', [
                'receiver_id' => $receiverId,
                'sender_id' => $senderId,
                'agent_key' => $agent['agent_key'] ?? null,
                'agent_type' => $agent['agent_type'] ?? null,
                'error' => $exception->getMessage(),
            ]);

            return 'Ocurrió un error al procesar tu solicitud. Intenta nuevamente.';
        }
    }

    private function replyFromFinanceAgent(int $userId, string $message): string
    {
        $data = $this->aiService->classify($message);

        if (!isset($data['success']) || !$data['success']) {
            return $this->aiService->chat($message);
        }

        if (($data['intent'] ?? 'unknown') === 'unknown') {
            return $this->aiService->chat($message);
        }

        return match ($data['intent']) {
            'income', 'expense' => $this->movementService->create($userId, $data),
            'balance_query' => "💰 Tu saldo actual es {$this->movementService->getBalance($userId)}",
            'reminder_create' => $this->reminderService->create($userId, $data),
            'reminder_list' => $this->reminderService->list($userId),
            'movement_summary' => $this->movementService->getSummary($userId, $data),
            'movement_list' => $this->movementService->list($userId, $data),
            default => $this->aiService->chat($message),
        };
    }

    private function replyFromMunicipalAgent(string $message, array $agent): string
    {
        $context = $this->municipalKnowledgeService->buildContext(
            message: $message,
            datasetPath: $agent['dataset_path'] ?? null,
            settings: $agent['settings'] ?? [],
        );

        if (!$this->aiService->isConfigured()) {
            return $this->municipalKnowledgeService->buildFallbackAnswer($message, $context);
        }

        $response = $this->aiService->municipalAgent($message, $context);

        if (trim($response) === '') {
            return $this->municipalKnowledgeService->buildFallbackAnswer($message, $context);
        }

        return $response;
    }
}

