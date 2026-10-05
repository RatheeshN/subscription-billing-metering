<?php

namespace App\Http\Middleware;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMerchant
{
    public function __construct(private MerchantRepositoryInterface $merchants) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        $merchant = is_string($token) && strlen($token) >= 32 && strlen($token) <= 256 ? $this->merchants->findByTokenHash(hash('sha256', $token)) : null;
        if ($merchant === null) {
            return response()->json(['message' => 'A valid merchant bearer token is required.'], 401)->header('WWW-Authenticate', 'Bearer');
        }
        $request->attributes->set('merchant_id', (int) $merchant->id);

        return $next($request);
    }
}
