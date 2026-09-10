<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A stale CSRF token (idle tab, Back button after logout) should not
        // dump the raw "419 Page Expired" page — bounce back to login with a
        // fresh token and a note.
        $exceptions->render(function (TokenMismatchException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Sesi kedaluwarsa.'], 419);
            }

            return redirect()->route('login')
                ->withInput($request->except('password', '_token'))
                ->with('status', 'Sesi kedaluwarsa — silakan masuk lagi.');
        });
    })->create();
