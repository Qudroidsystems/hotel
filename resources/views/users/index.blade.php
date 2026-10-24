@extends('layouts.master')

@section('content')
<?php use Spatie\Permission\Models\Role; ?>

{{-- ═══════════════════════════════════════════════════════════
     STYLES
═══════════════════════════════════════════════════════════ --}}
<style>
@import url('{{ asset('vendor/fonts/fonts.css') }}');

:root {
    --u-primary:    #1e3a5f;
    --u-accent:     #2563eb;
    --u-indigo:     #4f46e5;
    --u-success:    #16a34a;
    --u-warning:    #d97706;
    --u-danger:     #dc2626;
    --u-muted:      #6b7280;
    --u-border:     #e2e8f0;
    --u-bg:         #f8fafc;
    --u-surface:    #ffffff;
    --u-radius:     12px;
    --u-shadow:     0 2px 8px rgba(0,0,0,.07);
    --u-shadow-lg:  0 8px 32px rgba(0,0,0,.12);
}

*, *::before, *::after { box-sizing: border-box; }
body { font-family: 'Plus Jakarta Sans', sans-serif; }

/* ── Keyframes ── */
@keyframes fadeInUp   { from { opacity:0; transform:translateY(18px); } to { opacity:1; transform:translateY(0); } }
@keyframes fadeInDown { from { opacity:0; transform:translateY(-14px);} to { opacity:1; transform:translateY(0); } }
@keyframes slideRight { from { opacity:0; transform:translateX(-20px);} to { opacity:1; transform:translateX(0); } }
@keyframes scaleIn    { from { opacity:0; transform:scale(.92);       } to { opacity:1; transform:scale(1);    } }
@keyframes pulse      { 0%,100%{transform:scale(1);}50%{transform:scale(1.05);} }
@keyframes shimmer    { from{background-position:-200% 0;} to{background-position:200% 0;} }
@keyframes rowIn      { from{opacity:0;transform:translateX(-8px);}to{opacity:1;transform:translateX(0);} }
@keyframes badgePop   { 0%{transform:scale(0.5);}70%{transform:scale(1.15);}100%{transform:scale(1);} }

/* ── Hero ── */
.u-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 55%, #4f46e5 100%);
    border-radius: var(--u-radius);
    padding: 28px 32px;
    margin-bottom: 24px;
    position: relative;
    overflow: hidden;
    animation: fadeInDown .5s ease both;
}
.u-hero::before {
    content:''; position:absolute; top:-70px; right:-70px;
    width:240px; height:240px;
    background:rgba(255,255,255,.06); border-radius:50%;
    animation: pulse 6s ease-in-out infinite;
}
.u-hero::after {
    content:''; position:absolute; bottom:-50px; right:140px;
    width:150px; height:150px;
    background:rgba(255,255,255,.04); border-radius:50%;
}
.u-hero h1 { font-size:22px; font-weight:800; color:#fff; margin:0 0 6px; position:relative; letter-spacing:-.3px; }
.u-hero p  { font-size:13px; color:rgba(255,255,255,.75); margin:0; position:relative; }

/* ── Stat cards ── */
.stat-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    padding: 18px 20px;
    transition: transform .2s, box-shadow .2s;
    animation: fadeInUp .5s ease both;
    position: relative;
    overflow: hidden;
}
.stat-card::before {
    content:''; position:absolute; bottom:0; left:0; right:0; height:3px;
    border-radius:0 0 var(--u-radius) var(--u-radius);
    background: linear-gradient(90deg, var(--u-accent), var(--u-indigo));
    transform: scaleX(0); transform-origin: left;
    transition: transform .3s ease;
}
.stat-card:hover { transform:translateY(-3px); box-shadow:var(--u-shadow-lg); }
.stat-card:hover::before { transform: scaleX(1); }
.stat-card .stat-value { font-size:28px; font-weight:800; color:var(--u-primary); line-height:1; }
.stat-card .stat-label { font-size:12px; color:var(--u-muted); margin-top:5px; font-weight:500; }
.stat-card .stat-icon  { font-size:34px; opacity:.1; float:right; margin-top:-6px; }

/* ── Filter area ── */
.u-filter-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-top: 3px solid var(--u-accent);
    border-radius: var(--u-radius);
    padding: 16px 20px;
    animation: fadeInUp .5s .1s ease both;
}
.u-input {
    border: 1.5px solid var(--u-border);
    border-radius: 9px;
    padding: 9px 14px;
    font-size: 13px;
    font-family: inherit;
    transition: border-color .2s, box-shadow .2s;
    width: 100%;
    background: #fff;
}
.u-input:focus { border-color:var(--u-accent); outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.1); }
.u-input-icon-wrap { position:relative; }
.u-input-icon { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:#94a3b8; font-size:13px; pointer-events:none; }
.u-input-icon-wrap .u-input { padding-left: 34px; }

/* ── Table card ── */
.u-table-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    overflow: hidden;
    box-shadow: var(--u-shadow);
    animation: fadeInUp .5s .15s ease both;
}
.u-table-card .card-header {
    background: var(--u-surface);
    border-bottom: 1px solid var(--u-border);
    padding: 14px 20px;
}
.u-table thead th {
    background: var(--u-primary);
    color: #fff;
    padding: 12px 16px;
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .5px;
    white-space: nowrap;
    border: none;
}
.u-table tbody td {
    padding: 11px 16px;
    vertical-align: middle;
    border-bottom: 1px solid var(--u-border);
    font-size: 13px;
    transition: background .12s;
}
.u-table tbody tr {
    animation: rowIn .3s ease both;
}
.u-table tbody tr:hover td { background: #f0f9ff; }
.u-table tbody tr:last-child td { border-bottom: none; }

/* ── Avatar ── */
.u-avatar {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 14px; font-weight: 700; color: #fff;
    border: 2px solid var(--u-border);
    flex-shrink: 0;
    transition: transform .2s;
}
.u-avatar:hover { transform: scale(1.1); }

/* ── Role badges ── */
.u-role-pill {
    display: inline-flex; align-items: center; gap: 4px;
    padding: 3px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 600;
    animation: badgePop .3s ease;
}
.u-role-pill.blue     { background:#dbeafe; color:#1e40af; border:1px solid #bfdbfe; }
.u-role-pill.pink     { background:#fce7f3; color:#9d174d; border:1px solid #fbcfe8; }
.u-role-pill.green    { background:#d1fae5; color:#065f46; border:1px solid #a7f3d0; }
.u-role-pill.amber    { background:#fef3c7; color:#92400e; border:1px solid #fde68a; }
.u-role-pill.default  { background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; }

/* ── Action buttons ── */
.u-action-btn {
    width: 30px; height: 30px;
    border-radius: 7px;
    display: inline-flex; align-items: center; justify-content: center;
    border: none; cursor: pointer;
    font-size: 13px;
    transition: transform .15s, box-shadow .15s;
    text-decoration: none;
}
.u-action-btn:hover { transform: translateY(-1px); box-shadow: 0 3px 10px rgba(0,0,0,.15); }
.u-action-btn.view   { background:#eff6ff; color:#2563eb; }
.u-action-btn.edit   { background:#f0fdf4; color:#16a34a; }
.u-action-btn.key    { background:#fffbeb; color:#d97706; }
.u-action-btn.del    { background:#fef2f2; color:#dc2626; }

/* ── Top buttons ── */
.u-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 9px;
    font-size: 13px; font-weight: 600;
    border: none; cursor: pointer;
    transition: transform .15s, box-shadow .15s, opacity .15s;
    text-decoration: none;
}
.u-btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,.15); opacity: .92; }
.u-btn.primary  { background:linear-gradient(135deg,var(--u-accent),var(--u-indigo)); color:#fff; }
.u-btn.success  { background:linear-gradient(135deg,#16a34a,#15803d); color:#fff; }
.u-btn.warning  { background:linear-gradient(135deg,#d97706,#b45309); color:#fff; }
.u-btn.danger   { background:var(--u-danger); color:#fff; }
.u-btn.ghost    { background:#fff; color:var(--u-primary); border:1.5px solid var(--u-border); }

/* ── Modal redesign ── */
.u-modal .modal-content {
    border: none;
    border-radius: 18px;
    overflow: hidden;
    box-shadow: var(--u-shadow-lg);
    animation: scaleIn .25s ease;
}
.u-modal-hero {
    background: linear-gradient(135deg, #1e3a5f 0%, #2563eb 60%, #4f46e5 100%);
    padding: 22px 28px;
    position: relative; overflow: hidden;
}
.u-modal-hero::before {
    content:''; position:absolute; top:-40px; right:-40px;
    width:140px; height:140px;
    background:rgba(255,255,255,.07); border-radius:50%;
}
.u-modal-hero h5 { color:#fff; font-weight:700; font-size:16px; margin:0; position:relative; }
.u-modal-hero p  { color:rgba(255,255,255,.72); font-size:12px; margin:4px 0 0; position:relative; }
.u-modal-hero .btn-close { position:absolute; top:18px; right:20px; filter:invert(1); opacity:.8; }
.u-modal-body { padding: 22px 24px; background: var(--u-bg); }
.u-modal-footer { padding: 14px 24px; background: var(--u-surface); border-top:1px solid var(--u-border); }

.u-form-label {
    font-size: 11.5px; font-weight: 700; color: var(--u-muted);
    text-transform: uppercase; letter-spacing: .4px;
    display: block; margin-bottom: 5px;
}
.u-form-input {
    width: 100%;
    border: 1.5px solid var(--u-border);
    border-radius: 9px;
    padding: 10px 14px;
    font-size: 13px;
    font-family: inherit;
    background: #fff;
    transition: border-color .2s, box-shadow .2s;
}
.u-form-input:focus { border-color: var(--u-accent); outline:none; box-shadow:0 0 0 3px rgba(37,99,235,.1); }

/* ── Empty state ── */
.u-empty {
    text-align:center; padding:48px 24px; color: var(--u-muted);
}
.u-empty i { font-size:3rem; display:block; margin-bottom:12px; opacity:.3; }

/* ── Loading shimmer ── */
.shimmer {
    background: linear-gradient(90deg,#f0f0f0 25%,#e0e0e0 50%,#f0f0f0 75%);
    background-size: 200% 100%;
    animation: shimmer 1.5s infinite;
    border-radius: 6px;
    height: 16px;
}

/* ── Chart card ── */
.u-chart-card {
    background: var(--u-surface);
    border: 1px solid var(--u-border);
    border-radius: var(--u-radius);
    overflow: hidden;
    box-shadow: var(--u-shadow);
    animation: fadeInUp .5s .05s ease both;
    margin-bottom: 24px;
}
.u-chart-card .chart-header {
    padding: 14px 20px;
    background: var(--u-surface);
    border-bottom: 1px solid var(--u-border);
    font-weight: 700; font-size: 14px; color: var(--u-primary);
    display: flex; align-items: center; gap: 8px;
}

/* ── Stagger animation for rows ── */
.u-table tbody tr:nth-child(1)  { animation-delay: .03s; }
.u-table tbody tr:nth-child(2)  { animation-delay: .06s; }
.u-table tbody tr:nth-child(3)  { animation-delay: .09s; }
.u-table tbody tr:nth-child(4)  { animation-delay: .12s; }
.u-table tbody tr:nth-child(5)  { animation-delay: .15s; }
.u-table tbody tr:nth-child(6)  { animation-delay: .18s; }
.u-table tbody tr:nth-child(7)  { animation-delay: .21s; }
.u-table tbody tr:nth-child(8)  { animation-delay: .24s; }
.u-table tbody tr:nth-child(9)  { animation-delay: .27s; }
.u-table tbody tr:nth-child(10) { animation-delay: .30s; }

/* ── Pagination ── */
.u-pagination {
    display: flex; align-items: center; gap: 4px; flex-wrap: wrap;
}
.u-page-btn {
    min-width: 32px; height: 32px;
    display: inline-flex; align-items: center; justify-content: center;
    border-radius: 7px; border: 1.5px solid var(--u-border);
    background: #fff; color: var(--u-primary);
    font-size: 12px; font-weight: 600; cursor: pointer;
    transition: all .15s;
    text-decoration: none;
}
.u-page-btn:hover, .u-page-btn.active { background: var(--u-accent); color:#fff; border-color:var(--u-accent); }

</style>

<div class="main-content">
<div class="page-content">
<div class="container-fluid">

    {{-- Breadcrumb --}}
    <div class="row mb-1" style="animation:fadeInDown .4s ease both;">
        <div class="col-12">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb m-0 small">
                    <li class="breadcrumb-item"><a href="#" style="color:var(--u-accent)">User Management</a></li>
                    <li class="breadcrumb-item active text-muted">Users</li>
                </ol>
            </nav>
        </div>
    </div>

    {{-- Hero --}}
    <div class="u-hero">
        <div class="row align-items-center">
            <div class="col">
                <h1><i class="ri-team-line me-2"></i>User Management</h1>
                <p>Manage hotel staff accounts and the roles that control what each person can do.</p>
            </div>
            <div class="col-auto d-none d-md-flex gap-2">
                @can('Create user')
                <button type="button" class="u-btn primary" id="openAddUserModalBtn">
                    <i class="bi bi-plus-circle"></i> Add User
                </button>
                @endcan
            </div>
        </div>
    </div>

    {{-- Alerts --}}
    @if ($errors->any())
    <div class="alert alert-danger border-0 rounded-3 mb-3" style="animation:fadeInDown .4s ease both;">
        <strong>Input errors:</strong> {{ $errors->all()[0] }}
    </div>
    @endif
    @if (session('success'))
    <div class="alert alert-success border-0 rounded-3 mb-3" style="animation:fadeInDown .4s ease both;">
        <i class="bi bi-check-circle me-2"></i>{{ session('success') }}
    </div>
    @endif

    {{-- Stat Cards --}}
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3" style="animation-delay:.05s">
            <div class="stat-card">
                <div class="stat-icon"><i class="ri-group-line"></i></div>
                <div class="stat-value">{{ $data->count() }}</div>
                <div class="stat-label">Total Users</div>
            </div>
        </div>
        @foreach(array_slice($role_counts, 0, 3, true) as $role => $count)
        <div class="col-6 col-md-3" style="animation-delay:{{ .08 + $loop->index * .03 }}s">
            <div class="stat-card">
                <div class="stat-icon"><i class="ri-shield-user-line"></i></div>
                <div class="stat-value" style="color:var(--u-accent)">{{ $count }}</div>
                <div class="stat-label">{{ $role }}</div>
            </div>
        </div>
        @endforeach
    </div>

    {{-- Chart --}}
    <div class="u-chart-card">
        <div class="chart-header">
            <i class="ri-bar-chart-2-line" style="color:var(--u-accent)"></i>
            Users by Role
        </div>
        <div class="p-4">
            <canvas id="usersByRoleChart" height="80"></canvas>
        </div>
    </div>

    {{-- Filter Bar --}}
    <div class="u-filter-card mb-3">
        <div class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="u-form-label">Search</label>
                <div class="u-input-icon-wrap">
                    <i class="bi bi-search u-input-icon"></i>
                    <input type="text" id="liveSearch" class="u-input" placeholder="Name, email…">
                </div>
            </div>
            <div class="col-md-3">
                <label class="u-form-label">Role</label>
                <select id="roleFilter" class="u-input">
                    <option value="">All Roles</option>
                    @foreach ($roles as $role => $name)
                    <option value="{{ strtolower($name) }}">{{ $name }}</option>
                    @endforeach
                    <option value="no role">No Role</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="u-form-label">Email</label>
                <select id="emailFilter" class="u-input">
                    <option value="">All Emails</option>
                    @foreach ($data as $user)
                    <option value="{{ strtolower($user->email) }}">{{ $user->email }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="button" class="u-btn ghost w-100" id="clearFilters">
                    <i class="bi bi-x-circle"></i> Clear
                </button>
            </div>
        </div>
    </div>

    {{-- Table Card --}}
    <div class="u-table-card">
        <div class="card-header d-flex align-items-center justify-content-between">
            <div class="fw-bold" style="color:var(--u-primary);font-size:14px;">
                <i class="ri-list-check me-2" style="color:var(--u-accent)"></i>
                Users
                <span id="userCountBadge" class="badge ms-2" style="background:var(--u-accent);font-size:11px;font-weight:600;">{{ $data->count() }}</span>
            </div>
            <div class="d-flex gap-2 align-items-center">
                <button class="u-btn danger d-none" id="remove-actions" onclick="deleteMultiple()">
                    <i class="ri-delete-bin-2-line"></i> Delete Selected
                </button>
                @can('Create user')
                <div class="d-md-none d-flex gap-2">
                    <button type="button" class="u-btn primary" id="openAddUserModalBtnMobile">
                        <i class="bi bi-plus-circle"></i>
                    </button>
                </div>
                @endcan
            </div>
        </div>

        <div class="table-responsive">
            <table class="table u-table mb-0" id="usersTable">
                <thead>
                    <tr>
                        <th width="40">
                            <input type="checkbox" id="checkAll" style="accent-color:#fff;cursor:pointer;">
                        </th>
                        <th width="50"></th>
                        <th class="sortable" data-col="0">Name <i class="bi bi-chevron-expand ms-1 opacity-50"></i></th>
                        <th class="sortable" data-col="1">Email <i class="bi bi-chevron-expand ms-1 opacity-50"></i></th>
                        <th>Role</th>
                        <th class="sortable" data-col="4">Registered <i class="bi bi-chevron-expand ms-1 opacity-50"></i></th>
                        <th width="130">Actions</th>
                    </tr>
                </thead>
                <tbody id="usersTableBody">
                    @forelse ($data as $key => $user)
                    @php
                        $initials = strtoupper(substr($user->name,0,1) . (strpos($user->name,' ')!==false ? substr($user->name, strpos($user->name,' ')+1, 1) : ''));
                        $roleNames = $user->getRoleNames();
                        $avatarColors = ['#667eea','#f093fb','#4facfe','#43e97b','#fa709a','#30cfd0'];
                        $avatarColor = $avatarColors[$key % count($avatarColors)];
                    @endphp
                    <tr data-id="{{ $user->id }}"
                        data-name="{{ strtolower($user->name) }}"
                        data-email="{{ strtolower($user->email) }}"
                        data-roles="{{ strtolower($roleNames->implode(',')) }}"
                        data-date="{{ $user->created_at->format('Y-m-d') }}">
                        <td>
                            <input type="checkbox" class="row-check" name="chk_child" style="accent-color:var(--u-accent);cursor:pointer;">
                        </td>
                        <td>
                            <div class="u-avatar" style="background:linear-gradient(135deg,{{ $avatarColor }} 0%,{{ $avatarColors[($key+2)%count($avatarColors)] }} 100%)">
                                {{ $initials ?: 'U' }}
                            </div>
                        </td>
                        <td>
                            <div class="fw-semibold" style="color:var(--u-primary)">
                                <a href="{{ route('users.show', $user->id) }}" style="color:inherit;text-decoration:none;">
                                    {{ $user->name }}
                                </a>
                            </div>
                        </td>
                        <td>
                            <span class="text-muted small">{{ $user->email }}</span>
                        </td>
                        <td>
                            @if($roleNames->isNotEmpty())
                                @foreach($roleNames as $role)
                                    @php $cls = \App\Models\User::rolePillClass($role); @endphp
                                    <span class="u-role-pill {{ $cls }}">
                                        <i class="bi bi-shield-check"></i>{{ $role }}
                                    </span>
                                @endforeach
                            @else
                                <span class="u-role-pill default"><i class="bi bi-dash"></i>No Role</span>
                            @endif
                        </td>
                        <td class="text-muted small">{{ $user->created_at->format('d M Y') }}</td>
                        <td>
                            <div class="d-flex gap-1">
                                @can('View user')
                                <a href="{{ route('users.show', $user->id) }}" class="u-action-btn view" title="View">
                                    <i class="ph-eye"></i>
                                </a>
                                @endcan
                                @can('Update user')
                                <button type="button" class="u-action-btn edit edit-item-btn" title="Edit"
                                    data-id="{{ $user->id }}"
                                    data-name="{{ $user->name }}"
                                    data-email="{{ $user->email }}"
                                    data-phone="{{ $user->phone_number }}"
                                    data-roles="{{ $user->getRoleNames()->implode(',') }}">
                                    <i class="ph-pencil"></i>
                                </button>
                                @endcan
                                @can('Delete user')
                                <button type="button" class="u-action-btn del remove-item-btn" title="Delete"
                                    data-id="{{ $user->id }}">
                                    <i class="ph-trash"></i>
                                </button>
                                @endcan
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr id="emptyRow">
                        <td colspan="7">
                            <div class="u-empty">
                                <i class="ri-user-line"></i>
                                No users found
                            </div>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination / count --}}
        <div class="d-flex align-items-center justify-content-between px-4 py-3 border-top"
             style="background:var(--u-bg);">
            <div class="text-muted small">
                Showing <span id="showingCount" class="fw-semibold text-dark">{{ $data->count() }}</span>
                of <span id="totalCount" class="fw-semibold text-dark">{{ $data->count() }}</span> users
            </div>
            <div id="paginationEl" class="u-pagination"></div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════
         ADD USER MODAL
    ══════════════════════════════════════════════════════ --}}
    <div id="showModal" class="modal fade u-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="u-modal-hero">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <h5><i class="bi bi-person-plus me-2"></i>Add New User</h5>
                    <p>Create a system user with role-based access</p>
                </div>
                <form id="add-user-form" autocomplete="off">
                    <div class="u-modal-body">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="u-form-label">Full Name</label>
                                <input type="text" id="name" name="name" class="u-form-input" placeholder="e.g. John Adeyemi" required>
                            </div>
                            <div class="col-12">
                                <label class="u-form-label">Phone Number <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" id="phone_number" name="phone_number" class="u-form-input" placeholder="+2348012345678">
                            </div>
                            <div class="col-12">
                                <label class="u-form-label">Email Address</label>
                                <input type="email" id="email" name="email" class="u-form-input" placeholder="user@hotel.com" required>
                            </div>
                            <div class="col-12">
                                <label class="u-form-label">Role(s)</label>
                                <select id="role" name="roles[]" class="u-form-input" multiple required>
                                    @foreach (Role::all() as $role)
                                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                                    @endforeach
                                </select>
                                <div class="small text-muted mt-1">Hold Ctrl/Cmd to select multiple</div>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Password</label>
                                <input type="password" id="password" name="password" class="u-form-input" placeholder="Min. 8 chars" required>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Confirm Password</label>
                                <input type="password" id="password_confirmation" name="password_confirmation" class="u-form-input" placeholder="Repeat password" required>
                            </div>
                        </div>
                        <div class="alert alert-danger d-none mt-3 rounded-3" id="add-alert"></div>
                    </div>
                    <div class="u-modal-footer d-flex justify-content-end gap-2">
                        <button type="button" class="u-btn ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="u-btn primary" id="add-btn">
                            <i class="bi bi-plus-circle"></i> Create User
                        </button>
                    </div>
                </form>
            </div>
        </div>



    {{-- ══════════════════════════════════════════════════════
         EDIT USER MODAL
    ══════════════════════════════════════════════════════ --}}
    <div id="editModal" class="modal fade u-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="u-modal-hero" style="background:linear-gradient(135deg,#065f46,#16a34a,#4ade80);">
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    <h5><i class="ph-pencil me-2"></i>Edit User</h5>
                    <p>Update user information and permissions</p>
                </div>
                <form id="edit-user-form" autocomplete="off">
                    <div class="u-modal-body">
                        <input type="hidden" id="edit-id-field">
                        <div class="row g-3">
                            <div class="col-12">
                                <label class="u-form-label">Full Name</label>
                                <input type="text" id="edit-name" name="name" class="u-form-input" required>
                            </div>
                            <div class="col-12">
                                <label class="u-form-label">Email Address</label>
                                <input type="email" id="edit-email" name="email" class="u-form-input" required>
                            </div>
                            <div class="col-12">
                                <label class="u-form-label">Phone Number <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="text" id="edit-phone" name="phone_number" class="u-form-input" placeholder="+2348012345678">
                            </div>
                            <div class="col-12">
                                <label class="u-form-label">Role(s)</label>
                                <select id="edit-role" name="roles[]" class="u-form-input" multiple required>
                                    @foreach (Role::all() as $role)
                                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">New Password <span class="text-muted fw-normal">(optional)</span></label>
                                <input type="password" id="edit-password" name="password" class="u-form-input" placeholder="Leave blank to keep">
                            </div>
                            <div class="col-md-6">
                                <label class="u-form-label">Confirm Password</label>
                                <input type="password" id="edit-password_confirmation" name="password_confirmation" class="u-form-input" placeholder="Repeat if changing">
                            </div>
                        </div>
                        <div class="alert alert-danger d-none mt-3 rounded-3" id="edit-alert"></div>
                    </div>
                    <div class="u-modal-footer d-flex justify-content-end gap-2">
                        <button type="button" class="u-btn ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="u-btn success" id="update-btn">
                            <i class="bi bi-check-circle"></i> Update User
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    {{-- ══════════════════════════════════════════════════════
         DELETE CONFIRM MODAL
    ══════════════════════════════════════════════════════ --}}
    <div id="deleteRecordModal" class="modal fade u-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" style="max-width:400px">
            <div class="modal-content">
                <div class="modal-body p-5 text-center">
                    <div style="width:70px;height:70px;border-radius:50%;background:#fef2f2;display:inline-flex;align-items:center;justify-content:center;margin-bottom:16px;">
                        <i class="bi bi-trash" style="font-size:28px;color:var(--u-danger)"></i>
                    </div>
                    <h4 class="fw-700" style="color:var(--u-primary)">Confirm Delete</h4>
                    <p class="text-muted mb-4">This action cannot be undone. The user will be permanently removed.</p>
                    <div class="d-flex gap-3 justify-content-center">
                        <button type="button" class="u-btn ghost" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="u-btn danger" id="delete-record">
                            <i class="bi bi-trash"></i> Yes, Delete
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>
</div>
</div>

{{-- ══════════════════════════════════════════════════════════════
     SCRIPTS
══════════════════════════════════════════════════════════════ --}}

<script src="{{ asset('vendor/jquery/jquery-3.6.0.min.js') }}"></script>
<script src="{{ asset('theme/layouts/assets/libs/bootstrap/js/bootstrap.bundle.min.js') }}"></script>
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<script src="{{ asset('vendor/sweetalert2/sweetalert2.all.min.js') }}"></script>
<script src="{{ asset('vendor/axios/axios.min.js') }}"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    // ============================================================
    // MAIN USER MANAGEMENT FUNCTIONS
    // ============================================================

    if (typeof bootstrap === 'undefined') {
        console.error('Bootstrap not loaded!');
        return;
    }

    const CSRF = document.querySelector('meta[name="csrf-token"]')?.content || '';

    function showModal(modalId) {
        const modalElement = document.getElementById(modalId);
        if (!modalElement) return null;
        let modal = bootstrap.Modal.getInstance(modalElement);
        if (!modal) modal = new bootstrap.Modal(modalElement, { backdrop: 'static', keyboard: true });
        modal.show();
        return modal;
    }

    function hideModal(modalId) {
        const modalElement = document.getElementById(modalId);
        if (modalElement) {
            const modal = bootstrap.Modal.getInstance(modalElement);
            if (modal) modal.hide();
        }
    }

    // Modal triggers
    ['openAddUserModalBtn', 'openAddUserModalBtnMobile'].forEach(btnId => {
        const btn = document.getElementById(btnId);
        if (btn) btn.addEventListener('click', e => { e.preventDefault(); showModal('showModal'); });
    });

    // ── Table filtering ─────────────────────────────────────
    const allRows = () => Array.from(document.querySelectorAll('#usersTableBody tr[data-id]'));

    function applyFilters() {
        const search = document.getElementById('liveSearch')?.value.toLowerCase().trim() || '';
        const role   = document.getElementById('roleFilter')?.value.toLowerCase().trim() || '';
        const email  = document.getElementById('emailFilter')?.value.toLowerCase().trim() || '';
        let shown = 0;
        allRows().forEach(row => {
            const name = row.dataset.name || '';
            const rEmail = row.dataset.email || '';
            const roles = row.dataset.roles || '';
            const matchSearch = !search || name.includes(search) || rEmail.includes(search);
            const matchRole = !role || roles.split(',').some(r => r.trim() === role) || (role === 'no role' && !roles.trim());
            const matchEmail = !email || rEmail === email;
            const visible = matchSearch && matchRole && matchEmail;
            row.style.display = visible ? '' : 'none';
            if (visible) shown++;
        });
        const showingSpan = document.getElementById('showingCount');
        if (showingSpan) showingSpan.textContent = shown;
        const userBadge = document.getElementById('userCountBadge');
        if (userBadge) userBadge.textContent = shown;

        let empty = document.getElementById('noResults');
        if (shown === 0 && allRows().length > 0) {
            if (!empty) {
                empty = document.createElement('tr');
                empty.id = 'noResults';
                empty.innerHTML = `<td colspan="7"><div class="u-empty"><i class="ri-search-line"></i>No users match your filters</div></td>`;
                document.getElementById('usersTableBody')?.appendChild(empty);
            }
        } else if (empty) empty.remove();
    }

    document.getElementById('liveSearch')?.addEventListener('input', applyFilters);
    document.getElementById('roleFilter')?.addEventListener('change', applyFilters);
    document.getElementById('emailFilter')?.addEventListener('change', applyFilters);
    document.getElementById('clearFilters')?.addEventListener('click', () => {
        if (document.getElementById('liveSearch')) document.getElementById('liveSearch').value = '';
        if (document.getElementById('roleFilter')) document.getElementById('roleFilter').value = '';
        if (document.getElementById('emailFilter')) document.getElementById('emailFilter').value = '';
        applyFilters();
    });

    // Sortable columns
    let sortDir = {};
    document.querySelectorAll('.sortable').forEach(th => {
        th.style.cursor = 'pointer';
        th.addEventListener('click', () => {
            const col = parseInt(th.dataset.col);
            sortDir[col] = !sortDir[col];
            const tbody = document.getElementById('usersTableBody');
            if (!tbody) return;
            const rows = [...document.querySelectorAll('#usersTableBody tr[data-id]')];
            rows.sort((a, b) => {
                const key = ['name','email','','','','date'][col] || 'name';
                const av = a.dataset[key] || a.cells[col]?.textContent || '';
                const bv = b.dataset[key] || b.cells[col]?.textContent || '';
                return sortDir[col] ? av.localeCompare(bv) : bv.localeCompare(av);
            });
            rows.forEach(r => tbody.appendChild(r));
        });
    });

    // Check all functionality
    const checkAll = document.getElementById('checkAll');
    if (checkAll) {
        checkAll.addEventListener('change', function() {
            document.querySelectorAll('.row-check').forEach(cb => {
                const row = cb.closest('tr');
                if (row && row.style.display !== 'none') cb.checked = this.checked;
            });
            updateRemoveBtn();
        });
    }
    document.addEventListener('change', e => { if (e.target.classList.contains('row-check')) updateRemoveBtn(); });
    function updateRemoveBtn() {
        const count = document.querySelectorAll('.row-check:checked').length;
        const removeBtn = document.getElementById('remove-actions');
        if (removeBtn) removeBtn.classList.toggle('d-none', count === 0);
    }

    // Delete single
    document.addEventListener('click', e => {
        const btn = e.target.closest('.remove-item-btn');
        if (!btn) return;
        const id = btn.dataset.id;
        const deleteBtn = document.getElementById('delete-record');
        if (deleteBtn) {
            const fresh = deleteBtn.cloneNode(true);
            deleteBtn.replaceWith(fresh);
            fresh.addEventListener('click', () => {
                axios.delete(`/users/${id}`, { headers: { 'X-CSRF-TOKEN': CSRF } })
                    .then(() => {
                        const row = document.querySelector(`tr[data-id="${id}"]`);
                        if (row) row.remove();
                        const totalEl = document.getElementById('totalCount');
                        if (totalEl) totalEl.textContent = parseInt(totalEl.textContent) - 1;
                        hideModal('deleteRecordModal');
                        Swal.fire({ icon: 'success', title: 'Deleted!', text: 'User removed.', showConfirmButton: false, timer: 2000 });
                        applyFilters();
                    })
                    .catch(err => Swal.fire('Error', err.response?.data?.message || 'Delete failed', 'error'));
            });
        }
        showModal('deleteRecordModal');
    });

    // Delete multiple
    window.deleteMultiple = function() {
        const ids = [...document.querySelectorAll('.row-check:checked')].map(cb => cb.closest('tr').dataset.id).filter(Boolean);
        if (!ids.length) { Swal.fire('Select at least one user'); return; }
        Swal.fire({
            title: 'Delete ' + ids.length + ' user(s)?',
            text: 'This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc2626',
            confirmButtonText: 'Yes, Delete All'
        }).then(r => {
            if (!r.isConfirmed) return;
            Promise.all(ids.map(id => axios.delete(`/users/${id}`, { headers: { 'X-CSRF-TOKEN': CSRF } })))
                .then(() => {
                    ids.forEach(id => { const row = document.querySelector(`tr[data-id="${id}"]`); if (row) row.remove(); });
                    Swal.fire('Deleted!', ids.length + ' users removed.', 'success');
                    updateRemoveBtn();
                    applyFilters();
                });
        });
    };

    // Edit user
    document.addEventListener('click', e => {
        const btn = e.target.closest('.edit-item-btn');
        if (!btn) return;
        document.getElementById('edit-id-field').value = btn.dataset.id;
        document.getElementById('edit-name').value = btn.dataset.name;
        document.getElementById('edit-email').value = btn.dataset.email;
        document.getElementById('edit-phone').value = btn.dataset.phone || '';
        const roles = (btn.dataset.roles || '').split(',');
        const roleSelect = document.getElementById('edit-role');
        if (roleSelect) {
            Array.from(roleSelect.options).forEach(opt => { opt.selected = roles.includes(opt.value); });
        }
        document.getElementById('edit-password').value = '';
        document.getElementById('edit-password_confirmation').value = '';
        document.getElementById('edit-alert')?.classList.add('d-none');
        showModal('editModal');
    });

    // Add user form
    const addUserForm = document.getElementById('add-user-form');
    if (addUserForm) {
        addUserForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const alert = document.getElementById('add-alert');
            alert?.classList.add('d-none');
            const name = document.getElementById('name')?.value.trim();
            const email = document.getElementById('email')?.value.trim();
            const phone_number = document.getElementById('phone_number')?.value.trim() || null;
            const pass = document.getElementById('password')?.value;
            const conf = document.getElementById('password_confirmation')?.value;
            const roles = Array.from(document.getElementById('role')?.selectedOptions || []).map(o => o.value);
            if (!name) return showAlert(alert, 'Enter a name');
            if (!email) return showAlert(alert, 'Enter an email');
            if (!roles.length) return showAlert(alert, 'Select at least one role');
            if (!pass) return showAlert(alert, 'Enter a password');
            if (pass !== conf) return showAlert(alert, 'Passwords do not match');
            const btn = document.getElementById('add-btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Creating…'; }
            axios.post('/users', { name, email, phone_number, roles, password: pass, password_confirmation: conf, _token: CSRF })
                .then(res => {
                    addRowToTable(res.data.user);
                    hideModal('showModal');
                    Swal.fire({ icon: 'success', title: 'User Created!', text: res.data.user.name + ' added.', showConfirmButton: false, timer: 2500 });
                })
                .catch(err => showAlert(alert, err.response?.data?.message || 'Error creating user'))
                .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-circle"></i> Create User'; } });
        });
    }

    // Edit user form
    const editUserForm = document.getElementById('edit-user-form');
    if (editUserForm) {
        editUserForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const alert = document.getElementById('edit-alert');
            alert?.classList.add('d-none');
            const id = document.getElementById('edit-id-field')?.value;
            const name = document.getElementById('edit-name')?.value.trim();
            const email = document.getElementById('edit-email')?.value.trim();
            const phone_number = document.getElementById('edit-phone')?.value.trim() || null;
            const pass = document.getElementById('edit-password')?.value;
            const conf = document.getElementById('edit-password_confirmation')?.value;
            const roles = Array.from(document.getElementById('edit-role')?.selectedOptions || []).map(o => o.value);
            if (!name) return showAlert(alert, 'Enter a name');
            if (!email) return showAlert(alert, 'Enter an email');
            if (!roles.length) return showAlert(alert, 'Select at least one role');
            if (pass && pass !== conf) return showAlert(alert, 'Passwords do not match');
            const btn = document.getElementById('update-btn');
            if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Saving…'; }
            const payload = { name, email, phone_number, roles, _token: CSRF };
            if (pass) { payload.password = pass; payload.password_confirmation = conf; }
            axios.put(`/users/${id}`, payload, { headers: { 'X-CSRF-TOKEN': CSRF } })
                .then(res => {
                    updateRowInTable(res.data.user);
                    hideModal('editModal');
                    Swal.fire({ icon: 'success', title: 'Updated!', text: res.data.user.name + ' updated.', showConfirmButton: false, timer: 2500 });
                })
                .catch(err => showAlert(alert, err.response?.data?.message || 'Error updating user'))
                .finally(() => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-circle"></i> Update User'; } });
        });
    }

    function showAlert(el, msg) { if (el) { el.innerHTML = '<i class="bi bi-exclamation-triangle me-2"></i>' + msg; el.classList.remove('d-none'); setTimeout(() => el.classList.add('d-none'), 5000); } }
    function rolePill(roleName) {
        const cls = (@json(\Spatie\Permission\Models\Role::pluck('name')->mapWithKeys(fn ($r) => [$r => \App\Models\User::rolePillClass($r)])))[roleName] || 'default';
        return `<span class="u-role-pill ${cls}"><i class="bi bi-shield-check"></i>${roleName}</span>`;
    }
    function escHtml(s) { return String(s || '').replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m])); }
    function getInitials(name) { const parts = (name || '').split(' '); return (parts[0]?.[0] || '') + (parts[1]?.[0] || ''); }
    function addRowToTable(user) {
        const tbody = document.getElementById('usersTableBody');
        if (!tbody) return;
        const emptyRow = document.getElementById('emptyRow');
        if (emptyRow) emptyRow.remove();
        const row = document.createElement('tr');
        row.dataset.id = user.id;
        row.dataset.name = user.name.toLowerCase();
        row.dataset.email = user.email.toLowerCase();
        row.dataset.roles = (user.roles || []).join(',').toLowerCase();
        row.dataset.date = new Date().toISOString().slice(0, 10);
        row.innerHTML = `
            <td><input type="checkbox" class="row-check" style="accent-color:var(--u-accent);cursor:pointer;"></td>
            <td><div class="u-avatar">${getInitials(user.name)}</div></td>
            <td><div class="fw-semibold" style="color:var(--u-primary)">${escHtml(user.name)}</div></td>
            <td><span class="text-muted small">${escHtml(user.email)}</span></td>
            <td>${(user.roles || []).map(rolePill).join('')}</td>
            <td class="text-muted small">${new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}</td>
            <td><div class="d-flex gap-1">
                <a href="/users/${user.id}" class="u-action-btn view"><i class="ph-eye"></i></a>
                <button class="u-action-btn edit edit-item-btn" data-id="${user.id}" data-name="${escHtml(user.name)}" data-email="${escHtml(user.email)}" data-phone="${escHtml(user.phone_number || '')}" data-roles="${(user.roles || []).join(',')}"><i class="ph-pencil"></i></button>
                <button class="u-action-btn del remove-item-btn" data-id="${user.id}"><i class="ph-trash"></i></button>
            </div></td>
        `;
        tbody.prepend(row);
        const total = document.getElementById('totalCount');
        if (total) total.textContent = parseInt(total.textContent) + 1;
        applyFilters();
    }
    function updateRowInTable(user) {
        const row = document.querySelector(`tr[data-id="${user.id}"]`);
        if (!row) return;
        row.dataset.name = user.name.toLowerCase();
        row.dataset.email = user.email.toLowerCase();
        row.dataset.roles = (user.roles || []).join(',').toLowerCase();
        if (row.cells[2]) row.cells[2].innerHTML = `<div class="fw-semibold" style="color:var(--u-primary)">${escHtml(user.name)}</div>`;
        if (row.cells[3]) row.cells[3].innerHTML = `<span class="text-muted small">${escHtml(user.email)}</span>`;
        if (row.cells[4]) row.cells[4].innerHTML = (user.roles || []).map(rolePill).join('');
        const editBtn = row.querySelector('.edit-item-btn');
        if (editBtn) {
            editBtn.dataset.name  = user.name;
            editBtn.dataset.email = user.email;
            editBtn.dataset.phone = user.phone_number || '';
            editBtn.dataset.roles = (user.roles || []).join(',');
        }
    }

    // Chart
    const ctx = document.getElementById('usersByRoleChart')?.getContext('2d');
    if (ctx) {
        new Chart(ctx, {
            type: 'bar',
            data: { labels: @json(array_keys($role_counts)), datasets: [{ label: 'Users', data: @json(array_values($role_counts)), backgroundColor: ['rgba(37,99,235,.75)', 'rgba(16,185,129,.75)', 'rgba(245,158,11,.75)', 'rgba(239,68,68,.75)'], borderRadius: 8 }] },
            options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { stepSize: 1 } } } }
        });
    }

    // ── Initial Load ───────────────────────────────────────
    applyFilters();

});
</script>
@endsection
