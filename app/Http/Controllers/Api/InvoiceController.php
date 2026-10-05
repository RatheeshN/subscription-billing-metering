<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\InvoiceResource;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvoiceController extends Controller
{
    public function index(Request $request, InvoiceRepositoryInterface $invoices): AnonymousResourceCollection
    {
        return InvoiceResource::collection($invoices->listForMerchant((int) $request->attributes->get('merchant_id')));
    }

    public function show(Request $request, int $invoice, InvoiceRepositoryInterface $invoices): InvoiceResource
    {
        return new InvoiceResource($invoices->forMerchant((int) $request->attributes->get('merchant_id'), $invoice));
    }
}
