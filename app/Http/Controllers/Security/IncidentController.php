<?php

namespace App\Http\Controllers\Security;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\EmergencyContact;
use App\Models\Incident;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Security\SecurityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Incident reports and the emergency contact list (spec §10.2). */
class IncidentController extends Controller
{
    public function __construct(private SecurityService $service)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAny(['View incidents', 'Report incidents', 'Manage incidents']), 403);
        $seeAll = $user->canAny(['View incidents', 'Manage incidents']);

        $show = in_array($request->query('show'), ['active', 'resolved', 'closed', 'all'], true) ? $request->query('show') : 'active';
        $severity = array_key_exists((string) $request->query('severity'), Incident::SEVERITIES) ? $request->query('severity') : null;
        $type = array_key_exists((string) $request->query('type'), Incident::TYPES) ? $request->query('type') : null;

        $incidents = Incident::with(['room:id,number', 'reporter:id,name', 'assignee:id,name'])
            ->when($show === 'active', fn ($q) => $q->active())
            ->when(in_array($show, ['resolved', 'closed'], true), fn ($q) => $q->where('status', $show))
            ->when($severity, fn ($q) => $q->where('severity', $severity))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->when(! $seeAll, fn ($q) => $q->where(fn ($w) => $w->where('reported_by', $user->id)->orWhere('assigned_to', $user->id)))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest('occurred_at')
            ->paginate(40)->withQueryString();

        $active = Incident::active()->get(['id', 'severity', 'assigned_to']);
        $month = Incident::where('occurred_at', '>=', today()->startOfMonth())->get(['id', 'type', 'occurred_at', 'resolved_at']);
        $resolved = $month->whereNotNull('resolved_at');
        $stats = [
            'active'     => $active->count(),
            'serious'    => $active->whereIn('severity', ['high', 'critical'])->count(),
            'unassigned' => $active->whereNull('assigned_to')->count(),
            'month'      => $month->count(),
            'top_type'   => $month->countBy('type')->sortDesc()->keys()->first(),
            'hours'      => $resolved->isNotEmpty() ? round($resolved->avg(fn ($i) => $i->occurred_at->diffInMinutes($i->resolved_at)) / 60, 1) : null,
        ];

        $contacts = EmergencyContact::orderBy('sort_order')->orderBy('name')->get();
        $rooms = Room::active()->orderBy('number')->get(['id', 'number']);
        $officers = $this->officers();
        $pagetitle = 'Incidents';

        return view('security.incidents.index', compact('pagetitle', 'incidents', 'stats', 'show', 'severity', 'type', 'seeAll', 'contacts', 'rooms', 'officers'));
    }

    public function show(Request $request, Incident $incident)
    {
        $user = $request->user();
        abort_unless($user->canAny(['View incidents', 'Manage incidents']) || in_array($user->id, [$incident->reported_by, $incident->assigned_to], true), 403);
        $incident->load(['room', 'reservation.guest', 'reporter', 'assignee', 'resolvedBy', 'updates.user:id,name']);
        $officers = $this->officers();
        $canWork = $this->canWork($user, $incident);
        $pagetitle = $incident->number;

        return view('security.incidents.show', compact('pagetitle', 'incident', 'officers', 'canWork'));
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAny(['Report incidents', 'Manage incidents']), 403);
        $data = $request->validate([
            'type'             => ['required', Rule::in(array_keys(Incident::TYPES))],
            'severity'         => ['required', Rule::in(array_keys(Incident::SEVERITIES))],
            'title'            => 'required|string|max:150',
            'description'      => 'required|string|max:5000',
            'room_id'          => 'nullable|integer|exists:rooms,id',
            'location'         => 'nullable|string|max:120',
            'occurred_at'      => 'nullable|date|before_or_equal:now',
            'people_involved'  => 'nullable|string|max:500',
            'police_reference' => 'nullable|string|max:60',
            'assigned_to'      => 'nullable|integer|exists:users,id',
            'photos'           => 'nullable|array|max:4',
            'photos.*'         => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);
        if (! $request->user()->can('Manage incidents')) {
            unset($data['assigned_to']);
        }
        if (! empty($data['room_id'])) {
            $data['reservation_id'] = Reservation::inHouse()->whereHas('rooms', fn ($q) => $q->where('room_id', $data['room_id']))->value('id');  // guest in the room at the time
        }

        $incident = $this->service->report($data, $request->file('photos', []), $request->user());

        return redirect()->route('incidents.show', $incident)->with('success', "{$incident->number} reported." . ($incident->isSerious() ? ' Management has been flagged.' : ''));
    }

    public function addUpdate(Request $request, Incident $incident)
    {
        abort_unless($this->canWork($request->user(), $incident), 403);
        $data = $request->validate([
            'note'             => 'required|string|max:2000',
            'status'           => ['nullable', Rule::in(array_keys(Incident::STATUSES))],
            'police_reference' => 'nullable|string|max:60',
        ]);
        if (in_array($data['status'] ?? null, ['closed'], true) && ! $request->user()->can('Manage incidents')) {
            abort(403);
        }
        if (! empty($data['police_reference'])) {
            $incident->forceFill(['police_reference' => $data['police_reference']])->save();
        }

        return $this->attempt(fn () => $this->service->addUpdate($incident, $request->user(), $data['note'], $data['status'] ?? null), 'Update added.');
    }

    public function assign(Request $request, Incident $incident)
    {
        abort_unless($request->user()->can('Manage incidents'), 403);
        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);

        return $this->attempt(fn () => $this->service->assign($incident, isset($data['assigned_to']) ? User::find($data['assigned_to']) : null, $request->user()), 'Incident assigned.');
    }

    // ── Emergency contacts ────────────────────────────────────────────

    public function storeContact(Request $request)
    {
        abort_unless($request->user()->can('Manage incidents'), 403);
        EmergencyContact::create($this->validContact($request));

        return back()->with('success', 'Contact added.');
    }

    public function updateContact(Request $request, EmergencyContact $contact)
    {
        abort_unless($request->user()->can('Manage incidents'), 403);
        $contact->update($this->validContact($request));

        return back()->with('success', 'Contact updated.');
    }

    public function destroyContact(Request $request, EmergencyContact $contact)
    {
        abort_unless($request->user()->can('Manage incidents'), 403);
        $contact->delete();

        return back()->with('success', 'Contact removed.');
    }

    // ------------------------------------------------------------------

    private function validContact(Request $request): array
    {
        return $request->validate([
            'name'       => 'required|string|max:120',
            'role'       => 'nullable|string|max:120',
            'phone'      => 'required|string|max:40',
            'alt_phone'  => 'nullable|string|max:40',
            'notes'      => 'nullable|string|max:255',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]) + ['sort_order' => 0];
    }

    private function canWork(User $user, Incident $incident): bool
    {
        return $user->can('Manage incidents') || ($incident->assigned_to === $user->id && $user->canAny(['View incidents', 'Report incidents']));
    }

    private function officers()
    {
        return User::permission(['View incidents', 'Manage incidents'])->where('is_disabled', false)->orderBy('name')->get(['id', 'name']);
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
