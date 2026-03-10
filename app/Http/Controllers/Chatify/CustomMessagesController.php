<?php

namespace App\Http\Controllers\Chatify;

use App\Services\AgentRouterService;
use Chatify\Facades\ChatifyMessenger as Chatify;
use Chatify\Http\Controllers\MessagesController as BaseMessagesController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Str;

class CustomMessagesController extends BaseMessagesController
{
    public function __construct(
        private readonly AgentRouterService $agentRouter
    ) {
    }

    public function send(Request $request)
    {
        $error = (object) [
            'status' => 0,
            'message' => null,
        ];

        $messageData = null;

        [$attachment, $attachmentTitle, $error] = $this->processAttachment($request, $error);

        if (!$error->status) {
            $receiverId = (int) $request['id'];
            $senderId = (int) Auth::id();

            $message = Chatify::newMessage([
                'from_id' => $senderId,
                'to_id' => $receiverId,
                'body' => htmlentities(trim((string) $request['message']), ENT_QUOTES, 'UTF-8'),
                'attachment' => $attachment ? json_encode((object) [
                    'new_name' => $attachment,
                    'old_name' => htmlentities(trim((string) $attachmentTitle), ENT_QUOTES, 'UTF-8'),
                ]) : null,
            ]);

            $messageData = Chatify::parseMessage($message);

            if ($senderId !== $receiverId) {
                Chatify::push(
                    "private-chatify.{$receiverId}",
                    'messaging',
                    [
                        'from_id' => $senderId,
                        'to_id' => $receiverId,
                        'message' => Chatify::messageCard($messageData, true),
                    ]
                );
            }

            $botReply = $this->agentRouter->resolveReply(
                receiverId: $receiverId,
                senderId: $senderId,
                message: (string) $request['message'],
            );

            if ($botReply !== null) {
                $this->storeAndPushAgentReply($receiverId, $senderId, $botReply);
            }
        }

        return Response::json([
            'status' => 200,
            'error' => $error,
            'message' => $messageData ? Chatify::messageCard($messageData) : '',
            'tempID' => $request['temporaryMsgId'],
        ]);
    }

    private function processAttachment(Request $request, object $error): array
    {
        $attachment = null;
        $attachmentTitle = null;

        if (!$request->hasFile('file')) {
            return [$attachment, $attachmentTitle, $error];
        }

        $allowedImages = Chatify::getAllowedImages();
        $allowedFiles = Chatify::getAllowedFiles();
        $allowed = array_merge($allowedImages, $allowedFiles);

        $file = $request->file('file');

        if ($file->getSize() >= Chatify::getMaxUploadSize()) {
            $error->status = 1;
            $error->message = 'File size too large!';
            return [$attachment, $attachmentTitle, $error];
        }

        if (!in_array(strtolower($file->extension()), $allowed, true)) {
            $error->status = 1;
            $error->message = 'File extension not allowed!';
            return [$attachment, $attachmentTitle, $error];
        }

        $attachmentTitle = $file->getClientOriginalName();
        $attachment = Str::uuid() . '.' . $file->extension();

        $file->storeAs(
            config('chatify.attachments.folder'),
            $attachment,
            config('chatify.storage_disk_name')
        );

        return [$attachment, $attachmentTitle, $error];
    }

    private function storeAndPushAgentReply(int $agentUserId, int $userId, string $reply): void
    {
        $replyText = trim($reply);

        if ($replyText === '') {
            $replyText = 'No pude generar una respuesta en este momento.';
        }

        $botMessage = Chatify::newMessage([
            'from_id' => $agentUserId,
            'to_id' => $userId,
            'body' => htmlentities($replyText, ENT_QUOTES, 'UTF-8'),
            'attachment' => null,
        ]);

        $botMessageData = Chatify::parseMessage($botMessage);

        Chatify::push(
            "private-chatify.{$userId}",
            'messaging',
            [
                'from_id' => $agentUserId,
                'to_id' => $userId,
                'message' => Chatify::messageCard($botMessageData, true),
            ]
        );
    }
}

