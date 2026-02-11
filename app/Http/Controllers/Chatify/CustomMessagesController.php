<?php

namespace App\Http\Controllers\Chatify;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Auth;
use  Chatify\Facades;

use Chatify\Facades\ChatifyMessenger as Chatify;

use App\Models\ChMessage;

use Chatify\Http\Controllers\MessagesController as BaseMessagesController;

class CustomMessagesController extends BaseMessagesController
{
    public function send(Request $request)
    {
        // Ejecuta el método original primero
        $response = parent::send($request);

        // Si está hablando con el bot (ID 2)
        if ($request->id == 2) {

            $responseFlask = Http::withoutVerifying()->post(
                env('FLASK_API_URL') . '/message',
                [
                    'user_id' => auth()->id(),
                    'message' => $request->message
                ]
            );

            $botReply = $responseFlask->json()['reply'] ?? 'Error con IA';

            // Crear mensaje usando Chatify
            $botMessage = Chatify::newMessage([
                'from_id' => 2,
                'to_id' => Auth::user()->id,
                'body' => htmlentities(trim($botReply), ENT_QUOTES, 'UTF-8'),
                'attachment' => null,
            ]);

            $botMessageData = Chatify::parseMessage($botMessage);

            // Enviar en tiempo real
            Chatify::push(
                "private-chatify." . Auth::user()->id,
                'messaging',
                [
                    'from_id' => 2,
                    'to_id' => Auth::user()->id,
                    'message' => Chatify::messageCard($botMessageData, true)
                ]
            );
        }

        return $response;
    }
}
