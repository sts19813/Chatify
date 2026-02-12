<?php

namespace App\Services;

use App\Models\Movement;

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
}
