<?php

namespace App\Repositories\Contracts;

use App\Models\Merchant;

interface MerchantRepositoryInterface
{
    public function findByTokenHash(string $hash): ?Merchant;

    public function rotateToken(int $merchantId, string $hash): void;
}
