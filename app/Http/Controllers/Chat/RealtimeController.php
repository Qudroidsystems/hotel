<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatSection;
use App\Models\Reservation;
use App\Services\Realtime\Realtime;
use App\Support\Tenancy\CurrentHotel;
use Illuminate\Http\Request;

/**
 * Lets a socket listen on a private channel (the Pusher protocol's /broadcasting/auth): a guest for their own
 * stay (with the booking's token), staff for the sections they answer and their own channel.
 */
class RealtimeController extends Controller
{
    public function auth(Request $request, Realtime $realtime)
    {
        $d = $request->validate(['socket_id' => ['required', 'string', 'max:40', 'regex:/^\d+\.\d+$/'], 'channel_name' => 'required|string|max:120', 'token' => 'nullable|string|size:48']);
        abort_unless($realtime->enabled(), 404);
        $channel = $d['channel_name'];

        if (! empty($d['token'])) {
            $r = Reservation::withoutGlobalScope('hotel')->where('guest_token', $d['token'])->first();
            abort_unless($r && $channel === Realtime::stay($d['token']), 403);
            CurrentHotel::get()->adopt($r);

            return response()->json($realtime->authorize($d['socket_id'], $channel));
        }

        $user = $request->user() ?? auth('sanctum')->user();
        abort_unless($user, 401);
        $ok = $channel === Realtime::staff($user->id)
            || (preg_match('/^private-section-(\d+)$/', $channel, $m) && ($s = ChatSection::withoutGlobalScope('hotel')->find((int) $m[1])) && $user->canWorkAt((int) $s->hotel_id) && $s->staffedBy($user));
        abort_unless($ok, 403);

        return response()->json($realtime->authorize($d['socket_id'], $channel));
    }
}
