<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\WebsiteMessage;
use App\Models\Website\WebsiteSubscriber;
use Illuminate\Http\Request;

/** Website → Enquiries (messages from the site's forms) and newsletter sign-ups. */
class WebsiteEnquiryController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:View website enquiries|Manage website');
    }

    public function index(Request $request)
    {
        $status = in_array($request->query('status'), ['new', 'replied', 'closed', 'all'], true) ? $request->query('status') : 'open';
        $kind = array_key_exists((string) $request->query('kind'), WebsiteMessage::KINDS) ? $request->query('kind') : null;
        $search = trim((string) $request->query('q'));

        $list = WebsiteMessage::with('handler:id,name')
            ->when($status === 'open', fn ($q) => $q->where('status', '!=', 'closed'))
            ->when(! in_array($status, ['open', 'all'], true), fn ($q) => $q->where('status', $status))
            ->when($kind, fn ($q) => $q->where('kind', $kind))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")->orWhere('subject', 'like', "%{$search}%")))
            ->orderByRaw("case when status = 'new' then 0 else 1 end")->latest()
            ->paginate(25)->withQueryString();
        $counts = WebsiteMessage::selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');
        $pagetitle = 'Website enquiries';

        return view('website.admin.enquiries', compact('list', 'status', 'kind', 'search', 'counts', 'pagetitle'));
    }

    public function show(WebsiteMessage $message)
    {
        if (! $message->read_at) {
            $message->forceFill(['read_at' => now()])->save();
        }
        $pagetitle = $message->kind_label . ' from ' . $message->name;

        return view('website.admin.enquiry', compact('message', 'pagetitle'));
    }

    public function update(Request $request, WebsiteMessage $message)
    {
        $data = $request->validate(['status' => 'required|in:new,replied,closed', 'note' => 'nullable|string|max:2000']);
        $message->fill($data);
        if ($data['status'] !== 'new') {
            $message->handled_by = $request->user()->id;
            $message->handled_at = now();
        }
        $message->save();

        return redirect()->route('website.enquiries.index')->with('success', 'Saved: ' . $message->name . '’s ' . strtolower($message->kind_label) . ' is ' . strtolower($message->status_label) . '.');
    }

    public function subscribers(Request $request)
    {
        $list = WebsiteSubscriber::whereNull('unsubscribed_at')->latest()->paginate(50);
        $pagetitle = 'Newsletter sign-ups';

        return view('website.admin.subscribers', compact('list', 'pagetitle'));
    }

    public function export()
    {
        $rows = WebsiteSubscriber::whereNull('unsubscribed_at')->orderBy('created_at')->get(['email', 'name', 'source', 'created_at']);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Email', 'Name', 'Signed up from', 'Date']);
            foreach ($rows as $r) {
                // A leading = + - @ would run as a formula in a spreadsheet.
                fputcsv($out, array_map(fn ($v) => preg_match('/^[=+\-@]/', (string) $v) ? "'" . $v : $v,
                    [$r->email, $r->name, $r->source, $r->created_at->format('Y-m-d')]));
            }
            fclose($out);
        }, 'newsletter-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    public function removeSubscriber(WebsiteSubscriber $subscriber)
    {
        $subscriber->update(['unsubscribed_at' => now()]);

        return back()->with('success', $subscriber->email . ' is taken off the list.');
    }
}
