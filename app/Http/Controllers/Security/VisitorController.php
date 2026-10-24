<?php

namespace App\Http\Controllers\Security;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Reservation;
use App\Models\User;
use App\Models\Visitor;
use App\Services\Security\SecurityService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Visitor register and temporary QR passes (spec §10.1). */
class VisitorController extends Controller
{
    public function __construct(private SecurityService $service)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->can('View visitors'), 403);
        $show = in_array($request->query('show'), ['on_site', 'today', 'all'], true) ? $request->query('show') : 'on_site';
        $search = trim((string) $request->query('q'));

        $visitors = Visitor::with(['reservation.guest', 'reservation.rooms.room:id,number', 'signedInBy:id,name', 'signedOutBy:id,name'])
            ->when($show === 'on_site', fn ($q) => $q->onSite())
            ->when($show === 'today', fn ($q) => $q->where('signed_in_at', '>=', today()))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('full_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('company', 'like', "%{$search}%")->orWhere('vehicle_plate', 'like', "%{$search}%")->orWhere('host', 'like', "%{$search}%")))
            ->orderByRaw('signed_out_at is not null')->latest('signed_in_at')
            ->paginate(40)->withQueryString();

        $onSite = Visitor::onSite()->get(['id', 'expected_out_at', 'party_size', 'purpose']);
        $stats = [
            'on_site'    => $onSite->count(),
            'people'     => (int) $onSite->sum('party_size'),
            'overstayed' => $onSite->filter(fn ($v) => $v->expected_out_at && $v->expected_out_at->isPast())->count(),
            'today'      => Visitor::where('signed_in_at', '>=', today())->count(),
            'contractors' => $onSite->whereIn('purpose', ['contractor', 'delivery'])->count(),
        ];

        $inHouse = Reservation::inHouse()->with(['guest:id,first_name,last_name', 'rooms.room:id,number'])->get()
            ->map(fn ($r) => ['id' => $r->id, 'label' => ($r->rooms->first()?->room?->number ? $r->rooms->first()->room->number . ' — ' : '') . $r->guest?->full_name])
            ->sortBy('label')->values();
        $staff = User::where('is_disabled', false)->orderBy('name')->get(['id', 'name']);
        $pagetitle = 'Visitors';

        return view('security.visitors.index', compact('pagetitle', 'visitors', 'stats', 'show', 'search', 'inHouse', 'staff'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->can('Manage visitors'), 403);
        $data = $request->validate([
            'full_name'       => 'required|string|max:150',
            'phone'           => 'nullable|string|max:30',
            'id_type'         => ['nullable', Rule::in(Visitor::ID_TYPES)],
            'id_number'       => 'nullable|string|max:50',
            'company'         => 'nullable|string|max:120',
            'purpose'         => ['required', Rule::in(array_keys(Visitor::PURPOSES))],
            'reservation_id'  => ['nullable', 'integer', Rule::exists('reservations', 'id')],
            'host_user_id'    => 'nullable|integer|exists:users,id',
            'host'            => 'nullable|string|max:150',
            'vehicle_plate'   => 'nullable|string|max:20',
            'party_size'      => 'nullable|integer|min:1|max:50',
            'expected_out_at' => 'nullable|date|after:now',
            'notes'           => 'nullable|string|max:500',
        ]);
        if (! empty($data['host_user_id']) && empty($data['host'])) {
            $data['host'] = User::find($data['host_user_id'])?->name;
        }

        $visitor = $this->service->signIn($data, $request->user());

        return redirect()->route('visitors.pass', $visitor)->with('success', "{$visitor->full_name} signed in. Print or show this pass.");
    }

    /** Printable pass with the QR the gate scans. */
    public function pass(Request $request, Visitor $visitor)
    {
        abort_unless($request->user()->can('View visitors'), 403);
        $visitor->load(['reservation.guest', 'reservation.rooms.room', 'signedInBy']);

        return view('security.visitors.pass', compact('visitor'));
    }

    public function signOut(Request $request, Visitor $visitor)
    {
        abort_unless($request->user()->can('Manage visitors'), 403);

        return $this->attempt(fn () => $this->service->signOut($visitor, $request->user()), "{$visitor->full_name} signed out.");
    }

    public function extend(Request $request, Visitor $visitor)
    {
        abort_unless($request->user()->can('Manage visitors'), 403);
        $data = $request->validate(['expected_out_at' => 'required|date']);

        return $this->attempt(fn () => $this->service->extend($visitor, Carbon::parse($data['expected_out_at'])), "Pass extended to " . Carbon::parse($data['expected_out_at'])->format('g:i A') . '.');
    }

    /** Gate check: scan or type a pass code. */
    public function check(Request $request)
    {
        abort_unless($request->user()->can('View visitors'), 403);
        $result = null;
        if ($code = trim((string) $request->query('code'))) {
            $code = str_contains($code, '/') ? basename(parse_url($code, PHP_URL_PATH) ?: $code) : $code;  // a scanned URL works too
            $result = $this->service->checkPass(substr($code, 0, 30));
            $result['visitor']?->load(['reservation.guest', 'reservation.rooms.room']);
        }
        $pagetitle = 'Check a visitor pass';

        return view('security.visitors.check', compact('pagetitle', 'result'));
    }

    private function attempt(callable $action, string $success)
    {
        try {
            $action();
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $success);
    }
}
