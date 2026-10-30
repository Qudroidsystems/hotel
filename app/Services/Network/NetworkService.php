<?php

namespace App\Services\Network;

use App\Enums\ChargeType;
use App\Enums\ReservationStatus;
use App\Models\It\ItAlert;
use App\Models\Network\NetworkLink;
use App\Models\Network\NetworkPass;
use App\Models\Network\NetworkPlan;
use App\Models\Network\NetworkSample;
use App\Models\Reservation;
use App\Models\User;
use App\Services\Billing\FolioService;
use App\Services\Network\Gateways\DemoGateway;
use App\Services\Network\Gateways\Gateway;
use App\Services\Network\Gateways\ManualGateway;
use App\Support\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Internet & guest Wi-Fi: reads every internet line (Starlink dishes and others)
 * through the router, keeps their history, raises an IT alert when one drops,
 * and gives out, limits and cuts off guest Wi-Fi codes.
 */
class NetworkService
{
    /** Code letters without look-alikes (0/O, 1/I/L). */
    private const CODE_CHARS = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private ?Gateway $gateway = null;

    public function __construct(private FolioService $folios) {}

    public function gateway(): Gateway
    {
        return $this->gateway ??= match ((string) config('hotel.network.driver', 'demo')) {
            'demo'  => new DemoGateway,
            default => new ManualGateway,
        };
    }

    /** For tests and future router classes. */
    public function useGateway(Gateway $gateway): void
    {
        $this->gateway = $gateway;
    }

    // ── Reading the lines ─────────────────────────────────────────────

    /** Read every active line, store the readings, and tidy up expired Wi-Fi codes. Returns lines read. */
    public function poll(): int
    {
        $n = 0;
        foreach (NetworkLink::active()->ordered()->get() as $link) {
            try {
                $this->record($link, $this->gateway()->readLink($link));
                $n++;
            } catch (Throwable $e) {
                Log::warning("Network: reading {$link->name} failed: " . $e->getMessage());
                $this->record($link, ['status' => 'offline', 'message' => 'Could not read: ' . mb_substr($e->getMessage(), 0, 200), 'used_mb' => 0]);
            }
        }
        $this->readPasses();
        $this->expireDue();

        return $n;
    }

    /** Store one reading on the line and in its history; alert when it drops, close the alert when it is back. */
    public function record(NetworkLink $link, array $r, ?Carbon $at = null): NetworkSample
    {
        $at ??= now();
        $status = $r['status'] ?? 'unknown';
        $before = $link->status;

        if ($link->periodStart()->gt($link->checked_at ?? $at)) {
            $link->used_gb_month = 0;   // new allowance month
        }
        $link->forceFill([
            'status'          => $status,
            'signal'          => $r['signal'] ?? null,
            'down_mbps'       => $r['down_mbps'] ?? null,
            'up_mbps'         => $r['up_mbps'] ?? null,
            'latency_ms'      => $r['latency_ms'] ?? null,
            'packet_loss'     => $r['packet_loss'] ?? null,
            'obstruction_pct' => $r['obstruction_pct'] ?? null,
            'used_gb_month'   => round((float) $link->used_gb_month + ((float) ($r['used_mb'] ?? 0)) / 1024, 3),
            'last_message'    => $r['message'] ?? null,
            'checked_at'      => $at,
            'status_since'    => $before !== $status || ! $link->status_since ? $at : $link->status_since,
        ])->save();

        $sample = $link->samples()->create([
            'sampled_at' => $at, 'status' => $status, 'signal' => $r['signal'] ?? null, 'down_mbps' => $r['down_mbps'] ?? null,
            'up_mbps' => $r['up_mbps'] ?? null, 'latency_ms' => $r['latency_ms'] ?? null, 'packet_loss' => $r['packet_loss'] ?? null,
            'obstruction_pct' => $r['obstruction_pct'] ?? null, 'used_mb' => (float) ($r['used_mb'] ?? 0),
        ]);

        if ($status === 'offline' && $before !== 'offline' && $before !== 'unknown') {
            $this->raise($link);
        } elseif ($status !== 'offline' && $link->alert_id) {
            $this->recover($link);
        }

        return $sample;
    }

    private function raise(NetworkLink $link): void
    {
        if (! Schema::hasTable('it_alerts')) {
            return;
        }
        $others = NetworkLink::active()->where('id', '!=', $link->id)->whereIn('status', ['online', 'degraded'])->count();
        $severity = $others ? 'high' : 'critical';
        $alert = ItAlert::create(['severity' => $severity, 'title' => "{$link->name} is OFFLINE", 'status' => 'open', 'opened_at' => now(),
            'message' => ($link->last_message ?: 'The line stopped answering.') . ($others ? " {$others} other line(s) are carrying the traffic." : ' No other line is up: the hotel has no internet.')]);
        $alert->forceFill(['number' => 'AL-' . str_pad((string) $alert->id, 6, '0', STR_PAD_LEFT)])->save();
        $link->forceFill(['alert_id' => $alert->id])->save();
        Audit::record('network.link_down', "{$alert->number}: {$link->name} went offline", $alert, flagged: true);
    }

    private function recover(NetworkLink $link): void
    {
        $alert = ItAlert::find($link->alert_id);
        if ($alert && $alert->status !== 'resolved') {
            $alert->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'auto_resolved' => true,
                'resolution' => 'Back online after ' . $alert->opened_at->diffForHumans(now(), true) . '.'])->save();
            Audit::record('network.link_up', "{$alert->number}: {$link->name} is back online after " . $alert->opened_at->diffForHumans(now(), true), $alert);
        }
        $link->forceFill(['alert_id' => null])->save();
    }

    private function readPasses(): void
    {
        $active = NetworkPass::active()->where('expires_at', '>', now())->get();
        if ($active->isEmpty()) {
            return;
        }
        foreach ($this->gateway()->readPasses($active) as $id => $u) {
            $pass = $active->firstWhere('id', $id);
            if (! $pass) {
                continue;
            }
            $pass->forceFill([
                'used_mb'        => round((float) $pass->used_mb + (float) $u['used_mb'], 2),
                'devices_online' => (int) $u['devices_online'],
                'last_seen_at'   => $u['devices_online'] ? now() : $pass->last_seen_at,
            ])->save();
        }
    }

    /** Close codes whose time is up. */
    public function expireDue(): int
    {
        $n = 0;
        foreach (NetworkPass::active()->where('expires_at', '<=', now())->get() as $pass) {
            $this->gateway()->removePass($pass);
            $pass->forceFill(['status' => 'expired', 'devices_online' => 0])->save();
            $n++;
        }

        return $n;
    }

    public function prune(): int
    {
        return NetworkSample::where('sampled_at', '<', now()->subDays((int) config('hotel.network.sample_retention_days', 30)))->delete();
    }

    // ── What the pages show ───────────────────────────────────────────

    /**
     * Compact state of all lines, for the IT console and other dashboards.
     *
     * @return array{overall: array, links: array}
     */
    public function consoleSummary(): array
    {
        $links = Schema::hasTable('network_links') ? NetworkLink::active()->ordered()->get() : collect();
        $up = $links->whereIn('status', ['online', 'degraded']);
        $overall = [
            'status'    => $links->isEmpty() ? 'unknown' : ($up->isEmpty() ? 'offline' : ($up->count() < $links->count() || $up->contains('status', 'degraded') ? 'degraded' : 'online')),
            'signal'    => $up->isNotEmpty() ? (int) round($up->avg('signal')) : 0,
            'down_mbps' => round((float) $up->sum('down_mbps'), 1),
            'up_mbps'   => round((float) $up->sum('up_mbps'), 1),
            'online'    => $up->count(),
            'total'     => $links->count(),
            'source'    => $this->gateway()->label(),
            'live'      => $this->gateway()->live(),
        ];

        return ['overall' => $overall, 'links' => $links->map(fn (NetworkLink $l) => [
            'id' => $l->id, 'name' => $l->name, 'provider' => $l->provider, 'role' => $l->role, 'status' => $l->status,
            'signal' => $l->signal, 'down_mbps' => $l->down_mbps, 'up_mbps' => $l->up_mbps, 'latency_ms' => $l->latency_ms,
            'packet_loss' => $l->packet_loss, 'checked_at' => $l->checked_at?->toIso8601String(),
        ])->values()->all()];
    }

    /**
     * Readings over the last hours, bucketed for charts: [link_id => [[label, down, up, latency, status], …]].
     */
    public function history(Collection $links, int $hours = 24, int $buckets = 48): array
    {
        $from = now()->subHours($hours);
        $size = $hours * 3600 / $buckets;
        $samples = NetworkSample::whereIn('link_id', $links->pluck('id'))->where('sampled_at', '>=', $from)
            ->orderBy('sampled_at')->get(['link_id', 'sampled_at', 'status', 'down_mbps', 'up_mbps', 'latency_ms', 'used_mb']);
        $rank = ['offline' => 3, 'degraded' => 2, 'online' => 1, 'unknown' => 0];
        $out = [];
        foreach ($links as $link) {
            $rows = array_fill(0, $buckets, null);
            foreach ($samples->where('link_id', $link->id)->groupBy(fn ($s) => min($buckets - 1, (int) floor(($s->sampled_at->getTimestamp() - $from->getTimestamp()) / $size))) as $i => $g) {
                $worst = $g->sortByDesc(fn ($s) => $rank[$s->status] ?? 0)->first()->status;
                $rows[$i] = ['down' => round((float) $g->avg('down_mbps'), 1), 'up' => round((float) $g->avg('up_mbps'), 1),
                    'latency' => $g->whereNotNull('latency_ms')->isNotEmpty() ? (int) round($g->whereNotNull('latency_ms')->avg('latency_ms')) : null,
                    'used_mb' => round((float) $g->sum('used_mb'), 1), 'status' => $worst,
                    'at' => $from->copy()->addSeconds((int) ($i * $size))->format('D g:i A')];
            }
            $out[$link->id] = $rows;
        }

        return $out;
    }

    /** Data moved per line today and over the allowance month, in GB. */
    public function usage(Collection $links): array
    {
        $today = NetworkSample::whereIn('link_id', $links->pluck('id'))->where('sampled_at', '>=', today())
            ->groupBy('link_id')->select('link_id', DB::raw('sum(used_mb) as mb'))->pluck('mb', 'link_id');

        return $links->mapWithKeys(fn ($l) => [$l->id => ['today_gb' => round(((float) ($today[$l->id] ?? 0)) / 1024, 2), 'month_gb' => round($l->used_gb_month, 2)]])->all();
    }

    // ── Guest Wi-Fi codes ─────────────────────────────────────────────

    /**
     * Give a Wi-Fi code. $data: kind, plan_id, holder, room_number, devices, expires_at, reservation (Reservation|null), charge (bool).
     */
    public function issue(array $data, ?User $by = null): NetworkPass
    {
        $plan = ! empty($data['plan_id']) ? NetworkPlan::findOrFail($data['plan_id']) : $this->defaultPlan();
        /** @var Reservation|null $r */
        $r = $data['reservation'] ?? null;
        $expires = isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : ($r ? $this->stayEnds($r) : now()->addDay());
        abort_if($expires->lte(now()), 422, 'The end time must be in the future.');

        return DB::transaction(function () use ($data, $plan, $r, $expires, $by) {
            $pass = NetworkPass::create([
                'code'           => $this->newCode(),
                'kind'           => $data['kind'] ?? ($r ? 'stay' : 'visitor'),
                'reservation_id' => $r?->id,
                'plan_id'        => $plan?->id,
                'holder'         => $data['holder'] ?? $r?->guest?->full_name,
                'room_number'    => $data['room_number'] ?? ($r ? $r->rooms->pluck('room.number')->filter()->implode(', ') : null),
                'devices'        => (int) ($data['devices'] ?? $plan?->devices ?? 3),
                'down_mbps'      => $plan?->down_mbps,
                'up_mbps'        => $plan?->up_mbps,
                'data_cap_mb'    => $plan?->data_cap_mb,
                'starts_at'      => now(),
                'expires_at'     => $expires,
                'status'         => 'active',
                'issued_by'      => $by?->id,
            ]);
            if ($r && $plan && $plan->price > 0 && ($data['charge'] ?? true)) {
                $days = max(1, (int) ceil(now()->diffInHours($expires) / 24));
                $charge = $this->folios->postCharge($this->folios->forReservation($r), ChargeType::Service,
                    "Wi-Fi: {$plan->name} ({$days} day" . ($days === 1 ? '' : 's') . ')', $days, $plan->price);
                $pass->forceFill(['folio_charge_id' => $charge->id])->save();
            }
            $this->sync($pass);
            Audit::record('network.pass_issued', "Wi-Fi code {$pass->code} given to " . ($pass->holder ?: 'a guest') . ($pass->room_number ? " (room {$pass->room_number})" : '')
                . ' until ' . $pass->expires_at->format('D j M, g:i A'), $pass, by: $by);

            return $pass;
        });
    }

    /** Called at check-in: the guest gets the default plan's code for the whole stay. */
    public function issueForReservation(Reservation $r): ?NetworkPass
    {
        if (! config('hotel.network.auto_issue', true) || ! ($plan = $this->defaultPlan())) {
            return null;
        }
        if ($existing = NetworkPass::active()->where('reservation_id', $r->id)->first()) {
            return $existing;
        }
        $r->loadMissing('guest', 'rooms.room');

        return $this->issue(['reservation' => $r, 'plan_id' => $plan->id, 'kind' => 'stay', 'charge' => true]);
    }

    /** Called at check-out (or cancel): the stay's codes stop working. */
    public function endForReservation(Reservation $r, string $why = 'checked out'): int
    {
        $n = 0;
        foreach (NetworkPass::active()->where('reservation_id', $r->id)->get() as $pass) {
            $this->gateway()->removePass($pass);
            $pass->forceFill(['status' => 'expired', 'expires_at' => min($pass->expires_at, now()), 'devices_online' => 0, 'revoke_reason' => "Guest {$why}"])->save();
            $n++;
        }

        return $n;
    }

    public function revoke(NetworkPass $pass, string $reason, ?User $by = null): void
    {
        if ($pass->status !== 'active') {
            return;
        }
        $this->gateway()->removePass($pass);
        $pass->forceFill(['status' => 'revoked', 'revoked_at' => now(), 'revoked_by' => $by?->id, 'revoke_reason' => $reason, 'devices_online' => 0])->save();
        Audit::record('network.pass_revoked', "Wi-Fi code {$pass->code} (" . ($pass->holder ?: 'guest') . ") cut off: {$reason}", $pass, reason: $reason, by: $by);
    }

    /** Change the end time or plan of a code that is still running. */
    public function change(NetworkPass $pass, array $data, ?User $by = null): void
    {
        abort_unless($pass->status === 'active', 422, 'This code is no longer active.');
        if (! empty($data['plan_id']) && (int) $data['plan_id'] !== (int) $pass->plan_id) {
            $plan = NetworkPlan::findOrFail($data['plan_id']);
            $pass->forceFill(['plan_id' => $plan->id, 'down_mbps' => $plan->down_mbps, 'up_mbps' => $plan->up_mbps, 'data_cap_mb' => $plan->data_cap_mb, 'devices' => $plan->devices]);
        }
        if (! empty($data['devices'])) {
            $pass->devices = (int) $data['devices'];
        }
        if (! empty($data['expires_at'])) {
            $until = Carbon::parse($data['expires_at']);
            abort_if($until->lte(now()), 422, 'The end time must be in the future.');
            $pass->expires_at = $until;
        }
        $pass->save();
        $this->sync($pass);
        Audit::record('network.pass_changed', "Wi-Fi code {$pass->code}: " . ($pass->plan?->name ?? 'custom') . ', ' . $pass->devices . ' device(s), until ' . $pass->expires_at->format('D j M, g:i A'), $pass, by: $by);
    }

    /** What a guest sees about their Wi-Fi on the stay page and in the app. */
    public function forGuest(Reservation $r): ?array
    {
        if (! Schema::hasTable('network_passes') || $r->status !== ReservationStatus::CheckedIn) {
            return null;
        }
        $pass = NetworkPass::active()->with('plan')->where('reservation_id', $r->id)->where('expires_at', '>', now())->latest('id')->first();
        if (! $pass) {
            return null;
        }

        return [
            'network'     => (string) config('hotel.network.guest_ssid', 'Hotel Guest'),
            'code'        => $pass->code,
            'plan'        => $pass->plan?->name,
            'speed_label' => $pass->speed_label,
            'devices'     => (int) $pass->devices,
            'data_label'  => $pass->data_cap_mb ? NetworkPlan::size($pass->data_cap_mb) . ' a day' : 'Unlimited data',
            'used_label'  => NetworkPlan::size($pass->used_mb),
            'valid_until' => $pass->expires_at->toIso8601String(),
            'valid_until_label' => $pass->expires_at->format('D j M, g:i A'),
            'how'         => 'Join the "' . config('hotel.network.guest_ssid', 'Hotel Guest') . '" Wi-Fi, then type this code on the page that opens.',
        ];
    }

    public function defaultPlan(): ?NetworkPlan
    {
        return NetworkPlan::active()->where('is_default', true)->ordered()->first();
    }

    /** When a stay's Wi-Fi should stop: check-out day at check-out time, plus a grace period. */
    public function stayEnds(Reservation $r): Carbon
    {
        [$h, $m] = array_map('intval', explode(':', (string) config('hotel.booking.check_out_time', '12:00')) + [1 => 0]);
        $end = $r->check_out_date->copy()->setTime($h, $m)->addMinutes((int) config('hotel.network.grace_minutes', 60));

        return $end->lte(now()) ? now()->addHours(2) : $end;
    }

    private function sync(NetworkPass $pass): void
    {
        try {
            $this->gateway()->pushPass($pass);
            $pass->forceFill(['synced_at' => now()])->save();
        } catch (Throwable $e) {
            Log::warning("Network: sending Wi-Fi code {$pass->code} to the router failed: " . $e->getMessage());
        }
    }

    private function newCode(): string
    {
        $len = max(6, min(12, (int) config('hotel.network.code_length', 8)));
        do {
            $code = '';
            for ($i = 0; $i < $len; $i++) {
                $code .= self::CODE_CHARS[random_int(0, strlen(self::CODE_CHARS) - 1)];
            }
        } while (NetworkPass::active()->where('code', $code)->exists());

        return $code;
    }
}
