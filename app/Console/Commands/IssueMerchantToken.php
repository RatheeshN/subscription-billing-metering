<?php

namespace App\Console\Commands;

use App\Repositories\Contracts\MerchantRepositoryInterface;
use Illuminate\Console\Command;

class IssueMerchantToken extends Command
{
    protected $signature = 'merchant:token {merchant : Merchant ID}';

    protected $description = 'Rotate a merchant bearer token and display it once';

    public function handle(MerchantRepositoryInterface $merchants): int
    {
        $token = bin2hex(random_bytes(32));
        $merchants->rotateToken((int) $this->argument('merchant'), hash('sha256', $token));
        $this->info('Store this token securely; rotating it invalidates the previous token.');
        $this->line($token);

        return self::SUCCESS;
    }
}
