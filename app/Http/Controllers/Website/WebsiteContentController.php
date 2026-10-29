<?php

namespace App\Http\Controllers\Website;

use App\Http\Controllers\Controller;
use App\Models\Website\WebsiteItem;
use App\Support\Audit;
use App\Support\Website;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Website → each kind of content (slides, dining, offers, gallery …): list, add, edit, order, hide. */
class WebsiteContentController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:Manage website');
    }

    public function index(Request $request, string $kind)
    {
        $def = $this->kind($kind);
        $items = WebsiteItem::ofKind($kind)->orderBy('sort_order')->orderByDesc('published_at')->orderBy('id')->get();
        $pagetitle = $def[1];

        return view('website.admin.content', compact('kind', 'def', 'items', 'pagetitle'));
    }

    public function create(string $kind)
    {
        $def = $this->kind($kind);
        $item = new WebsiteItem(['kind' => $kind, 'is_active' => true, 'published_at' => $kind === 'post' ? now() : null, 'rating' => 5]);
        $pagetitle = 'New ' . strtolower($def[0]);

        return view('website.admin.form', compact('kind', 'def', 'item', 'pagetitle'));
    }

    public function store(Request $request, string $kind)
    {
        $this->kind($kind);
        $item = new WebsiteItem(['kind' => $kind, 'created_by' => $request->user()->id]);
        $item->sort_order = (int) WebsiteItem::ofKind($kind)->max('sort_order') + 1;
        $this->fill($request, $item);
        $item->save();
        Audit::record('website.content_added', 'Added ' . strtolower($item->kind_label) . ' "' . $item->title . '" to the website', $item);

        return redirect()->route('website.content.index', $kind)->with('success', '"' . $item->title . '" is saved' . ($item->is_active ? ' and on the website.' : ' (hidden).'));
    }

    public function edit(string $kind, WebsiteItem $item)
    {
        $def = $this->kind($kind, $item);
        $pagetitle = 'Edit ' . strtolower($def[0]);

        return view('website.admin.form', compact('kind', 'def', 'item', 'pagetitle'));
    }

    public function update(Request $request, string $kind, WebsiteItem $item)
    {
        $this->kind($kind, $item);
        $old = $item->only(['title', 'is_active', 'promo_code', 'starts_on', 'ends_on']);
        $this->fill($request, $item);
        $item->save();
        Audit::record('website.content_changed', 'Changed ' . strtolower($item->kind_label) . ' "' . $item->title . '" on the website', $item, $old, $item->only(array_keys($old)));

        return redirect()->route('website.content.index', $kind)->with('success', '"' . $item->title . '" is saved.');
    }

    public function destroy(string $kind, WebsiteItem $item)
    {
        $this->kind($kind, $item);
        $item->delete();
        Audit::record('website.content_removed', 'Removed ' . strtolower($item->kind_label) . ' "' . $item->title . '" from the website', null, ['title' => $item->title]);

        return redirect()->route('website.content.index', $kind)->with('success', '"' . $item->title . '" is removed.');
    }

    /** Show / hide without opening the form. */
    public function toggle(string $kind, WebsiteItem $item)
    {
        $this->kind($kind, $item);
        $item->update(['is_active' => ! $item->is_active]);

        return back()->with('success', '"' . $item->title . '" is ' . ($item->is_active ? 'on the website.' : 'hidden.'));
    }

    /** Up or down one place in the list. */
    public function move(Request $request, string $kind, WebsiteItem $item)
    {
        $this->kind($kind, $item);
        $list = WebsiteItem::ofKind($kind)->orderBy('sort_order')->orderByDesc('published_at')->orderBy('id')->get()->values();
        $at = $list->search(fn ($i) => $i->id === $item->id);
        $to = $request->input('dir') === 'up' ? $at - 1 : $at + 1;
        if ($at !== false && $to >= 0 && $to < $list->count()) {
            [$list[$at], $list[$to]] = [$list[$to], $list[$at]];
            foreach ($list as $i => $row) {
                if ($row->sort_order !== $i + 1) {
                    $row->forceFill(['sort_order' => $i + 1])->saveQuietly();
                }
            }
        }

        return back();
    }

    private function fill(Request $request, WebsiteItem $item): void
    {
        $kind = $item->kind;
        $uses = fn (string $f) => WebsiteItem::uses($kind, $f);
        $needsImage = in_array($kind, ['slide', 'gallery'], true) && ! $item->image;

        $data = $request->validate([
            'title'        => ['required', 'string', 'max:160'],
            'slug'         => ['nullable', 'string', 'max:180', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('website_items', 'slug')->where('kind', $kind)->where('hotel_id', \App\Support\Tenancy\CurrentHotel::get()->id())->ignore($item->id)],
            'subtitle'     => ['nullable', 'string', 'max:200'],
            'category'     => ['nullable', 'string', 'max:60'],
            'summary'      => ['nullable', 'string', 'max:600'],
            'body'         => ['nullable', 'string', 'max:20000'],
            'image'        => [$needsImage ? 'required' : 'nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:6144'],
            'icon'         => ['nullable', Rule::in(array_keys(WebsiteItem::ICONS))],
            'meta'         => ['nullable', 'string', 'max:160'],
            'link_label'   => ['nullable', 'string', 'max:60', 'required_with:link_url'],
            'link_url'     => ['nullable', 'string', 'max:255', 'regex:#^(https?://|/|\#|mailto:|tel:)#i'],
            'promo_code'   => ['nullable', 'string', 'max:30', 'regex:/^[A-Za-z0-9_-]+$/'],
            'starts_on'    => ['nullable', 'date'],
            'ends_on'      => ['nullable', 'date', 'after_or_equal:starts_on'],
            'rating'       => ['nullable', 'integer', 'min:1', 'max:5'],
            'published_at' => ['nullable', 'date'],
        ], [
            'link_url.regex' => 'Start the link with https://, or / for a page of this website.',
            'image.required' => 'Choose a photo.',
            'link_label.required_with' => 'Give the button some words, e.g. "See the menu".',
        ], ['link_url' => 'button link', 'link_label' => 'button words']);

        $item->title = $data['title'];
        foreach (['subtitle', 'category', 'summary', 'body', 'icon', 'meta', 'rating'] as $f) {
            $item->{$f} = $uses($f) ? ($data[$f] ?? null) : null;
        }
        $item->link_label = $uses('link') ? ($data['link_label'] ?? null) : null;
        $item->link_url = $uses('link') ? ($data['link_url'] ?? null) : null;
        $item->promo_code = $uses('promo_code') && filled($data['promo_code'] ?? null) ? strtoupper($data['promo_code']) : null;
        $item->starts_on = $uses('dates') ? ($data['starts_on'] ?? null) : null;
        $item->ends_on = $uses('dates') ? ($data['ends_on'] ?? null) : null;
        $item->published_at = $uses('published_at') ? ($data['published_at'] ?? now()) : null;
        if (WebsiteItem::hasPage($kind)) {
            $item->slug = $data['slug'] ?? ($item->exists && $item->isDirty('title') ? null : $item->slug);
        }
        $item->is_active = $request->boolean('is_active');
        $item->is_featured = $request->boolean('is_featured');

        if ($uses('image')) {
            if ($request->hasFile('image')) {
                Website::deleteUpload($item->getOriginal('image'));
                $item->image = Website::storeUpload($request->file('image'), $kind . '-' . $item->title);
            } elseif ($request->boolean('image_remove') && ! in_array($kind, ['slide', 'gallery'], true)) {
                Website::deleteUpload($item->image);
                $item->image = null;
            }
        }
    }

    private function kind(string $kind, ?WebsiteItem $item = null): array
    {
        abort_unless(isset(WebsiteItem::KINDS[$kind]), 404);
        abort_if($item && $item->kind !== $kind, 404);

        return WebsiteItem::KINDS[$kind];
    }
}
