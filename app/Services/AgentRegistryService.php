<?php

namespace App\Services;

use App\Models\AIAgent;
use Illuminate\Support\Facades\Schema;

class AgentRegistryService
{
    private static ?bool $hasAgentsTable = null;

    public function resolveByUserId(int $userId): ?array
    {
        $databaseAgent = $this->resolveDatabaseAgent($userId);

        if ($databaseAgent !== null) {
            return $databaseAgent;
        }

        return $this->resolveLegacyAgent($userId);
    }

    private function resolveDatabaseAgent(int $userId): ?array
    {
        if (!$this->hasAgentsTable()) {
            return null;
        }

        $agent = AIAgent::query()
            ->where('user_id', $userId)
            ->where('enabled', true)
            ->first();

        if (!$agent) {
            return null;
        }

        return [
            'user_id' => $agent->user_id,
            'agent_key' => $agent->agent_key,
            'agent_type' => $agent->agent_type,
            'enabled' => $agent->enabled,
            'dataset_path' => $agent->dataset_path,
            'settings' => $agent->settings ?? [],
            'source' => 'database',
        ];
    }

    private function resolveLegacyAgent(int $userId): ?array
    {
        $legacy = data_get(config('agents.legacy_user_map'), (string) $userId);

        if (!$legacy || !($legacy['enabled'] ?? false)) {
            return null;
        }

        return [
            'user_id' => $userId,
            'agent_key' => $legacy['agent_key'] ?? "legacy_agent_{$userId}",
            'agent_type' => $legacy['agent_type'] ?? 'intelligent',
            'enabled' => (bool) ($legacy['enabled'] ?? false),
            'dataset_path' => $legacy['dataset_path'] ?? null,
            'settings' => $legacy['settings'] ?? [],
            'source' => 'legacy',
        ];
    }

    private function hasAgentsTable(): bool
    {
        if (self::$hasAgentsTable !== null) {
            return self::$hasAgentsTable;
        }

        try {
            self::$hasAgentsTable = Schema::hasTable('ai_agents');
        } catch (\Throwable) {
            self::$hasAgentsTable = false;
        }

        return self::$hasAgentsTable;
    }
}

