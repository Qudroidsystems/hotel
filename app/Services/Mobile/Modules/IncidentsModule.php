<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\Incident;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\User;
use App\Services\Security\SecurityService;
use Illuminate\Database\Eloquent\Model;

/** Report an incident on the spot, follow it, add updates, resolve it. */
class IncidentsModule extends StaffModule
{
    public function __construct(private SecurityService $security)
    {
    }

    public function key(): string { return 'incidents'; }
    public function title(): string { return 'Incidents'; }
    public function icon(): string { return 'report'; }
    public function section(): string { return 'Security'; }
    public function blurb(): string { return 'Report and follow incidents'; }

    public function allowed(User $user): bool
    {
        return $user->canAny(['View incidents', 'Report incidents', 'Manage incidents']);
    }

    private function seeAll(User $user): bool
    {
        return $user->canAny(['View incidents', 'Manage incidents']);
    }

    private function canWork(User $user, Incident $i): bool
    {
        return $user->can('Manage incidents') || ($i->assigned_to === $user->id && $user->canAny(['View incidents', 'Report incidents']));
    }

    public function count(User $user): ?int
    {
        return Incident::active()->when(! $this->seeAll($user), fn ($q) => $q->where(fn ($w) => $w->where('reported_by', $user->id)->orWhere('assigned_to', $user->id)))->count() ?: null;
    }

    public function tabs(User $user): array
    {
        return [['key' => 'active', 'label' => 'Open'], ['key' => 'resolved', 'label' => 'Resolved']];
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        return Incident::with(['room:id,number', 'reporter:id,name', 'assignee:id,name'])
            ->when($tab === 'resolved', fn ($q) => $q->whereIn('status', ['resolved', 'closed'])->where('updated_at', '>=', now()->subDays(30)), fn ($q) => $q->active())
            ->when(! $this->seeAll($user), fn ($q) => $q->where(fn ($w) => $w->where('reported_by', $user->id)->orWhere('assigned_to', $user->id)))
            ->orderByRaw("case severity when 'critical' then 0 when 'high' then 1 when 'medium' then 2 else 3 end")
            ->latest('occurred_at')->limit(100)->get()
            ->map(fn (Incident $i) => $this->item($user, $i))->all();
    }

    public function find(int $id): ?Model
    {
        return Incident::with(['room:id,number', 'reporter:id,name', 'assignee:id,name'])->find($id);
    }

    public function item(User $user, Model $i): array
    {
        /** @var Incident $i */
        abort_unless($this->seeAll($user) || in_array($user->id, [$i->reported_by, $i->assigned_to], true), 403);

        return [
            'id' => $i->id,
            'title' => $i->title,
            'subtitle' => trim(($i->number ? $i->number . ' · ' : '') . $i->type_label . ' · ' . $i->where),
            'badge' => ['label' => $i->status_label, 'tone' => self::tone($i->status_pill)],
            'chips' => [['label' => $i->severity_label, 'tone' => self::tone($i->severity_pill)], ['label' => self::when($i->occurred_at) ?? '', 'tone' => 'muted']],
            'urgent' => in_array($i->severity, ['critical', 'high'], true) && $i->isActive(),
            'body' => $i->description,
            'lines' => self::lines([
                'Reported by' => $i->reporter?->name, 'Handled by' => $i->assignee?->name ?? 'Nobody yet',
                'People involved' => $i->people_involved, 'Police reference' => $i->police_reference, 'Resolution' => $i->resolution,
            ]),
            'history' => $i->updates()->with('user:id,name')->latest('id')->limit(10)->get()->map(fn ($u) => ['who' => $u->user?->name, 'what' => $u->note, 'at' => self::when($u->created_at)])->all(),
            'actions' => $this->actions($user, $i),
        ];
    }

    public function actions(User $user, Model $i): array
    {
        /** @var Incident $i */
        if (! $this->canWork($user, $i) || $i->status === 'closed') {
            return [];
        }
        $statuses = collect(Incident::STATUSES)->except($user->can('Manage incidents') ? [] : ['closed'])->all();
        $a = [['id' => 'update', 'label' => 'Add update', 'tone' => 'neutral', 'fields' => [
            self::field('note', 'What happened since', 'textarea', ['required' => true]),
            self::field('status', 'Status now', 'select', ['value' => $i->status, 'options' => self::options($statuses)]),
            self::field('police_reference', 'Police reference', 'text', ['max' => 60, 'value' => $i->police_reference]),
        ]]];
        if ($i->isActive()) {
            $a[] = ['id' => 'resolve', 'label' => 'Resolve', 'tone' => 'primary', 'fields' => [self::field('resolution', 'How was it resolved?', 'textarea', ['required' => true])]];
        }
        if ($user->can('Manage incidents') && $i->isActive() && $i->assigned_to !== $user->id) {
            $a[] = ['id' => 'mine', 'label' => 'I’ll handle it', 'tone' => 'neutral'];
        }

        return $a;
    }

    public function perform(User $user, Model $i, string $action, array $input, array $files): string
    {
        /** @var Incident $i */
        abort_unless($this->canWork($user, $i), 403);
        switch ($action) {
            case 'update':
                if (($input['status'] ?? null) === 'closed' && ! $user->can('Manage incidents')) {
                    abort(403);
                }
                if (! empty($input['police_reference'])) {
                    $i->forceFill(['police_reference' => $input['police_reference']])->save();
                }
                $this->security->addUpdate($i, $user, (string) $input['note'], ($input['status'] ?? null) !== $i->status ? ($input['status'] ?? null) : null);

                return 'Update added.';
            case 'resolve':
                $this->security->resolve($i, $user, (string) $input['resolution']);

                return "{$i->number} resolved.";
            case 'mine':
                abort_unless($user->can('Manage incidents'), 403);
                $this->security->assign($i, $user, $user);

                return 'It’s yours.';
        }
        throw new BookingException('Unknown action.');
    }

    public function form(User $user): ?array
    {
        if (! $user->canAny(['Report incidents', 'Manage incidents'])) {
            return null;
        }

        return ['title' => 'Report an incident', 'submit' => 'Report', 'fields' => [
            self::field('type', 'What kind', 'select', ['required' => true, 'options' => self::options(Incident::TYPES)]),
            self::field('severity', 'How serious', 'select', ['required' => true, 'value' => 'medium', 'options' => self::options(Incident::SEVERITIES)]),
            self::field('title', 'In a few words', 'text', ['required' => true, 'max' => 150]),
            self::field('description', 'What happened', 'textarea', ['required' => true, 'max' => 5000]),
            self::field('room_id', 'Room', 'select', ['options' => Room::active()->orderBy('number')->get(['id', 'number'])->map(fn ($r) => ['value' => (string) $r->id, 'label' => 'Room ' . $r->number])->all()]),
            self::field('location', 'Or where', 'text', ['max' => 120]),
            self::field('occurred_at', 'When (leave empty for now)', 'datetime'),
            self::field('people_involved', 'People involved', 'textarea', ['max' => 500]),
            self::field('photos', 'Photos', 'photos'),
        ]];
    }

    public function create(User $user, array $input, array $files): array
    {
        abort_unless($user->canAny(['Report incidents', 'Manage incidents']), 403);
        if (empty($input['room_id']) && empty($input['location'])) {
            throw new BookingException('Pick the room or say where it happened.');
        }
        if (! empty($input['occurred_at']) && now()->lt($input['occurred_at'])) {
            throw new BookingException('When it happened can’t be in the future.');
        }
        if (! empty($input['room_id'])) {
            $input['reservation_id'] = Reservation::inHouse()->whereHas('rooms', fn ($q) => $q->where('room_id', $input['room_id']))->value('id');
        }
        $incident = $this->security->report(array_filter($input, fn ($x) => $x !== null), array_values(array_filter((array) ($files['photos'] ?? []))), $user);

        return ["{$incident->number} reported." . ($incident->isSerious() ? ' Management has been flagged.' : ''), $incident];
    }

    public function emptyText(?string $tab): string
    {
        return $tab === 'resolved' ? 'Nothing resolved in the last 30 days.' : 'No open incidents.';
    }
}
