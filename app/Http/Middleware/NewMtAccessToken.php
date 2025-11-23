<?php

namespace App\Http\Middleware;

use App\Exceptions\ForbiddenException;
use Closure;
use Illuminate\Http\Request;

class NewMtAccessToken
{
    /**
     * @param Request $request
     * @param Closure $next
     * @return mixed
     * @throws ForbiddenException
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $token = $request->bearerToken();
        $validToken = env('NEW_MT_OUTER_TOKEN_1');

        if (!$token || $token !== $validToken) {
            throw new ForbiddenException('Доступ запрещён');
        }

        return $next($request);
    }
}
