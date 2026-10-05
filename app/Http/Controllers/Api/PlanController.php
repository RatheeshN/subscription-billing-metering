<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SavePlanRequest;
use App\Http\Resources\PlanResource;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Services\PlanPricingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PlanController extends Controller
{
    public function index(Request $request, PlanRepositoryInterface $plans): AnonymousResourceCollection
    {
        return PlanResource::collection($plans->listForMerchant((int) $request->attributes->get('merchant_id')));
    }

    public function store(SavePlanRequest $request, PlanPricingService $pricing): JsonResponse
    {
        return (new PlanResource($pricing->save((int) $request->attributes->get('merchant_id'), null, $request->validated())))->response()->setStatusCode(201);
    }

    public function update(SavePlanRequest $request, int $plan, PlanPricingService $pricing): PlanResource
    {
        return new PlanResource($pricing->save((int) $request->attributes->get('merchant_id'), $plan, $request->validated()));
    }
}
