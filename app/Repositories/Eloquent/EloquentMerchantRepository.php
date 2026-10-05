<?php

namespace App\Repositories\Eloquent;

use App\Models\Merchant;
use App\Repositories\Contracts\MerchantRepositoryInterface;

class EloquentMerchantRepository implements MerchantRepositoryInterface
{
    public function findByTokenHash(string $hash): ?Merchant
    {
        return Merchant::query()->where('api_token_hash', $hash)->first();
    }

    public function rotateToken(int $merchantId, string $hash): void
    {
        Merchant::query()->findOrFail($merchantId)->update(['api_token_hash' => $hash]);
    }
}
