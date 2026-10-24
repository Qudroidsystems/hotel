<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Auth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role'               => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission'         => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
        ]);

        // Signed-in users go to the dashboard instead of the login page.
        $middleware->redirectUsersTo('/dashboard');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // The mobile API always answers in JSON, even without an Accept header.
        $exceptions->shouldRenderJsonWhen(fn ($request, $e) => $request->is('api/*') || $request->expectsJson());

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