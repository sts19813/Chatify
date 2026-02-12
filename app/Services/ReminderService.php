<?php

namespace App\Services;

use App\Models\Reminder;

class ReminderService
{
    public function create(int $userId, array $data): string
    {
        $date = $data['data']['date'] ?? now()->toDateString();

        Reminder::create([
            'user_id' => $userId,
            'description' => $data['data']['description'],
            'amount' => $data['data']['amount'] ?? null,
            'remind_at' => $date,
        ]);

        return "📅 Recordatorio creado para {$date}";
    }

    public function list(int $userId): string
    {
        $reminders = Reminder::where('user_id', $userId)
            ->where('completed', false)
            ->orderBy('remind_at')
            ->get();

        if ($reminders->isEmpty()) {
            return "No tienes recordatorios pendientes.";
        }

        $message = "📌 Tus recordatorios:\n\n";

        foreach ($reminders as $r) {
            $message .= "- {$r->remind_at}: {$r->description}\n";
        }

        return $message;
    }
}
