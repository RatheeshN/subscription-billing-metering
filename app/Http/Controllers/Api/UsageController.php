<?php

namespace App\Http\Controllers\Api;

use App\Actions\RecordUsageAction;
use App\DTOs\RecordUsageDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\RecordUsageRequest;
use App\Http\Resources\UsageEventResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;

class UsageController extends Controller
{
    public function store(RecordUsageRequest $request, RecordUsageAction $action): JsonResponse
    {
        $data = $request->validated();
        $usage = new RecordUsageDTO((int) $request->attributes->get('merchant_id'), (int) $data['customer_id'], (int) $data['units'], CarbonImmutable::parse($data['occurred_at'])->utc(), $data['idempotency_key']);
        $event = $action->handle($usage);

        return (new UsageEventResource($event))->response()->setStatusCode($event->wasRecentlyCreated ? 201 : 200);
    }
}
