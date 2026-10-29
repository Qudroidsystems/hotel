<?php

namespace App\Http\Controllers\Events;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\Events\Event;
use App\Services\Events\HallUsageService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** Halls on the day (handing over and taking back) and the hall usage report. */
class HallUsageController extends Controller
{
    public function __construct(private HallUsageService $usage)
    {
        $this->middleware('permission:View events|Manage events', ['only' => ['report']]);
        $this->middleware('permission:Manage events', ['only' => ['checkIn', 'checkOut']]);
    }

    public function report(Request $request)
    {
        $from = $request->filled('from') ? Carbon::parse($request->query('from')) : today()->startOfMonth();
        $to = $request->filled('to') ? Carbon::parse($request->query('to')) : today()->endOfMonth();
        if ($to->lt($from) || $from->diffInDays($to) > 400) {
            $to = $from->copy()->endOfMonth();
        }

        return view('events.usage', ['pagetitle' => 'Hall Usage', 'report' => $this->usage->report($from, $to), 'today' => $this->usage->today()]);
    }

    public function checkIn(Request $request, Event $event)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:1000']);
        try {
            $this->usage->checkIn($event, $data['notes'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$event->hall->name} handed over.");
    }

    public function checkOut(Request $request, Event $event)
    {
        $data = $request->validate([
            'guests_actual'         => 'nullable|integer|min:0|max:20000',
            'hall_condition'        => ['required', Rule::in(array_keys(HallUsageService::CONDITIONS))],
            'usage_notes'           => 'nullable|string|max:1000',
            'charge_overtime'       => 'nullable|boolean',
            'damages'               => 'nullable|array|max:10',
            'damages.*.description' => 'nullable|string|max:150',
            'damages.*.amount'      => 'nullable|numeric|min:0|max:100000000',
        ]);
        try {
            $this->usage->checkOut($event, $data);
        } catch (BookingException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$event->hall->name} taken back." . ($event->fresh()->balance() > 0.004 ? ' Settle the bill, then mark the event as done.' : ''));
    }
}
