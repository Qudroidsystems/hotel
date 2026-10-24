<?php

namespace App\Http\Controllers\Rooms;

use App\Http\Controllers\Controller;
use App\Models\RoomType;
use App\Models\RoomTypePhoto;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Room categories: rates, capacity, amenities and photos. */
class RoomTypeController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View rooms|Manage room types', ['only' => ['index']]);
        $this->middleware('permission:Manage room types', ['except' => ['index']]);
    }

    public function index()
    {
        $pagetitle = 'Room Types & Rates';
        $roomTypes = RoomType::withCount('rooms')->with('coverPhoto')->orderBy('base_rate')->get();

        return view('rooms.types.index', compact('pagetitle', 'roomTypes'));
    }

    public function create()
    {
        $pagetitle = 'New Room Type';
        $roomType = new RoomType(['max_adults' => 2, 'max_children' => 0, 'color' => '#405189', 'is_active' => true, 'amenities' => []]);

        return view('rooms.types.form', compact('pagetitle', 'roomType'));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        $roomType = DB::transaction(function () use ($data, $request) {
            $roomType = RoomType::create($data);
            $this->savePhotos($request, $roomType);

            return $roomType;
        });

        return redirect()->route('room-types.index')->with('success', "Room type “{$roomType->name}” created.");
    }

    public function edit(RoomType $roomType)
    {
        $pagetitle = 'Edit ' . $roomType->name;
        $roomType->load('photos');

        return view('rooms.types.form', compact('pagetitle', 'roomType'));
    }

    public function update(Request $request, RoomType $roomType)
    {
        $data = $this->validated($request, $roomType);

        DB::transaction(function () use ($data, $request, $roomType) {
            $roomType->update($data);
            $this->savePhotos($request, $roomType);
        });

        return redirect()->route('room-types.index')->with('success', "Room type “{$roomType->name}” updated.");
    }

    public function destroy(RoomType $roomType)
    {
        if ($roomType->rooms()->exists()) {
            return back()->with('error', "“{$roomType->name}” is used by {$roomType->rooms()->count()} room(s). Change those rooms first, or mark the type inactive.");
        }

        foreach ($roomType->photos as $photo) {
            Storage::disk('public')->delete($photo->path);
        }
        $roomType->delete();

        return redirect()->route('room-types.index')->with('success', 'Room type deleted.');
    }

    public function destroyPhoto(RoomTypePhoto $photo)
    {
        Storage::disk('public')->delete($photo->path);
        $photo->delete();

        return back()->with('success', 'Photo removed.');
    }

    private function validated(Request $request, ?RoomType $roomType = null): array
    {
        $data = $request->validate([
            'name'         => 'required|string|max:100',
            'code'         => ['required', 'string', 'max:20', 'alpha_dash', Rule::unique('room_types', 'code')->ignore($roomType?->id)],
            'description'  => 'nullable|string|max:3000',
            'base_rate'    => 'required|numeric|min:0|max:99999999',
            'weekend_rate' => 'nullable|numeric|min:0|max:99999999',
            'max_adults'   => 'required|integer|min:1|max:20',
            'max_children' => 'required|integer|min:0|max:20',
            'bed_type'     => 'nullable|string|max:50',
            'size_sqm'     => 'nullable|integer|min:1|max:10000',
            'amenities'    => 'nullable|array',
            'amenities.*'  => 'string|max:60',
            'extra_amenities' => 'nullable|string|max:500',
            'color'        => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'photos'       => 'nullable|array|max:10',
            'photos.*'     => 'image|mimes:jpeg,png,jpg,webp|max:5120',
        ]);

        // Ticked amenities + any extra ones typed in, comma separated.
        $extra = collect(explode(',', (string) ($data['extra_amenities'] ?? '')))->map(fn ($a) => trim($a))->filter();
        $data['amenities'] = collect($data['amenities'] ?? [])->merge($extra)->unique()->values()->all();

        $data['code'] = strtoupper($data['code']);
        $data['is_active'] = $request->boolean('is_active');
        unset($data['extra_amenities'], $data['photos']);

        return $data;
    }

    private function savePhotos(Request $request, RoomType $roomType): void
    {
        $next = (int) $roomType->photos()->max('sort_order');

        foreach ((array) $request->file('photos', []) as $file) {
            $roomType->photos()->create([
                'path'       => $file->store(RoomTypePhoto::DIR . '/' . $roomType->id, 'public'),
                'sort_order' => ++$next,
            ]);
        }
    }
}
