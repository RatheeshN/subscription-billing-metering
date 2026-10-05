<?php

namespace App\Repositories\Eloquent;

use App\Models\Customer;
use App\Repositories\Contracts\CustomerRepository;

class EloquentCustomerRepository implements CustomerRepository
{
    public function lockForMerchantOrFail(int $merchantId, int $customerId): Customer
    {
        return Customer::query()->where('merchant_id', $merchantId)->whereKey($customerId)->lockForUpdate()->firstOrFail();
    }

    public function findForMerchantOrFail(int $merchantId, int $customerId): Customer
    {
        return Customer::query()
            ->where('merchant_id', $merchantId)
            ->whereKey($customerId)
            ->firstOrFail();
    }
}
