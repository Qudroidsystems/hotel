<?php

namespace App\Services\It;

use App\Models\It\ItAlert;
use App\Models\It\ItCheck;
use App\Models\It\ItMaintenanceWindow;
use App\Models\It\ItMonitor;
use App\Support\Audit;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Runs the control room's automated checks (spec §11.1–11.3) and turns
 * failures into alerts. A monitor must fail `alert_after` checks in a row
 * before an alert opens (one blip is not an outage); the alert closes itself
 * when the monitor recovers. Alerts are held during planned maintenance.
 *
 * Runs from the scheduler: `php artisan it:monitor` every minute.
 */
class MonitorService
{
    public const HEARTBEAT = 'it.monitor.heartbeat';

    /** Check every monitor that is due. Returns how many were checked. */
    public function runDue(bool $all = false): int
    {
        Cache::forever(self::HEARTBEAT, now()->toIso8601String());
        $monitors = ($all ? ItMonitor::active() : ItMonitor::due())->get();
        $windows = ItMaintenanceWindow::now()->get();
        foreach ($monitors as $monitor) {
            $this->check($monitor, $windows);
        }

        return $monitors->count();
    }

    /** When the scheduler last ran the checks (null = never). */
    public function lastRun(): ?Carbon
    {
        $at = Cache::get(self::HEARTBEAT);

        return $at ? Carbon::parse($at) : null;
    }

    public function check(ItMonitor $monitor, ?Collection $windows = null): ItCheck
    {
        try {
            [$status, $latency, $message] = $this->probe($monitor);
        } catch (Throwable $e) {
            [$status, $latency, $message] = ['down', null, 'Check failed: ' . $e->getMessage()];
        }

        $windows ??= ItMaintenanceWindow::now()->get();
        $inMaintenance = $windows->contains(fn ($w) => $w->covers($monitor->id));
        $bad = in_array($status, ['down', 'degraded'], true);
        $recorded = $bad && $inMaintenance ? 'maintenance' : $status;

        $check = ItCheck::create(['monitor_id' => $monitor->id, 'status' => $recorded, 'latency_ms' => $latency,
            'message' => str($message)->limit(495)->toString(), 'checked_at' => now()]);

        $monitor->forceFill([
            'status'          => $recorded,
            'status_since'    => $monitor->status === $recorded && $monitor->status_since ? $monitor->status_since : now(),
            'failures'        => $bad ? $monitor->failures + 1 : 0,
            'last_latency_ms' => $latency,
            'last_message'    => str($message)->limit(495)->toString(),
            'last_checked_at' => now(),
        ])->save();

        if ($bad && ! $inMaintenance && $monitor->failures >= max(1, $monitor->alert_after)) {
            $this->raise($monitor, $status, $message);
        } elseif ($status === 'up') {
            $this->recover($monitor);
        }

        return $check;
    }

    /** @return array{0: string, 1: ?int, 2: string} status, latency in ms, message */
    public function probe(ItMonitor $m): array
    {
        return match ($m->type) {
            'http'     => $this->http($m),
            'ping'     => $this->ping($m),
            'tcp'      => $this->tcp($m),
            'ssl'      => $this->ssl($m),
            'database' => $this->database($m),
            'disk'     => $this->disk($m),
            'backup'   => $this->backup($m),
            'queue'    => $this->queue($m),
            default    => ['unknown', null, 'Unknown check type.'],
        };
    }

    // ── Probes ────────────────────────────────────────────────────────

    private function http(ItMonitor $m): array
    {
        $start = microtime(true);
        try {
            $res = Http::timeout($m->timeout_seconds)->connectTimeout($m->timeout_seconds)
                ->withHeaders(['User-Agent' => 'SmartHotel-Monitor/1.0'])->withOptions(['allow_redirects' => ['max' => 3]])
                ->get($m->target);
        } catch (ConnectionException $e) {
            return ['down', null, 'No response: ' . $this->short($e->getMessage())];
        }
        $ms = $this->ms($start);
        $code = $res->status();
        $ok = $m->expect_status ? $code === (int) $m->expect_status : $code < 400;
        if (! $ok) {
            return ['down', $ms, "HTTP {$code}" . ($m->expect_status ? " (expected {$m->expect_status})" : '')];
        }
        if ($m->keyword && ! str_contains($res->body(), $m->keyword)) {
            return ['down', $ms, "HTTP {$code} but the page does not contain \"{$m->keyword}\""];
        }

        return $ms > $m->limit() ? ['degraded', $ms, "HTTP {$code}, slow ({$ms} ms)"] : ['up', $ms, "HTTP {$code} in {$ms} ms"];
    }

    private function ping(ItMonitor $m): array
    {
        $host = (string) $m->target;
        if (! self::validHost($host)) {
            return ['down', null, 'Not a valid IP address or host name.'];
        }
        if (! function_exists('exec') || in_array('exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            return ['unknown', null, 'Ping is not available on this server — use a port check instead.'];
        }
        $t = max(1, (int) $m->timeout_seconds);
        $cmd = match (PHP_OS_FAMILY) {
            'Windows' => 'ping -n 1 -w ' . ($t * 1000) . ' ' . escapeshellarg($host),
            'Darwin'  => 'ping -c 1 -t ' . $t . ' ' . escapeshellarg($host),
            default   => 'ping -c 1 -W ' . $t . ' ' . escapeshellarg($host),
        };
        $start = microtime(true);
        $out = [];
        @exec($cmd . ' 2>&1', $out, $code);
        $text = implode("\n", $out);
        if ($code === 127 || preg_match('/not (found|recognized)|permission denied|operation not permitted/i', $text)) {
            return ['unknown', null, 'The ping command is not available to the web server — use a port check instead.'];
        }
        if ($code !== 0) {
            return ['down', null, 'No reply from ' . $host];
        }
        $ms = preg_match('/time[=<]\s*([\d.]+)\s*ms/i', $text, $mt) ? (int) round((float) $mt[1]) : $this->ms($start);

        return $ms > $m->limit() ? ['degraded', $ms, "Reply in {$ms} ms (slow)"] : ['up', $ms, "Reply in {$ms} ms"];
    }

    private function tcp(ItMonitor $m): array
    {
        $host = (string) $m->target;
        if (! self::validHost($host) || ! $m->port) {
            return ['down', null, 'Host and port are both needed.'];
        }
        $start = microtime(true);
        $fp = @fsockopen($host, (int) $m->port, $errno, $errstr, max(1, $m->timeout_seconds));
        if (! $fp) {
            return ['down', null, "Port {$m->port} not reachable" . ($errstr ? ': ' . $this->short($errstr) : '')];
        }
        fclose($fp);
        $ms = $this->ms($start);

        return $ms > $m->limit() ? ['degraded', $ms, "Port {$m->port} open, slow ({$ms} ms)"] : ['up', $ms, "Port {$m->port} open in {$ms} ms"];
    }

    private function ssl(ItMonitor $m): array
    {
        $host = preg_replace('#^https?://#i', '', trim((string) $m->target));
        $host = explode('/', $host)[0];
        $port = $m->port ?: 443;
        if (! self::validHost($host)) {
            return ['down', null, 'Not a valid domain.'];
        }
        $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false, 'SNI_enabled' => true, 'peer_name' => $host]]);
        $start = microtime(true);
        $fp = @stream_socket_client("ssl://{$host}:{$port}", $errno, $errstr, max(1, $m->timeout_seconds), STREAM_CLIENT_CONNECT, $ctx);
        if (! $fp) {
            return ['down', null, 'Could not connect: ' . $this->short($errstr ?: 'no response')];
        }
        $cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
        fclose($fp);
        $info = $cert ? openssl_x509_parse($cert) : null;
        if (! $info || empty($info['validTo_time_t'])) {
            return ['down', null, 'No certificate returned.'];
        }
        $expires = Carbon::createFromTimestamp($info['validTo_time_t']);
        $days = (int) floor(now()->diffInDays($expires, false));
        $msg = 'Expires ' . $expires->format('j M Y') . " ({$days} days)";

        return match (true) {
            $days < 0               => ['down', $this->ms($start), 'Certificate EXPIRED on ' . $expires->format('j M Y')],
            $days < $m->limit()     => ['degraded', $this->ms($start), $msg . ' — renew soon'],
            default                 => ['up', $this->ms($start), $msg],
        };
    }

    private function database(ItMonitor $m): array
    {
        $start = microtime(true);
        try {
            DB::select('select 1');
        } catch (Throwable $e) {
            return ['down', null, 'Database not answering: ' . $this->short($e->getMessage())];
        }
        $ms = $this->ms($start);

        return $ms > $m->limit() ? ['degraded', $ms, "Answered slowly ({$ms} ms)"] : ['up', $ms, "Answered in {$ms} ms"];
    }

    private function disk(ItMonitor $m): array
    {
        $path = $this->path($m->target) ?? base_path();
        $total = @disk_total_space($path);
        $free = @disk_free_space($path);
        if (! $total || $free === false) {
            return ['down', null, 'Cannot read disk space for ' . $path];
        }
        $used = round(($total - $free) / $total * 100, 1);
        $msg = "{$used}% used · " . self::bytes($free) . ' free of ' . self::bytes($total);

        return match (true) {
            $used >= max(95, $m->limit()) => ['down', null, $msg . ' — almost full'],
            $used >= $m->limit()          => ['degraded', null, $msg],
            default                       => ['up', null, $msg],
        };
    }

    private function backup(ItMonitor $m): array
    {
        $dir = $this->path($m->target);
        if (! $dir || ! is_dir($dir)) {
            return ['down', null, 'Backup folder not found: ' . ($m->target ?: '(not set)')];
        }
        $newest = null;
        $count = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (! $file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }
            $count++;
            if (! $newest || $file->getMTime() > $newest->getMTime()) {
                $newest = clone $file;
            }
        }
        if (! $newest) {
            return ['down', null, 'No backup files in ' . $m->target];
        }
        $at = Carbon::createFromTimestamp($newest->getMTime());
        $hours = round($at->diffInMinutes(now()) / 60, 1);
        $msg = 'Newest: ' . $newest->getFilename() . ' (' . self::bytes($newest->getSize()) . '), ' . $at->diffForHumans() . " · {$count} file" . ($count === 1 ? '' : 's');

        return match (true) {
            $hours > $m->limit()        => ['down', null, "No new backup for {$hours} hours. " . $msg],
            $newest->getSize() < 1024   => ['degraded', null, 'Newest backup is almost empty. ' . $msg],
            default                     => ['up', null, $msg],
        };
    }

    private function queue(ItMonitor $m): array
    {
        $failed = Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count() : 0;
        $oldest = null;
        if (config('queue.default') === 'database' && Schema::hasTable('jobs')) {
            $ts = DB::table('jobs')->whereNull('reserved_at')->min('available_at');
            $oldest = $ts ? (int) floor((time() - (int) $ts) / 60) : null;
        }
        $msg = "{$failed} failed in 24 h" . ($oldest !== null && $oldest > 0 ? " · oldest waiting job {$oldest} min" : ' · nothing waiting');

        return ($failed > $m->limit() || ($oldest ?? 0) > 30) ? ['degraded', null, $msg] : ['up', null, $msg];
    }

    // ── Alerts ────────────────────────────────────────────────────────

    private function raise(ItMonitor $monitor, string $status, string $message): void
    {
        $severity = $status === 'down' ? $monitor->severity : 'normal';
        $title = $monitor->name . ($status === 'down' ? ' is DOWN' : ' is degraded');
        $alert = $monitor->alerts()->active()->latest('id')->first();

        if ($alert) {
            // Already alerted. If it got worse (degraded → down), raise the severity.
            if ($this->rank($severity) < $this->rank($alert->severity)) {
                $alert->forceFill(['severity' => $severity, 'title' => $title, 'message' => $message])->save();
                $this->email($alert->fresh('monitor'));
            }

            return;
        }

        $alert = ItAlert::create(['monitor_id' => $monitor->id, 'severity' => $severity, 'title' => $title, 'message' => $message, 'status' => 'open', 'opened_at' => now()]);
        $alert->forceFill(['number' => 'AL-' . str_pad((string) $alert->id, 6, '0', STR_PAD_LEFT)])->save();
        Audit::record('it.alert_opened', "{$alert->number}: {$title} — {$message}", $alert, flagged: in_array($severity, ['critical', 'high'], true));
        $this->email($alert->setRelation('monitor', $monitor));
    }

    private function recover(ItMonitor $monitor): void
    {
        foreach ($monitor->alerts()->active()->get() as $alert) {
            $alert->forceFill(['status' => 'resolved', 'resolved_at' => now(), 'auto_resolved' => true,
                'resolution' => 'Recovered by itself after ' . $alert->opened_at->diffForHumans(now(), true) . '.'])->save();
            Audit::record('it.alert_recovered', "{$alert->number}: {$monitor->name} is back up after " . $alert->opened_at->diffForHumans(now(), true), $alert);
        }
    }

    private function email(ItAlert $alert): void
    {
        $to = (array) config('hotel.it.alert_emails', []);
        if (! $to || ! in_array($alert->severity, ['critical', 'high'], true)) {
            return;
        }
        $hotel = config('hotel.name');
        try {
            Mail::raw("{$alert->title}\n\n{$alert->message}\n\nSeverity: {$alert->severity_label}\nSince: " . $alert->opened_at->format('D j M Y, g:i A')
                . "\n\nOpen the IT control room: " . route('it.alerts.index'), fn ($mail) => $mail->to($to)->subject("[{$hotel} IT] {$alert->severity_label}: {$alert->title}"));
        } catch (Throwable $e) {
            Log::warning('IT alert email failed: ' . $e->getMessage());
        }
    }

    // ── Reporting helpers ─────────────────────────────────────────────

    /**
     * Availability per monitor since a time: checks that were up, degraded
     * or under planned maintenance count as available. monitor_id => % (null = no checks).
     */
    public function uptime(Carbon $since, ?Carbon $until = null, ?array $monitorIds = null): array
    {
        $rows = ItCheck::where('checked_at', '>=', $since)->when($until, fn ($q) => $q->where('checked_at', '<', $until))
            ->when($monitorIds !== null, fn ($q) => $q->whereIn('monitor_id', $monitorIds))
            ->where('status', '!=', 'unknown')
            ->groupBy('monitor_id')
            ->selectRaw("monitor_id, count(*) as n, sum(case when status = 'down' then 0 else 1 end) as ok")
            ->get();

        return $rows->mapWithKeys(fn ($r) => [$r->monitor_id => $r->n ? round($r->ok / $r->n * 100, 2) : null])->all();
    }

    /** The last N checks per monitor, oldest first, for the little status bars. */
    public function recent(array $monitorIds, int $n = 30): array
    {
        $out = [];
        foreach ($monitorIds as $id) {
            $out[$id] = ItCheck::where('monitor_id', $id)->latest('checked_at')->limit($n)->get(['status', 'latency_ms', 'message', 'checked_at'])->reverse()->values();
        }

        return $out;
    }

    public function prune(): int
    {
        return ItCheck::where('checked_at', '<', now()->subDays(max(7, (int) config('hotel.it.check_retention_days', 90))))->delete();
    }

    // ── Small helpers ─────────────────────────────────────────────────

    public static function validHost(string $host): bool
    {
        return (bool) (filter_var($host, FILTER_VALIDATE_IP) || preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/i', $host));
    }

    public static function bytes(float|int $b): string
    {
        $u = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($b >= 1024 && $i < count($u) - 1) {
            $b /= 1024;
            $i++;
        }

        return round($b, $i ? 1 : 0) . ' ' . $u[$i];
    }

    private function path(?string $target): ?string
    {
        $target = trim((string) $target);
        if ($target === '') {
            return null;
        }

        return str_starts_with($target, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $target) ? $target : base_path($target);
    }

    private function ms(float $start): int
    {
        return (int) round((microtime(true) - $start) * 1000);
    }

    private function short(string $s): string
    {
        return str($s)->replaceMatches('/\s+/', ' ')->limit(160)->toString();
    }

    private function rank(string $severity): int
    {
        return array_search($severity, ['critical', 'high', 'normal', 'low'], true) ?: 0;
    }
}
