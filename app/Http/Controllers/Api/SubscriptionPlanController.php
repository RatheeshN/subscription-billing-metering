<?php

namespace App\Http\Controllers\Api;

use App\Actions\ChangeSubscriptionPlanAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeSubscriptionPlanRequest;
use App\Http\Resources\SubscriptionResource;

class SubscriptionPlanController extends Controller
{
    public function update(ChangeSubscriptionPlanRequest $request, int $subscription, ChangeSubscriptionPlanAction $action): SubscriptionResource
    {
        return new SubscriptionResource($action->handle((int) $request->attributes->get('merchant_id'), $subscription, (int) $request->validated('plan_id')));
    }
}
