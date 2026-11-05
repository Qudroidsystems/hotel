<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\Booking\GuestFeedback;
use App\Models\User;
use App\Services\Booking\FeedbackService;
use Illuminate\Database\Eloquent\Model;

/** What guests said after their stay; follow up the unhappy ones. */
class FeedbackModule extends StaffModule
{
    public function __construct(private FeedbackService $feedback)
    {
    }

    public function key(): string { return 'feedback'; }
    public function title(): string { return 'Guest feedback'; }
    public function icon(): string { return 'reviews'; }
    public function section(): string { return 'Guests'; }
    public function blurb(): string { return 'Scores and comments after check-out'; }

    public function allowed(User $user): bool
    {
        return $user->canAny(['View guest feedback', 'Handle guest feedback']);
    }

    public function count(User $user): ?int
    {
        return GuestFeedback::where('status', GuestFeedback::FOLLOW_UP)->count() ?: null;
    }

    public function tabs(User $user): array
    {
        return [['key' => 'open', 'label' => 'To handle'], ['key' => 'all', 'label' => 'Latest']];
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        return GuestFeedback::with(['guest', 'reservation'])
            ->when($tab !== 'all', fn ($q) => $q->whereIn('status', [GuestFeedback::NEW, GuestFeedback::FOLLOW_UP])->orderByRaw("case status when 'follow_up' then 0 else 1 end"))
            ->latest('id')->limit(60)->get()->map(fn ($f) => $this->item($user, $f))->all();
    }

    public function find(int $id): ?Model
    {
        return GuestFeedback::with(['guest', 'reservation'])->find($id);
    }

    public function item(User $user, Model $f): array
    {
        /** @var GuestFeedback $f */
        [$label, $pill] = GuestFeedback::STATUSES[$f->status] ?? [ucfirst($f->status), 'st-muted'];
        $ratings = collect((array) $f->ratings)->mapWithKeys(fn ($v, $k) => [(GuestFeedback::ASPECTS[$k][0] ?? ucfirst($k)) => str_repeat('★', (int) $v) . str_repeat('☆', 5 - (int) $v)])->all();

        return [
            'id' => $f->id,
            'title' => ($f->guest?->full_name ?? 'Guest') . ' · ' . str_repeat('★', (int) $f->overall) . str_repeat('☆', 5 - (int) $f->overall),
            'subtitle' => trim(($f->reservation?->reference ?? '') . ' · ' . self::when($f->created_at)),
            'badge' => ['label' => $label, 'tone' => self::tone($pill)],
            'chips' => array_values(array_filter([
                $f->recommend !== null ? ['label' => "Would recommend {$f->recommend}/10", 'tone' => match ($f->nps_group) { 'promoter' => 'success', 'passive' => 'muted', default => 'danger' }] : null,
                $f->contact_ok ? ['label' => 'Happy to be contacted', 'tone' => 'info'] : null,
            ])),
            'urgent' => $f->isLow() && $f->status !== GuestFeedback::RESOLVED,
            'body' => $f->comment,
            'lines' => self::lines($ratings + ['Phone' => $f->contact_ok ? $f->guest?->phone : null, 'Follow-up note' => $f->follow_up_note]),
            'phone' => $f->contact_ok ? $f->guest?->phone : null,
            'actions' => $this->actions($user, $f),
        ];
    }

    public function actions(User $user, Model $f): array
    {
        /** @var GuestFeedback $f */
        if (! $user->can('Handle guest feedback')) {
            return [];
        }
        $a = [];
        if ($f->status !== GuestFeedback::RESOLVED) {
            $a[] = ['id' => 'resolved', 'label' => 'Handled', 'tone' => 'primary', 'fields' => [self::field('note', 'What was done (optional)', 'textarea', ['max' => 1000])]];
        }
        if ($f->status !== GuestFeedback::FOLLOW_UP) {
            $a[] = ['id' => 'follow_up', 'label' => 'Needs follow-up', 'tone' => 'neutral', 'fields' => [self::field('note', 'Note (optional)', 'textarea', ['max' => 1000])]];
        }

        return $a;
    }

    public function perform(User $user, Model $f, string $action, array $input, array $files): string
    {
        /** @var GuestFeedback $f */
        abort_unless($user->can('Handle guest feedback'), 403);
        if (! in_array($action, [GuestFeedback::RESOLVED, GuestFeedback::FOLLOW_UP], true)) {
            throw new BookingException('Unknown action.');
        }
        $this->feedback->handle($f, $user, $action, $input['note'] ?? null);

        return $action === GuestFeedback::RESOLVED ? 'Marked as handled.' : 'Marked for follow-up.';
    }

    public function emptyText(?string $tab): string
    {
        return $tab === 'all' ? 'No feedback yet.' : 'Nothing to handle.';
    }
}
