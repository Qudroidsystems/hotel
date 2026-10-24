<?php

namespace App\Http\Controllers\It;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\It\ItAsset;
use App\Models\It\ItMaintenanceWindow;
use App\Models\It\ItTicket;
use App\Models\User;
use App\Services\It\ServiceDesk;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * IT service desk (spec §11.4). Any signed-in staff member can raise a ticket
 * and follow their own; IT staff ("Manage IT tickets") work all of them.
 */
class TicketController extends Controller
{
    public function __construct(private ServiceDesk $desk)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $isIt = $user->can('Manage IT tickets');
        $seeAll = $isIt || $user->can('View IT control room');

        $show = in_array($request->query('show'), ['active', 'mine', 'unassigned', 'overdue', 'resolved', 'closed', 'all'], true) ? $request->query('show') : 'active';
        $priority = array_key_exists((string) $request->query('priority'), ItTicket::PRIORITIES) ? $request->query('priority') : null;
        $category = array_key_exists((string) $request->query('category'), ItTicket::CATEGORIES) ? $request->query('category') : null;
        $q = trim((string) $request->query('q'));

        $tickets = ItTicket::with(['requester:id,name', 'assignee:id,name'])
            ->when(! $seeAll, fn ($w) => $w->where(fn ($s) => $s->where('requested_by', $user->id)->orWhere('assigned_to', $user->id)))
            ->when($show === 'active', fn ($w) => $w->active())
            ->when($show === 'mine', fn ($w) => $w->active()->where('assigned_to', $user->id))
            ->when($show === 'unassigned', fn ($w) => $w->active()->whereNull('assigned_to'))
            ->when($show === 'overdue', fn ($w) => $w->overdue())
            ->when(in_array($show, ['resolved', 'closed'], true), fn ($w) => $w->where('status', $show))
            ->when($priority, fn ($w) => $w->where('priority', $priority))
            ->when($category, fn ($w) => $w->where('category', $category))
            ->when($q !== '', fn ($w) => $w->where(fn ($s) => $s->where('title', 'like', "%{$q}%")->orWhere('number', 'like', "%{$q}%")->orWhere('location', 'like', "%{$q}%")))
            ->orderByRaw("case when status in ('open','in_progress','waiting') then 0 else 1 end")
            ->orderByRaw("case priority when 'critical' then 0 when 'high' then 1 when 'normal' then 2 else 3 end")
            ->oldest()->paginate(40)->withQueryString();

        $active = ItTicket::active()->when(! $seeAll, fn ($w) => $w->where('requested_by', $user->id))->get(['id', 'assigned_to', 'priority']);
        $stats = [
            'active'     => $active->count(),
            'mine'       => $active->where('assigned_to', $user->id)->count(),
            'unassigned' => $active->whereNull('assigned_to')->count(),
            'overdue'    => $seeAll ? ItTicket::overdue()->count() : 0,
            'serious'    => $active->whereIn('priority', ['critical', 'high'])->count(),
        ];
        $assets = ItAsset::where('is_active', true)->orderBy('name')->get(['id', 'name', 'location']);
        $maintenance = ItMaintenanceWindow::upcoming()->orderBy('starts_at')->limit(3)->get();
        $pagetitle = $seeAll ? 'IT Service Desk' : 'My IT Requests';

        return view('it.tickets.index', compact('pagetitle', 'tickets', 'stats', 'show', 'priority', 'category', 'q', 'isIt', 'seeAll', 'assets', 'maintenance'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'title'       => 'required|string|max:150',
            'description' => 'required|string|max:5000',
            'category'    => ['required', Rule::in(array_keys(ItTicket::CATEGORIES))],
            'priority'    => ['required', Rule::in(array_keys(ItTicket::PRIORITIES))],
            'department'  => 'nullable|string|max:80',
            'location'    => 'nullable|string|max:120',
            'asset_id'    => 'nullable|integer|exists:it_assets,id',
        ]);
        $ticket = $this->desk->open($data, $request->user());

        return redirect()->route('it.tickets.show', $ticket)->with('success', "{$ticket->number} raised. IT aims to respond by " . $ticket->response_due_at->format('g:i A' . ($ticket->response_due_at->isToday() ? '' : ', D j M')) . '.');
    }

    public function show(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        $isIt = $user->can('Manage IT tickets');
        abort_unless($isIt || $user->can('View IT control room') || in_array($user->id, [$ticket->requested_by, $ticket->assigned_to], true), 403);

        $ticket->load(['requester:id,name,email', 'assignee:id,name', 'resolvedBy:id,name', 'asset',
            'updates' => fn ($q) => $q->when(! $isIt, fn ($w) => $w->where('is_internal', false))->with('user:id,name')]);
        $similar = $isIt ? $this->desk->similar($ticket) : collect();
        $technicians = $isIt ? User::permission('Manage IT tickets')->where('is_disabled', false)->orderBy('name')->get(['id', 'name']) : collect();
        $alert = \App\Models\It\ItAlert::where('ticket_id', $ticket->id)->first();
        $pagetitle = $ticket->number;

        return view('it.tickets.show', compact('pagetitle', 'ticket', 'isIt', 'similar', 'technicians', 'alert'));
    }

    public function update(Request $request, ItTicket $ticket)
    {
        $user = $request->user();
        $isIt = $user->can('Manage IT tickets');
        abort_unless($isIt || in_array($user->id, [$ticket->requested_by, $ticket->assigned_to], true), 403);
        $data = $request->validate([
            'note'   => 'required|string|max:3000',
            'status' => ['nullable', Rule::in(array_keys(ItTicket::STATUSES))],
        ]);

        try {
            $this->desk->update($ticket, $user, $data['note'], $data['status'] ?? null, $isIt && $request->boolean('internal'));
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Update saved.');
    }

    public function assign(Request $request, ItTicket $ticket)
    {
        abort_unless($request->user()->can('Manage IT tickets'), 403);
        $data = $request->validate(['assigned_to' => 'nullable|integer|exists:users,id']);
        $to = isset($data['assigned_to']) ? User::find($data['assigned_to']) : null;
        abort_if($to && ! $to->can('Manage IT tickets'), 422, 'That person does not work IT tickets.');
        $this->desk->assign($ticket, $to, $request->user());

        return back()->with('success', $to ? "Assigned to {$to->name}." : 'Unassigned.');
    }

    public function triage(Request $request, ItTicket $ticket)
    {
        abort_unless($request->user()->can('Manage IT tickets'), 403);
        $data = $request->validate([
            'priority'   => ['required', Rule::in(array_keys(ItTicket::PRIORITIES))],
            'root_cause' => 'nullable|string|max:1000',
        ]);
        $this->desk->triage($ticket, $request->user(), $data['priority'], $data['root_cause'] ?? null);

        return back()->with('success', 'Saved.');
    }
}
