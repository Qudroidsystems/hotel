<?php

namespace App\Http\Controllers\Rates;

use App\Http\Controllers\Controller;
use App\Services\Rates\RevenueService;
use Illuminate\Http\Request;

/** Rates → Revenue: what's on the books night by night, pickup, and suggested prices. */
class RevenueController extends Controller
{
    public function __construct(private RevenueService $revenue)
    {
        $this->middleware('permission:Manage rates');
    }

    public function index(Request $request)
    {
        $days = in_array((int) $request->query('days'), [14, 30, 60, 90], true) ? (int) $request->query('days') : 30;
        $grid = $this->revenue->grid($days);
        $pickup = $this->revenue->pickup($grid);
        $pagetitle = 'Revenue';

        return view('rates.revenue', compact('pagetitle', 'grid', 'pickup', 'days'));
    }

    public function apply(Request $request)
    {
        $data = $request->validate(['cells' => 'nullable|array', 'cells.*' => ['string', 'regex:/^\d+\|\d{4}-\d{2}-\d{2}$/'], 'all' => 'nullable|boolean']);
        $n = $this->revenue->apply($request->boolean('all') ? null : ($data['cells'] ?? []), $request->user()->id);

        return back()->with($n ? 'success' : 'error', $n ? "{$n} night price" . ($n === 1 ? '' : 's') . ' set from the suggestions.' : 'Nothing to change: tick the nights to reprice first.');
    }
}
