<?php

namespace App\Services\Mobile\Modules;

use App\Exceptions\BookingException;
use App\Models\LostItem;
use App\Models\Room;
use App\Models\User;
use App\Services\Security\SecurityService;
use Illuminate\Database\Eloquent\Model;

/** Log a found item with a photo where it's found; hand it back with the owner's ID. */
class LostFoundModule extends StaffModule
{
    public function __construct(private SecurityService $security)
    {
    }

    public function key(): string { return 'lost_found'; }
    public function title(): string { return 'Lost & found'; }
    public function icon(): string { return 'inventory_2'; }
    public function section(): string { return 'Security'; }
    public function blurb(): string { return 'Log found items, hand them back'; }

    public function allowed(User $user): bool
    {
        return $user->can('Manage lost & found');
    }

    public function tabs(User $user): array
    {
        return [['key' => 'stored', 'label' => 'In storage'], ['key' => 'done', 'label' => 'Returned']];
    }

    public function searchable(): bool
    {
        return true;
    }

    public function items(User $user, ?string $tab, ?string $search): array
    {
        return LostItem::with(['room:id,number', 'finder:id,name'])
            ->when($tab === 'done', fn ($q) => $q->whereIn('status', ['claimed', 'disposed'])->where('handed_over_at', '>=', now()->subDays(60)), fn ($q) => $q->where('status', 'stored'))
            ->when($search, fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$search}%")->orWhere('number', 'like', "%{$search}%")
                ->orWhere('found_location', 'like', "%{$search}%")->orWhere('claimant_name', 'like', "%{$search}%")))
            ->latest('found_at')->limit(100)->get()->map(fn (LostItem $i) => $this->item($user, $i))->all();
    }

    public function find(int $id): ?Model
    {
        return LostItem::with(['room:id,number', 'finder:id,name'])->find($id);
    }

    public function item(User $user, Model $i): array
    {
        /** @var LostItem $i */
        return [
            'id' => $i->id,
            'title' => $i->description,
            'subtitle' => trim(($i->number ?? '') . ' · ' . $i->category_label . ' · ' . ($i->room ? 'Room ' . $i->room->number : $i->found_location)),
            'badge' => ['label' => $i->status_label, 'tone' => self::tone($i->status_pill)],
            'chips' => array_values(array_filter([
                ['label' => 'Found ' . self::when($i->found_at), 'tone' => 'muted'],
                $i->valuable ? ['label' => 'Valuable', 'tone' => 'warning'] : null,
            ])),
            'image' => $i->photo_path ? asset('storage/' . $i->photo_path) : null,
            'lines' => self::lines([
                'Found by' => $i->finder?->name, 'Kept at' => $i->storage_location, 'Returned to' => $i->claimant_name,
                'Contact' => $i->claimant_contact, 'ID checked' => $i->claimant_id, 'Disposal' => $i->disposal_note,
            ]),
            'actions' => $this->actions($user, $i),
        ];
    }

    public function actions(User $user, Model $i): array
    {
        /** @var LostItem $i */
        if ($i->status !== 'stored') {
            return [];
        }

        return [
            ['id' => 'hand_over', 'label' => 'Hand back', 'tone' => 'primary', 'fields' => [
                self::field('claimant_name', 'Owner’s name', 'text', ['required' => true, 'max' => 150]),
                self::field('claimant_contact', 'Phone or e-mail', 'text', ['max' => 60]),
                self::field('claimant_id', 'ID seen (type and number)', 'text', ['required' => true, 'max' => 80]),
            ]],
            ['id' => 'dispose', 'label' => 'Dispose', 'tone' => 'danger', 'fields' => [self::field('disposal_note', 'How (donated, destroyed …)', 'text', ['required' => true])]],
        ];
    }

    public function perform(User $user, Model $i, string $action, array $input, array $files): string
    {
        /** @var LostItem $i */
        if ($action === 'hand_over') {
            $this->security->handOver($i, $input, $user);

            return "{$i->number} returned to {$input['claimant_name']}.";
        }
        if ($action === 'dispose') {
            $this->security->dispose($i, (string) $input['disposal_note'], $user);

            return "{$i->number} disposed of.";
        }
        throw new BookingException('Unknown action.');
    }

    public function form(User $user): ?array
    {
        return ['title' => 'Log a found item', 'submit' => 'Log item', 'fields' => [
            self::field('description', 'What is it', 'text', ['required' => true]),
            self::field('category', 'Kind', 'select', ['required' => true, 'options' => self::options(LostItem::CATEGORIES)]),
            self::field('valuable', 'Valuable (money, jewellery, phone, ID …)', 'switch'),
            self::field('room_id', 'Found in room', 'select', ['options' => Room::active()->orderBy('number')->get(['id', 'number'])->map(fn ($r) => ['value' => (string) $r->id, 'label' => 'Room ' . $r->number])->all()]),
            self::field('found_location', 'Or found at', 'text', ['max' => 120]),
            self::field('storage_location', 'Kept at', 'text', ['max' => 120]),
            self::field('photo', 'Photo', 'photo'),
        ]];
    }

    public function create(User $user, array $input, array $files): array
    {
        if (empty($input['room_id']) && empty($input['found_location'])) {
            throw new BookingException('Say where it was found, or pick the room.');
        }
        if (empty($input['found_location']) && ! empty($input['room_id'])) {
            $input['found_location'] = 'Room ' . Room::find($input['room_id'])?->number;
        }
        $item = $this->security->logFound(array_filter($input, fn ($x) => $x !== null), $files['photo'] ?? null, $user);

        return ["Logged as {$item->number}.", $item];
    }

    public function emptyText(?string $tab): string
    {
        return $tab === 'done' ? 'Nothing returned recently.' : 'Nothing in storage.';
    }
}
