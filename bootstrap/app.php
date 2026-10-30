<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Paystack, Twilio and Termii post without a CSRF token; they are checked by signature instead.
        $middleware->validateCsrfTokens(except: ['paystack/webhook', 'twilio/status', 'twilio/inbound', 'termii/events']);

        // Multi-hotel: pick the hotel before route models are loaded (so they come from that hotel only).
        // Then: roles that must use two-step sign-in are sent to set it up first.
        $middleware->web(append: [\App\Http\Middleware\SetCurrentHotel::class, \App\Http\Middleware\EnsureTwoFactorSetUp::class]);
        $middleware->api(append: [\App\Http\Middleware\SetCurrentHotel::class]);
        $middleware->prependToPriorityList(before: \Illuminate\Routing\Middleware\SubstituteBindings::class, prepend: \App\Http\Middleware\SetCurrentHotel::class);

        // WebRTC call set-up (SDP) must keep its exact line endings: trimming the last CRLF breaks every call.
        $middleware->trimStrings(except: ['payload.sdp', 'payload.candidate']);

        // Signed-in users go to the dashboard instead of the login page.
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The mobile API always answers in JSON, even without an Accept header.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

        // A business rule said no (e.g. "someone else must approve it"): show the reason in the app.
        $exceptions->render(fn (\App\Exceptions\BookingException $e, $request) => $request->is('api/*')
            ? response()->json(['success' => false, 'code' => 'not_allowed_now', 'message' => $e->getMessage()], 422)
            : null);

        // Fallback for an expired session / CSRF token anywhere else.
        // Laravel turns TokenMismatchException into an HttpException(419)
        // before render callbacks run, so match the 419 status here.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e, $request) {
            if ($e->getStatusCode() !== 419) {
                return null; // let Laravel handle every other HTTP error
            }
            $intendedUrl = $request->fullUrl();

            if ($request->expectsJson()) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Session expired. Please login again.',
                    'redirect' => route('login', [], false)
                ], 419);
            }

            if (Auth::check()) {
                Auth::logout();
            }

            $request->session()->flush();
            $request->session()->regenerate();

            return redirect()->route('login')
                ->with('session_expired', true)
                ->with('error', 'Your session has expired. Please login again.')
                ->with('intended', $intendedUrl);
        });
    })->create();