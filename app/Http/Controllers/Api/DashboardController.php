<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function show(Request $request, int $merchant, DashboardService $dashboard): JsonResponse
    {
        abort_unless($merchant === (int) $request->attributes->get('merchant_id'), 404);

        return response()->json($dashboard->get($merchant));
    }
}
