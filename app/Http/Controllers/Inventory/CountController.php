<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Inventory\StockCategory;
use App\Models\Inventory\StockCount;
use App\Models\Inventory\StockLocation;
use App\Services\Inventory\StockService;
use Illuminate\Http\Request;

/** Stocktakes: count a store, then book the differences. */
class CountController extends Controller
{
    public function __construct(private StockService $stock)
    {
        $this->middleware('permission:View stock')->only(['index', 'show']);
        $this->middleware('permission:Count stock')->only(['store', 'save', 'cancel']);
        $this->middleware('permission:Post stock counts')->only(['post']);
    }

    public function index()
    {
        $counts = StockCount::with(['location', 'category', 'startedBy', 'postedBy'])->withCount('lines')
            ->withCount(['lines as counted_count' => fn ($q) => $q->whereNotNull('counted_quantity')])
            ->latest('id')->paginate(25);
        $locations = StockLocation::active()->ordered()->get();
        $categories = StockCategory::ordered()->get();
        $pagetitle = 'Stocktakes';

        return view('inventory.counts.index', compact('pagetitle', 'counts', 'locations', 'categories'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'location_id' => 'required|exists:stock_locations,id',
            'category_id' => 'nullable|exists:stock_categories,id',
            'notes'       => 'nullable|string|max:500',
        ]);
        try {
            $count = $this->stock->startCount((int) $data['location_id'], $data['category_id'] ?? null, $data['notes'] ?? null);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.counts.show', $count)->with('success', 'Stocktake started — enter what you count.');
    }

    public function show(Request $request, StockCount $count)
    {
        $count->load(['location', 'category', 'startedBy', 'postedBy', 'lines.item.category']);
        $pagetitle = $count->number;

        return view($request->boolean('print') ? 'inventory.counts.print' : 'inventory.counts.show', compact('pagetitle', 'count'));
    }

    public function save(Request $request, StockCount $count)
    {
        $data = $request->validate(['counted' => 'array', 'counted.*' => 'nullable|numeric|min:0|max:99999999']);
        $andPost = $request->input('then') === 'post';
        abort_if($andPost && ! $request->user()->can('Post stock counts'), 403);
        try {
            $this->stock->saveCount($count, $data['counted'] ?? []);
            if ($andPost) {
                $this->stock->postCount($count->fresh());

                return redirect()->route('inventory.counts.show', $count)->with('success', 'Stocktake approved — differences booked to stock.');
            }
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Counts saved.');
    }

    public function post(StockCount $count)
    {
        try {
            $this->stock->postCount($count);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Stocktake approved — differences booked to stock.');
    }

    public function cancel(StockCount $count)
    {
        try {
            $this->stock->cancelCount($count);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.counts.index')->with('success', 'Stocktake cancelled. Nothing was changed.');
    }
}
