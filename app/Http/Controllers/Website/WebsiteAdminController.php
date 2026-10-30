<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\WebsiteItem;
use App\Models\Website\WebsiteMessage;
use App\Models\Website\WebsiteSubscriber;
use App\Support\Audit;
use App\Support\Website;
use Illuminate\Http\Request;

/** Website in the sidebar: what is on the public site, and its settings and texts. */
class WebsiteAdminController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage website')->except('index');
        $this->middleware('permission:Manage website|View website enquiries')->only('index');
    }

    public function index()
    {
        $counts = WebsiteItem::selectRaw('kind, count(*) as n, sum(case when is_active = 1 then 1 else 0 end) as live')->groupBy('kind')->get()->keyBy('kind');
        $stats = [
            'new'         => WebsiteMessage::where('status', 'new')->count(),
            'week'        => WebsiteMessage::where('created_at', '>=', now()->subDays(7))->count(),
            'subscribers' => WebsiteSubscriber::whereNull('unsubscribed_at')->count(),
            'live'        => WebsiteItem::live()->count(),
        ];
        $latest = WebsiteMessage::latest()->limit(6)->get();
        $pagetitle = 'Website';

        return view('website.admin.index', compact('counts', 'stats', 'latest', 'pagetitle'));
    }

    public function settings(Request $request, ?string $group = null)
    {
        $group = array_key_exists((string) $group, Website::FIELDS) ? $group : 'general';
        $pagetitle = 'Website settings';

        return view('website.admin.settings', ['group' => $group, 'groups' => Website::FIELDS, 'pagetitle' => $pagetitle]);
    }

    public function saveSettings(Request $request, string $group)
    {
        abort_unless(isset(Website::FIELDS[$group]), 404);
        [, , $fields] = Website::FIELDS[$group];

        $rules = [];
        foreach ($fields as $key => $f) {
            $name = self::input($key);
            $rules[$name] = match ($f['type']) {
                'bool'     => ['nullable', 'boolean'],
                'image'    => ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
                'url'      => ['nullable', 'url:http,https', 'max:255'],
                'textarea' => ['nullable', 'string', 'max:4000'],
                'list'     => ['nullable', 'string', 'max:2000'],
                'select'   => ['nullable', 'in:' . implode(',', array_keys(Website::THEMES))],
                // An Android app. Its contents are a zip, so the extension is what tells an .apk apart.
                'file'     => ['nullable', 'file', 'max:409600', function ($attr, $v, $fail) {
                    if ($v instanceof \Illuminate\Http\UploadedFile && strtolower($v->getClientOriginalExtension()) !== 'apk') {
                        $fail('Choose the Android app file, ending in .apk.');
                    }
                }],
                default    => $key === 'notify_email' ? ['nullable', 'email', 'max:150'] : ['nullable', 'string', 'max:255'],
            };
        }
        $request->validate($rules, [], collect($fields)->mapWithKeys(fn ($f, $k) => [self::input($k) => $f['label']])->all());

        $before = Website::all();
        $values = [];
        foreach ($fields as $key => $f) {
            $name = self::input($key);
            $values[$key] = match ($f['type']) {
                'bool'  => $request->boolean($name),
                'list'  => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $request->input($name))))),
                'image' => $this->image($request, $name, $key, $before[$key] ?? null),
                'file'  => $this->appFile($request, $name, $key, $before[$key] ?? null),
                default => ($v = trim((string) $request->input($name))) === '' ? null : $v,
            };
        }
        Website::save($values, $request->user()->id);

        $changed = array_keys(array_filter($values, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
        if ($changed) {
            Audit::record('website.settings', 'Changed the website\'s ' . strtolower(Website::FIELDS[$group][0]) . ' (' . count($changed) . ' field' . (count($changed) === 1 ? '' : 's') . ')', null,
                array_intersect_key($before, array_flip($changed)), array_intersect_key($values, array_flip($changed)));
        }

        return redirect()->route('website.settings', $group)->with('success', 'Saved. The website shows the change straight away.');
    }

    /** "home.intro_title" → "home__intro_title" (dots don't survive form field names). */
    public static function input(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    private function appFile(Request $request, string $name, string $key, mixed $old): ?array
    {
        if ($request->boolean($name . '__remove')) {
            Website::deleteApp($old);

            return null;
        }
        if ($request->hasFile($name)) {
            Website::deleteApp($old);

            return Website::storeApp($request->file($name), explode('.', $key)[1] ?? 'app');
        }

        return is_array($old) ? $old : null;
    }

    private function image(Request $request, string $name, string $key, ?string $old): ?string
    {
        if ($request->boolean($name . '__remove')) {
            Website::deleteUpload($old);

            return null;
        }
        if ($request->hasFile($name)) {
            Website::deleteUpload($old);

            return Website::storeUpload($request->file($name), str_replace('.', '-', $key));
        }

        return $old;
    }
}
