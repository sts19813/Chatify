<?php

namespace App\Http\Controllers;

use App\Models\AIAgent;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ChatLandingController extends Controller
{
    public function guest(): RedirectResponse
    {
        return redirect()->route('chat.landing');
    }

    public function index(): View
    {
        return view('chat.select-agent', [
            'agents' => $this->availableAgents(),
        ]);
    }

    public function start(int $agentId): RedirectResponse
    {
        $agent = $this->availableAgents()->firstWhere('id', $agentId);

        if ($agent === null) {
            return redirect()
                ->route('chat.landing')
                ->with('error', 'El bot o IA seleccionado no esta disponible.');
        }

        $prefix = trim((string) config('chatify.routes.prefix', 'chatify'), '/');

        return redirect()->to("/{$prefix}/{$agentId}");
    }

    private function availableAgents(): Collection
    {
        if (!Schema::hasTable('users')) {
            return collect();
        }

        $databaseAgents = collect();

        if (Schema::hasTable('ai_agents')) {
            $databaseAgents = AIAgent::query()
                ->where('enabled', true)
                ->with('user:id,name')
                ->get()
                ->filter(fn (AIAgent $agent): bool => $agent->user !== null)
                ->map(function (AIAgent $agent): array {
                    return $this->buildAgentPayload(
                        userId: $agent->user_id,
                        userName: (string) $agent->user->name,
                        type: (string) $agent->agent_type,
                        key: (string) $agent->agent_key,
                    );
                });
        }

        $legacyConfig = collect(config('agents.legacy_user_map', []))
            ->filter(fn (array $agent): bool => (bool) ($agent['enabled'] ?? false));

        $legacyUserIds = $legacyConfig
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->all();

        $legacyUsers = empty($legacyUserIds)
            ? collect()
            : User::query()
                ->whereIn('id', $legacyUserIds)
                ->get(['id', 'name'])
                ->keyBy('id');

        $legacyAgents = $legacyConfig
            ->map(function (array $agent, string $id) use ($legacyUsers): ?array {
                $userId = (int) $id;
                $user = $legacyUsers->get($userId);

                if ($user === null) {
                    return null;
                }

                $type = (string) ($agent['agent_type'] ?? 'intelligent');

                return $this->buildAgentPayload(
                    userId: $userId,
                    userName: (string) $user->name,
                    type: $type,
                    key: (string) ($agent['agent_key'] ?? "legacy_agent_{$userId}"),
                );
            })
            ->filter();

        return $databaseAgents
            ->concat($legacyAgents)
            ->unique('id')
            ->sortBy('name')
            ->values();
    }

    private function buildAgentPayload(int $userId, string $userName, string $type, string $key): array
    {
        return [
            'id' => $userId,
            'name' => $userName,
            'key' => $key,
            'type' => $type,
            'type_label' => $this->agentTypeLabel($type),
            'description' => $this->agentDescription($type),
        ];
    }

    private function agentTypeLabel(string $type): string
    {
        return match ($type) {
            'finance' => 'Finanzas',
            'real_estate' => 'Bienes raices',
            'municipal' => 'Municipal',
            'kiro' => 'KIRO Local',
            'intelligent' => 'Asistente general',
            default => Str::headline(str_replace('_', ' ', $type)),
        };
    }

    private function agentDescription(string $type): string
    {
        return match ($type) {
            'finance' => 'Control de gastos, ingresos y resumen financiero.',
            'real_estate' => 'Asesoria para compra, renta e inversion inmobiliaria.',
            'municipal' => 'Informacion municipal y guia de tramites.',
            'kiro' => 'Recomendaciones locales de negocios y servicios con contexto de ubicacion.',
            'intelligent' => 'Asistente de uso general para resolver dudas.',
            default => 'Asistente especializado.',
        };
    }
}
