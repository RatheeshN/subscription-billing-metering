<?php

return [
    'usage_requests_per_minute' => (int) env('USAGE_REQUESTS_PER_MINUTE', 600),
    'api_requests_per_minute' => (int) env('API_REQUESTS_PER_MINUTE', 120),
    'aggregation_chunk_size' => (int) env('AGGREGATION_CHUNK_SIZE', 1000),
    'aggregation_chunks_per_job' => 50,
    'plan_cache_ttl' => 3600,
    'invoice_grace_seconds' => (int) env('INVOICE_GRACE_SECONDS', 300),
];
