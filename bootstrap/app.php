<?php

use App\Exceptions\ChangeRequiresApprovalException;
use App\Exceptions\WorkloadLimitException;
use App\Http\Middleware\CompressResponse;
use App\Http\Middleware\EnsurePortalAccountActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'portal.active' => EnsurePortalAccountActive::class,
        ]);

        // Outermost, so it sees the finished body of every response.
        $middleware->append(CompressResponse::class);

        // Unauthenticated requests to a /portal/* route must bounce to the
        // portal login, never the staff one (and vice versa) — without this,
        // Laravel's default redirectTo() always points at the staff 'login'
        // route regardless of which guard rejected the request.
        $middleware->redirectGuestsTo(function ($request) {
            return $request->is('portal*') ? route('portal.login') : route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ChangeRequiresApprovalException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'pending' => true, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        });

        $exceptions->render(function (WorkloadLimitException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
            }

            return back()->with('error', $e->getMessage());
        });
    })->create();
