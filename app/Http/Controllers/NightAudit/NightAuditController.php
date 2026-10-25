<?php

namespace App\Http\Controllers\NightAudit;

use App\Exceptions\BookingException;
use App\Http\Controllers\Controller;
use App\Models\NightAudit;
use App\Services\NightAudit\NightAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Front Desk → Night Audit: close the business day and read past days' reports. */
class NightAuditController extends Controller
{
    public function __construct(private NightAuditService $audits)
    {
        $this->middleware('permission:Run night audit|View night audit reports', ['only' => ['index', 'show']]);
        $this->middleware('permission:Run night audit', ['only' => ['run']]);
    }

    public function index()
    {
        $date = NightAudit::businessDate();
        $canClose = $date->lte(today());
        $checks = $canClose ? $this->audits->checks($date) : null;
        $preview = $canClose ? $this->audits->report($date) : null;
        $history = NightAudit::with('closer')->latest('business_date')->paginate(20);
        $pagetitle = 'Night Audit';

        return view('night-audit.index', compact('pagetitle', 'date', 'canClose', 'checks', 'preview', 'history'));
    }

    public function run(Request $request)
    {
        $data = $request->validate([
            'date'       => 'required|date',
            'no_shows'   => 'nullable|array',
            'no_shows.*' => 'integer',
            'notes'      => 'nullable|string|max:1000',
        ]);
        try {
            $audit = $this->audits->close(Carbon::parse($data['date']), $data['no_shows'] ?? [], $data['notes'] ?? null);
        } catch (BookingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('night-audit.show', $audit)->with('success', $audit->business_date->format('D j M Y') . ' is closed. Here is the day’s report.');
    }

    public function show(NightAudit $audit)
    {
        $audit->load('closer');

        return view('night-audit.show', ['audit' => $audit, 'r' => $audit->report, 'pagetitle' => 'Daily report ' . $audit->business_date->format('j M Y')]);
    }
}
