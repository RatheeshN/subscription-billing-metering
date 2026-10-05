<?php

declare(strict_types=1);

namespace App\DTOs;

use Carbon\CarbonImmutable;

final readonly class RecordUsageDTO
{
    public function __construct(
        public int $merchantId,
        public int $customerId,
        public int $units,
        public CarbonImmutable $occurredAt,
        public string $idempotencyKey,
    ) {}
}
