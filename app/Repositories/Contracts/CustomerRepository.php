<?php

namespace App\Repositories\Contracts;

use App\Models\Customer;

interface CustomerRepository
{
    public function findForMerchantOrFail(int $merchantId, int $customerId): Customer;

    public function lockForMerchantOrFail(int $merchantId, int $customerId): Customer;
}
