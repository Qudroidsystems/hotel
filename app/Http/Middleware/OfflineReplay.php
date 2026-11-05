<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Makes an action safe to send again. The staff app and the website keep an
 * action that couldn't be sent (no connection) and send it when the
 * connection is back, with a header X-Offline-Id naming it. If the hotel
 * system already did it — the first try got through but the answer was lost —
 * the saved answer is sent back instead of doing it twice.
 *
 * X-Offline-At says when the button was really pressed; screens that care
 * (a driver's "arrived") read it from the request as `offline_at`.
 * Requests without the header are not touched.
 */
class OfflineReplay
{
    public function handle(Request $request, Closure $next): Response
    {
        $id = (string) $request->header('X-Offline-Id', '');
        if ($request->isMethodSafe() || $id === '' || ! preg_match('/^[A-Za-z0-9_.:-]{8,64}$/', $id)) {
            return $next($request);
        }
        if ($at = $request->header('X-Offline-At')) {
            try {
                $when = Carbon::parse($at)->setTimezone(config('app.timezone'));
                if ($when->lte(now()->addMinute()) && $when->gte(now()->subDays(2))) {
                    $request->attributes->set('offline_at', $when);
                }
            } catch (Throwable) {
            }
        }
        $userId = $request->user()?->getAuthIdentifier();
        $key = ($userId ?? 'guest') . ':' . $id;

        if ($done = DB::table('offline_requests')->where('key', $key)->first()) {
            return $this->replay($done);
        }
        try {
            $rowId = DB::table('offline_requests')->insertGetId([
                'key' => $key, 'user_id' => $userId, 'method' => $request->method(), 'path' => mb_substr($request->path(), 0, 255),
                'status' => 0, 'happened_at' => $request->attributes->get('offline_at'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Its twin arrived at the same moment.
            $done = DB::table('offline_requests')->where('key', $key)->first();

            return $done ? $this->replay($done) : response()->json(['message' => 'Already being saved.', 'code' => 'in_progress'], 409);
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            DB::table('offline_requests')->where('id', $rowId)->delete();
            throw $e;
        }

        if (! $request->is('api/*')) {
            $response = $this->forTheBrowser($request, $response);
        }
        $status = $response->getStatusCode();
        if ($status >= 500 || $status === 419 || $status === 429 || $status === 401) {
            // Not done: let it be sent again.
            DB::table('offline_requests')->where('id', $rowId)->delete();
        } else {
            $body = $response->isRedirection() ? null : (string) $response->getContent();
            DB::table('offline_requests')->where('id', $rowId)->update([
                'status' => $status, 'body' => $body !== null && strlen($body) <= 200000 ? $body : null,
                'content_type' => mb_substr((string) $response->headers->get('Content-Type'), 0, 100), 'updated_at' => now(),
            ]);
        }

        return $response;
    }

    /**
     * A website form sent later by the page's script: its usual answer is "go back to the page" with a
     * message in the session. Turn that into {ok, message} the script can show, and take the message out
     * of the session so it doesn't pop up again on the next page.
     */
    private function forTheBrowser(Request $request, Response $response): Response
    {
        if (! $response->isRedirection() || ! $request->hasSession()) {
            return $response;
        }
        $session = $request->session();
        $errors = $session->get('errors');
        $error = $session->get('error') ?? ($errors && method_exists($errors, 'first') ? $errors->first() : null);
        $message = $error ?? $session->get('success') ?? $session->get('info') ?? $session->get('status') ?? 'Saved.';
        foreach (['success', 'error', 'info', 'status', 'errors', '_old_input'] as $k) {
            $session->forget($k);
        }
        $session->put('_flash.new', array_values(array_diff((array) $session->get('_flash.new', []), ['success', 'error', 'info', 'status', 'errors', '_old_input'])));

        return response()->json(['ok' => $error === null, 'message' => is_string($message) ? $message : 'Saved.'], $error === null ? 200 : 422);
    }

    private function replay(object $done): Response
    {
        if ((int) $done->status === 0) {
            return response()->json(['message' => 'Already being saved.', 'code' => 'in_progress'], 409);
        }
        $body = $done->body ?? json_encode(['success' => true, 'message' => 'Already saved.']);

        return response($body, (int) $done->status ?: 200, ['Content-Type' => $done->content_type ?: 'application/json', 'X-Offline-Replayed' => '1']);
    }

    /** Old answers are no longer needed (run daily). */
    public static function prune(int $days = 30): int
    {
        return DB::table('offline_requests')->where('created_at', '<', now()->subDays($days))->delete();
    }
}
