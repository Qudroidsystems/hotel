<?php

namespace App\Http\Controllers\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\ChatSection;
use App\Models\User;
use App\Services\Chat\ChatService;
use App\Services\Realtime\Realtime;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Guest Chat on the website: the inbox (it also rings for calls), and setting up the sections guests can reach. */
class ChatPageController extends Controller
{
    public function __construct(private ChatService $chat)
    {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->canAny(['Chat with guests', 'Manage guest chat']), 403);
        $sections = $this->chat->sectionsFor($request->user());

        return view('chat.index', [
            'pagetitle' => 'Guest Chat',
            'sections' => $sections,
            'live' => app(Realtime::class)->clientConfig(),
            'channels' => array_merge($sections->map(fn ($s) => Realtime::section($s->id))->all(), [Realtime::staff($request->user()->id)]),
        ]);
    }

    public function sections(Request $request)
    {
        abort_unless($request->user()->can('Manage guest chat'), 403);
        $sections = $this->chat->sections(false);
        $edit = $request->filled('edit') ? $sections->firstWhere('id', (int) $request->query('edit')) : null;

        return view('chat.sections', [
            'pagetitle' => 'Chat Sections',
            'sections' => $sections,
            'edit' => $edit,
            'creating' => $request->boolean('new'),
            'roles' => Role::whereNot('name', 'Super Admin')->orderBy('name')->pluck('name'),
            'users' => User::permission(['Chat with guests', 'Manage guest chat'])->where('is_disabled', false)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function saveSection(Request $request, ?ChatSection $section = null)
    {
        abort_unless($request->user()->can('Manage guest chat'), 403);
        $d = $request->validate([
            'name' => 'required|string|max:60', 'description' => 'nullable|string|max:160', 'icon' => ['nullable', 'string', 'max:40', 'regex:/^ri-[a-z0-9-]+$/'],
            'phone' => 'nullable|string|max:40', 'opens' => 'nullable|date_format:H:i|required_with:closes', 'closes' => 'nullable|date_format:H:i|required_with:opens',
            'away_message' => 'nullable|string|max:255', 'roles' => 'nullable|array', 'roles.*' => 'string|max:60', 'users' => 'nullable|array', 'users.*' => 'integer',
            'sort_order' => 'nullable|integer|min:0|max:999',
        ]);
        $d['calls'] = $request->boolean('calls');
        $d['is_active'] = $request->boolean('is_active', true);
        $d['roles'] = array_values(array_filter($d['roles'] ?? []));
        $d['users'] = array_map('intval', array_values(array_filter($d['users'] ?? [])));
        $d['icon'] = $d['icon'] ?: 'ri-chat-3-line';
        $d['sort_order'] = (int) ($d['sort_order'] ?? 0);
        if ($section?->exists) {
            $section->update($d);
        } else {
            $d['key'] = \Illuminate\Support\Str::slug($d['name']) . '-' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
            $section = ChatSection::create($d);
        }
        Audit::record('chat.section', "Chat section saved: {$section->name}", $section);

        return redirect()->route('chat.sections')->with('success', "{$section->name} saved.");
    }
}
