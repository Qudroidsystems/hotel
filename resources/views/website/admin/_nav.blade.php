{{-- Website section tabs. $active: overview | settings | <kind> | enquiries | subscribers --}}
@php $newMessages = \App\Models\Website\WebsiteMessage::where('status', 'new')->count(); $canManage = auth()->user()->can('Manage website'); @endphp
<style>
    .ws-thumb { width: 64px; height: 44px; object-fit: cover; border-radius: 8px; background: var(--cb-surface-2); display: inline-grid; place-items: center; color: var(--cb-muted); font-size: 18px; flex-shrink: 0; }
    .ws-kinds { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 12px; }
    .ws-kind { display: flex; gap: 12px; align-items: center; padding: 14px 16px; border: 1.5px solid var(--cb-border); border-radius: 14px; background: var(--cb-surface); color: var(--cb-text); text-decoration: none; transition: border-color .2s, transform .2s; }
    .ws-kind:hover { border-color: var(--cb-teal); transform: translateY(-2px); color: var(--cb-text); }
    .ws-kind i { font-size: 22px; color: var(--cb-teal); width: 42px; height: 42px; border-radius: 12px; display: grid; place-items: center; background: color-mix(in srgb, var(--cb-teal) 12%, transparent); }
    .ws-kind b { display: block; color: var(--cb-heading); }
    .ws-kind small { color: var(--cb-muted); }
    .ws-field { padding: 14px 0; border-bottom: 1px solid var(--cb-border); }
    .ws-field:last-of-type { border-bottom: 0; }
    .ws-field > label { font-weight: 600; color: var(--cb-heading); margin-bottom: 6px; display: block; }
    .ws-help { font-size: 12.5px; color: var(--cb-muted); margin-top: 4px; }
    .ws-img { max-height: 120px; max-width: 100%; border-radius: 10px; border: 1px solid var(--cb-border); }
    .ws-theme { display: flex; gap: 10px; flex-wrap: wrap; }
    .ws-theme label { cursor: pointer; border: 2px solid var(--cb-border); border-radius: 12px; padding: 8px 12px 8px 8px; display: flex; gap: 10px; align-items: center; font-size: 13px; font-weight: 600; }
    .ws-theme input { display: none; }
    .ws-theme input:checked + label { border-color: var(--cb-teal); box-shadow: 0 0 0 3px color-mix(in srgb, var(--cb-teal) 18%, transparent); }
    .ws-theme .sw { width: 38px; height: 30px; border-radius: 8px; position: relative; overflow: hidden; }
    .ws-theme .sw::after { content: ''; position: absolute; right: 0; bottom: 0; width: 45%; height: 45%; background: var(--a); border-top-left-radius: 6px; }
    .ws-icons { display: grid; grid-template-columns: repeat(auto-fill, minmax(46px, 1fr)); gap: 6px; max-height: 220px; overflow-y: auto; padding: 2px; }
    .ws-icons input { display: none; }
    .ws-icons label { height: 46px; border: 1.5px solid var(--cb-border); border-radius: 10px; display: grid; place-items: center; font-size: 21px; cursor: pointer; color: var(--cb-text); }
    .ws-icons input:checked + label { border-color: var(--cb-teal); background: color-mix(in srgb, var(--cb-teal) 12%, transparent); color: var(--cb-teal); }
    .ws-move { display: inline-flex; flex-direction: column; gap: 2px; }
    .ws-move button { border: 0; background: none; padding: 0 4px; line-height: 1; color: var(--cb-muted); }
    .ws-move button:hover { color: var(--cb-heading); }
    .ws-hidden { opacity: .55; }
</style>
<div class="rs-tabs">
    <a href="{{ route('website.index') }}" class="rs-tab {{ $active === 'overview' ? 'active' : '' }}"><i class="ri-dashboard-line"></i> Overview</a>
    @if ($canManage)
        <a href="{{ route('website.settings') }}" class="rs-tab {{ $active === 'settings' ? 'active' : '' }}"><i class="ri-settings-3-line"></i> Settings & texts</a>
        @foreach (\App\Models\Website\WebsiteItem::KINDS as $k => $d)
            <a href="{{ route('website.content.index', $k) }}" class="rs-tab {{ $active === $k ? 'active' : '' }}"><i class="{{ $d[2] }}"></i> {{ $d[1] }}</a>
        @endforeach
    @endif
    <a href="{{ route('website.enquiries.index') }}" class="rs-tab {{ $active === 'enquiries' ? 'active' : '' }}"><i class="ri-inbox-line"></i> Enquiries @if ($newMessages)<span class="n">{{ $newMessages }}</span>@endif</a>
    <a href="{{ route('website.subscribers') }}" class="rs-tab {{ $active === 'subscribers' ? 'active' : '' }}"><i class="ri-mail-star-line"></i> Newsletter</a>
</div>
