<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatMessage;
use Illuminate\Support\Facades\Storage;

/** Plays a voice message from guest chat. The link is signed and short-lived (ChatService::audioUrl). */
class ChatAudioController extends Controller
{
    public function __invoke(int $message)
    {
        $m = ChatMessage::whereKey($message)->whereNotNull('audio')->firstOrFail();
        abort_unless(Storage::disk('local')->exists($m->audio), 404);

        return self::stream($m);
    }

    public static function stream(ChatMessage $m)
    {
        $type = Storage::disk('local')->mimeType($m->audio) ?: 'audio/webm';
        if (str_starts_with($type, 'video/')) {
            $type = 'audio/' . substr($type, 6);   // a webm / mp4 with only sound in it
        }

        return Storage::disk('local')->response($m->audio, 'voice-message-' . $m->id . '.' . pathinfo($m->audio, PATHINFO_EXTENSION), [
            'Content-Type' => $type, 'Cache-Control' => 'private, max-age=3600', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
