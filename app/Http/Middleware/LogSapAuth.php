<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class LogSapAuth
{
    public function handle(Request $request, Closure $next)
    {
        Log::info('SAP Auth Attempt', [
            'url' => $request->fullUrl(),
            'method' => $request->method(),
            'has_bearer' => $request->bearerToken() ? 'yes' : 'no',
            'token_preview' => $request->bearerToken() 
                ? substr($request->bearerToken(), 0, 20) 
                : null,
            'guard' => 'sap',
            'user' => $request->user('sap') ? $request->user('sap')->user_id : 'not authenticated'
        ]);

        return $next($request);
    }
}