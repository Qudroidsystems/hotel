<?php

namespace App\Http\Controllers\Messaging;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Models\Messaging\GuestMessage;
use App\Models\Reservation;
use App\Services\Messaging\GuestMessenger;
use Illuminate\Http\Request;

/** Texts to and from guests: the log, guest replies, and sending one by hand. */
class MessageController extends Controller
{
    public function __construct(private GuestMessenger $messenger)
    {
        $this->middleware('permission:Message guests');
    }

    public function index(Request $request)
    {
        $show = in_array($request->query('show'), ['all', 'replies', 'failed'], true) ? $request->query('show') : 'all';
        $q = trim((string) $request->query('q'));
        $messages = GuestMessage::with(['guest:id,title,first_name,last_name,phone', 'reservation:id,reference', 'sender:id,name'])
            ->when($show === 'replies', fn ($w) => $w->where('direction', 'in'))
            ->when($show === 'failed', fn ($w) => $w->whereIn('status', ['failed', 'skipped']))
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x->where('body', 'like', "%{$q}%")->orWhere('to', 'like', "%{$q}%")->orWhere('from', 'like', "%{$q}%")
                ->orWhereHas('guest', fn ($g) => $g->search($q))->orWhereHas('reservation', fn ($r) => $r->where('reference', $q))))
            ->latest('id')->paginate(40)->withQueryString();

        $unread = GuestMessage::where('direction', 'in')->whereNull('read_at')->count();
        $today = GuestMessage::where('direction', 'out')->where('created_at', '>=', today())->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $pagetitle = 'Guest Messages';
        $ready = ['enabled' => $this->messenger->enabled(), 'configured' => $this->messenger->configured(), 'channel' => $this->messenger->channel(), 'provider' => $this->messenger->providerLabel(), 'missing' => $this->messenger->missingSettings()];

        return view('messages.index', compact('pagetitle', 'messages', 'show', 'q', 'unread', 'today', 'ready'));
    }

    /** Write to the guest on a booking. */
    public function sendForReservation(Request $request, Reservation $reservation)
    {
        $data = $request->validate(['body' => 'required|string|max:640']);
        $msg = $this->messenger->send($reservation->guest, $reservation, $data['body'], 'manual', $request->user());

        return back()->with($msg->status === 'sent' ? 'success' : 'error', $msg->status === 'sent' ? $msg->channel_label . ' sent to ' . $msg->to . '.' : 'Not sent: ' . $msg->error);
    }

    /** Write to a guest from their profile, or reply to one who texted. */
    public function sendForGuest(Request $request, Guest $guest)
    {
        $data = $request->validate(['body' => 'required|string|max:640', 'reply_to' => 'nullable|integer|exists:guest_messages,id']);
        $reservation = $data['reply_to'] ? GuestMessage::find($data['reply_to'])?->reservation : null;
        $msg = $this->messenger->send($guest, $reservation, $data['body'], 'manual', $request->user());
        if (! empty($data['reply_to'])) {
            GuestMessage::whereKey($data['reply_to'])->whereNull('read_at')->update(['read_at' => now()]);
        }

        return back()->with($msg->status === 'sent' ? 'success' : 'error', $msg->status === 'sent' ? $msg->channel_label . ' sent to ' . $msg->to . '.' : 'Not sent: ' . $msg->error);
    }

    public function markRead(GuestMessage $message)
    {
        $message->forceFill(['read_at' => $message->read_at ?? now()])->save();

        return back();
    }
}
