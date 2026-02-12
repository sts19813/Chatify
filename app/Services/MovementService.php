<?php

namespace App\Services;

use App\Models\Movement;
use Carbon\Carbon;

class MovementService
{
    public function create(int $userId, array $data): string
    {
        Movement::create([
            'user_id' => $userId,
            'type' => $data['intent'],
            'description' => $data['data']['description'],
            'amount' => $data['data']['amount'],
        ]);

        $balance = $this->getBalance($userId);

        return "✅ Movimiento registrado\n\nSaldo actual: {$balance}";
    }

    public function getBalance(int $userId): float
    {
        return Movement::where('user_id', $userId)
            ->selectRaw("
                COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) -
                COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0)
                as balance
            ")
            ->value('balance');
    }
    /*
    |--------------------------------------------------------------------------
    | GET SUMMARY
    |--------------------------------------------------------------------------
    | Devuelve resumen financiero por periodo
    */
    public function getSummary(int $userId, array $aiData): string
    {
        $period = $aiData['data']['period'] ?? null;
        $category = $aiData['data']['category'] ?? null;

        [$start, $end, $label] = $this->resolvePeriod($period);

        $query = Movement::where('user_id', $userId)
            ->whereBetween('movement_date', [$start, $end]);

        if ($category) {
            $query->where('category', $category);
        }

        $movements = $query->get();

        $income = $movements->where('type', 'income')->sum('amount');
        $expense = $movements->where('type', 'expense')->sum('amount');
        $balance = $income - $expense;

        if ($movements->isEmpty()) {
            return "No tienes movimientos registrados para {$label}.";
        }

        return "📊 Resumen {$label}:

💵 Ingresos: $" . number_format($income, 2) . "
💸 Gastos: $" . number_format($expense, 2) . "
📈 Balance: $" . number_format($balance, 2);
    }


    /*
    |--------------------------------------------------------------------------
    | LIST MOVEMENTS
    |--------------------------------------------------------------------------
    | Devuelve últimos movimientos filtrados
    */
    public function list(int $userId, array $aiData): string
    {
        $period = $aiData['data']['period'] ?? null;
        $category = $aiData['data']['category'] ?? null;

        [$start, $end, $label] = $this->resolvePeriod($period);

        $query = Movement::where('user_id', $userId)
            ->orderByDesc('movement_date')
            ->orderByDesc('id');

        if ($period) {
            $query->whereBetween('movement_date', [$start, $end]);
        }

        if ($category) {
            $query->where('category', $category);
        }

        $movements = $query->limit(10)->get();

        if ($movements->isEmpty()) {
            return "No encontré movimientos registrados.";
        }

        $text = "🧾 Últimos movimientos {$label}:\n\n";

        foreach ($movements as $m) {
            $emoji = $m->type === 'income' ? '💵' : '💸';

            $text .= "{$emoji} {$m->movement_date?->format('d/m/Y')} - ";
            $text .= "{$m->description} - $";
            $text .= number_format($m->amount, 2);
            $text .= " ({$m->category})\n";
        }

        return $text;
    }


    /*
    |--------------------------------------------------------------------------
    | PERIOD RESOLVER
    |--------------------------------------------------------------------------
    */
    private function resolvePeriod(?string $period): array
    {
        $now = Carbon::now();

        switch ($period) {

            case 'this_week':
                return [
                    $now->copy()->startOfWeek(),
                    $now->copy()->endOfWeek(),
                    'esta semana'
                ];

            case 'last_week':
                return [
                    $now->copy()->subWeek()->startOfWeek(),
                    $now->copy()->subWeek()->endOfWeek(),
                    'la semana pasada'
                ];

            case 'last_month':
                return [
                    $now->copy()->subMonth()->startOfMonth(),
                    $now->copy()->subMonth()->endOfMonth(),
                    'el mes pasado'
                ];

            case 'this_month':
            default:
                return [
                    $now->copy()->startOfMonth(),
                    $now->copy()->endOfMonth(),
                    'este mes'
                ];
        }
    }
}
