<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use App\Models\ChMessage;


class BotChatController extends Controller
{
    public function sendToBot(Request $request)
    {
        $botId = 2;

        // Guardar mensaje usuario
        ChMessage::create([
            'from_id' => Auth::id(),
            'to_id' => $botId,
            'body' => $request->message,
            'attachment' => null
        ]);

        // Llamar a Flask
        $response = Http::withoutVerifying()->post(
            env('FLASK_API_URL') . '/message',
            [
                'user_id' => Auth::id(),
                'message' => $request->message
            ]
        );

        $botReply = $response->json()['reply'] ?? 'Error con IA';

        // Guardar mensaje del bot
        ChMessage::create([
            'from_id' => $botId,
            'to_id' => Auth::id(),
            'body' => $botReply,
            'attachment' => null
        ]);

        return response()->json(['status' => 'ok']);
    }
}
