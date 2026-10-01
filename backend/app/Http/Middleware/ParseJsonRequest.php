<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ParseJsonRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        Log::info('ParseJsonRequest middleware called', [
            'is_json' => $request->isJson(),
            'content_type' => $request->header('Content-Type'),
            'content' => $request->getContent(),
        ]);
        
        if ($request->isJson()) {
            $json = $request->getContent();
            if ($json) {
                $data = json_decode($json, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    $request->merge($data);
                    Log::info('Merged JSON data', ['data' => $data]);
                }
            }
        }

        return $next($request);
    }
}