<?php

namespace App\Http\Controllers\Api;

use App\Actions\StartSubscriptionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\StartSubscriptionRequest;
use App\Http\Resources\SubscriptionResource;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    public function store(StartSubscriptionRequest $request, StartSubscriptionAction $action): JsonResponse
    {
        $data = $request->validated();
        $subscription = $action->handle((int) $request->attributes->get('merchant_id'), (int) $data['customer_id'], (int) $data['plan_id'], isset($data['starts_at']) ? CarbonImmutable::parse($data['starts_at'])->utc() : CarbonImmutable::now('UTC')->startOfSecond());

        return (new SubscriptionResource($subscription))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $subscription, SubscriptionRepositoryInterface $subscriptions): SubscriptionResource
    {
        return new SubscriptionResource($subscriptions->forMerchant((int) $request->attributes->get('merchant_id'), $subscription));
    }
}
