<?php

namespace App\Http\Controllers\Security;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\LostItem;
use App\Models\Room;
use App\Services\Security\SecurityService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Lost & found register (spec §12.3). */
class LostItemController extends Controller
{
    public function __construct(private SecurityService $service)
    {
        $this->middleware('permission:Manage lost & found');
    }

    public function index(Request $request)
    {
        $show = in_array($request->query('show'), ['stored', 'claimed', 'disposed', 'all'], true) ? $request->query('show') : 'stored';
        $category = array_key_exists((string) $request->query('category'), LostItem::CATEGORIES) ? $request->query('category') : null;
        $search = trim((string) $request->query('q'));
        $keepDays = (int) config('hotel.security.lost_item_days', 90);

        $items = LostItem::with(['room:id,number', 'finder:id,name', 'handedOverBy:id,name'])
            ->when($request->integer('item'), fn ($q, $id) => $q->whereKey($id), fn ($q) => $q
                ->when($show !== 'all', fn ($q) => $q->where('status', $show))
                ->when($category, fn ($q) => $q->where('category', $category))
                ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('description', 'like', "%{$search}%")->orWhere('number', 'like', "%{$search}%")
                    ->orWhere('found_location', 'like', "%{$search}%")->orWhere('claimant_name', 'like', "%{$search}%"))))
            ->latest('found_at')
            ->paginate(40)->withQueryString();

        $stored = LostItem::where('status', 'stored')->get(['id', 'valuable', 'found_at']);
        $stats = [
            'stored'   => $stored->count(),
            'valuable' => $stored->where('valuable', true)->count(),
            'old'      => $stored->filter(fn ($i) => $i->found_at->lt(now()->subDays($keepDays)))->count(),
            'returned' => LostItem::where('status', 'claimed')->where('handed_over_at', '>=', today()->startOfMonth())->count(),
        ];
        $rooms = Room::active()->orderBy('number')->get(['id', 'number']);
        $pagetitle = 'Lost & Found';

        return view('security.lost-found.index', compact('pagetitle', 'items', 'stats', 'show', 'category', 'search', 'rooms', 'keepDays'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'description'      => 'required|string|max:255',
            'category'         => ['required', Rule::in(array_keys(LostItem::CATEGORIES))],
            'valuable'         => 'nullable|boolean',
            'found_at'         => 'nullable|date|before_or_equal:now',
            'room_id'          => 'nullable|integer|exists:rooms,id',
            'found_location'   => 'required_without:room_id|nullable|string|max:120',
            'storage_location' => 'nullable|string|max:120',
            'photo'            => 'nullable|image|mimes:jpeg,png,jpg,webp|max:5120',
        ], ['found_location.required_without' => 'Say where it was found, or pick the room.']);
        if (empty($data['found_location'])) {
            $data['found_location'] = 'Room ' . Room::find($data['room_id'])->number;
        }

        $item = $this->service->logFound($data, $request->file('photo'), $request->user());

        return back()->with('success', "{$item->number} logged. Label the item with this number.");
    }

    public function handOver(Request $request, LostItem $item)
    {
        $data = $request->validate([
            'claimant_name'    => 'required|string|max:150',
            'claimant_contact' => 'nullable|string|max:60',
            'claimant_id'      => 'required|string|max:80',
        ], ['claimant_id.required' => "Check the owner's ID and note it before handing over."]);

        return $this->attempt(fn () => $this->service->handOver($item, $data, $request->user()), "{$item->number} returned to {$data['claimant_name']}.");
    }

    public function dispose(Request $request, LostItem $item)
    {
        $data = $request->validate(['disposal_note' => 'required|string|max:255']);

        return $this->attempt(fn () => $this->service->dispose($item, $data['disposal_note'], $request->user()), "{$item->number} marked as disposed.");
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
