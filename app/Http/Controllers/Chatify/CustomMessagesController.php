<?php

namespace App\Http\Controllers\Chatify;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;

use App\Services\AIService;
use App\Services\MovementService;
use App\Services\ReminderService;

use Chatify\Facades\ChatifyMessenger as Chatify;
use Chatify\Http\Controllers\MessagesController as BaseMessagesController;

class CustomMessagesController extends BaseMessagesController
{
    public function send(Request $request)
    {
        $error = (object) [
            'status' => 0,
            'message' => null
        ];

        $attachment = null;
        $attachment_title = null;

        // =========================
        // HANDLE ATTACHMENTS
        // =========================
        if ($request->hasFile('file')) {

            $allowed_images = Chatify::getAllowedImages();
            $allowed_files = Chatify::getAllowedFiles();
            $allowed = array_merge($allowed_images, $allowed_files);

            $file = $request->file('file');

            if ($file->getSize() < Chatify::getMaxUploadSize()) {

                if (in_array(strtolower($file->extension()), $allowed)) {

                    $attachment_title = $file->getClientOriginalName();
                    $attachment = \Str::uuid() . "." . $file->extension();

                    $file->storeAs(
                        config('chatify.attachments.folder'),
                        $attachment,
                        config('chatify.storage_disk_name')
                    );

                } else {
                    $error->status = 1;
                    $error->message = "File extension not allowed!";
                }

            } else {
                $error->status = 1;
                $error->message = "File size too large!";
            }
        }

        // =========================
        // IF NO ERRORS → PROCESS MESSAGE
        // =========================
        if (!$error->status) {

            // 🔹 Guardar mensaje del usuario
            $message = Chatify::newMessage([
                'from_id' => Auth::id(),
                'to_id' => $request['id'],
                'body' => htmlentities(trim($request['message']), ENT_QUOTES, 'UTF-8'),
                'attachment' => $attachment ? json_encode((object) [
                    'new_name' => $attachment,
                    'old_name' => htmlentities(trim($attachment_title), ENT_QUOTES, 'UTF-8'),
                ]) : null,
            ]);

            $messageData = Chatify::parseMessage($message);

            if (Auth::id() != $request['id']) {
                Chatify::push(
                    "private-chatify." . $request['id'],
                    'messaging',
                    [
                        'from_id' => Auth::id(),
                        'to_id' => $request['id'],
                        'message' => Chatify::messageCard($messageData, true)
                    ]
                );
            }

            // =========================
            // IF TALKING TO BOT (ID = 2)
            // =========================
            if ($request->id == 2) {

                $aiService = new AIService();
                $movementService = new MovementService();
                $reminderService = new ReminderService();

                $data = $aiService->classify($request->message);

                if (!isset($data['success']) || !$data['success']) {

                     $botReply = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

                } else {

                    switch ($data['intent']) {

                        case 'income':
                        case 'expense':
                            $botReply = $movementService->create(Auth::id(), $data);
                            break;

                        case 'balance_query':
                            $balance = $movementService->getBalance(Auth::id());
                            $botReply = "💰 Tu saldo actual es {$balance}";
                            break;

                        case 'reminder_create':
                            $botReply = $reminderService->create(Auth::id(), $data);
                            break;

                        case 'reminder_list':
                            $botReply = $reminderService->list(Auth::id());
                            break;

                        case 'movement_summary':
                            $botReply = $movementService->getSummary(Auth::id(), $data);
                            break;

                        case 'movement_list':
                            $botReply = $movementService->list(Auth::id(), $data);
                            break;


                        default:
                            $botReply = json_encode(value: $data);
                    }
                }

                // 🔹 Guardar mensaje del BOT
                $botMessage = Chatify::newMessage([
                    'from_id' => 2,
                    'to_id' => Auth::id(),
                    'body' => htmlentities(trim($botReply), ENT_QUOTES, 'UTF-8'),
                    'attachment' => null,
                ]);

                $botMessageData = Chatify::parseMessage($botMessage);

                Chatify::push(
                    "private-chatify." . Auth::id(),
                    'messaging',
                    [
                        'from_id' => 2,
                        'to_id' => Auth::id(),
                        'message' => Chatify::messageCard($botMessageData, true)
                    ]
                );
            }
        }

        return Response::json([
            'status' => 200,
            'error' => $error,
            'message' => Chatify::messageCard(@$messageData),
            'tempID' => $request['temporaryMsgId'],
        ]);
    }
}
