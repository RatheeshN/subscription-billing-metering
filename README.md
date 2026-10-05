# Subscription billing and usage metering

A Laravel 13 / PHP 8.4 backend for a multi-tenant subscription business. MySQL 8 with InnoDB stores usage, immutable pricing snapshots, daily aggregates and finalized invoices. Redis is supported through Predis for shared cache, locks, rate limits and queues. The local WAMP setup uses database cache and queues so it runs without a Redis server.

## Quick start

Requirements: PHP 8.4, Composer, MySQL 8, PDO MySQL, and the usual Laravel PHP extensions. The current database configuration uses PHP 8.4's PDO driver class. No frontend build is required for the API. For the browser dashboard, run `npm ci` and `npm run build` after installing Node.js supported by the installed Vite version.

```sh
composer install
# Copy .env.example to .env if .env does not exist.
php artisan key:generate
# Create the configured MySQL database before migrating.
php artisan migrate
php artisan db:seed --class=BillingDemoSeeder
php artisan merchant:token 1
php artisan serve
```

On the existing workspace, `.env` connects to `mp` on `127.0.0.1:3306`. WAMP uses local root credentials with an empty password; use a dedicated database account and secret outside development. The MySQL connection explicitly selects InnoDB in `config/database.php`, so schema creation does not depend on the server's default engine. The seeder prints the actual merchant IDs. Supply that ID to `merchant:token`; the example assumes an empty database and ID 1.

Run these in separate terminals:

```sh
php artisan queue:work --queue=billing,metering,default --sleep=1 --timeout=60 --tries=5
php artisan schedule:work
```

For deployed environments, point the web server to `public/`, set `APP_DEBUG=false`, serve HTTPS, supervise queue workers, and install a cron entry:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

The scheduler dispatches aggregation every minute and due billing every five minutes. Scheduler nodes share a cache and use `onOneServer()` locks. Invoice dispatch also uses `withoutOverlapping()`. Run `php artisan billing:dispatch` to queue due invoices manually. Each pass dispatches at most one due cycle per subscription; repeated passes catch up missed cycles in chronological order. Inspect schedules with `php artisan schedule:list` and failures with `php artisan queue:failed`.

## Redis configuration

Predis is installed; a PHP Redis extension is optional. If Docker Compose is available:

```sh
docker compose up -d redis
```

Set these in `.env` and clear the configuration cache:

```dotenv
CACHE_STORE=redis
QUEUE_CONNECTION=redis
REDIS_CLIENT=predis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_QUEUE_RETRY_AFTER=180
```

```sh
php artisan config:clear
php artisan queue:restart
```

The supplied Compose file binds Redis to localhost and persists its append-only log. It is a development service; production should use a secured shared Redis deployment with backups and queue monitoring. Database queues/cache remain supported for local development. `array` cache and `sync` queue are used in tests only. Job timeout is 60 seconds; queue `retry_after` is 180 seconds. A reservation must outlast the worker timeout. See the [Laravel queue documentation](https://laravel.com/framework/docs/13.x/queues) for operation and supervision.

## Architecture and repository pattern

Requests validate input, merchant authentication derives the tenant from a bearer token, controllers call a focused action/service, and API Resources serialize responses. Actions coordinate transactions. Services own aggregation, pricing, billing and projection rules. Repositories contain persistence, locking and aggregate queries. Contracts are bound in `AppServiceProvider` and injected through constructors.

```text
app/
  Actions/                    record usage, start subscription, change plan
  DTOs/                       typed usage, pricing and calculated invoice lines
  Enums/                      monthly/yearly billing cycles
  Exceptions/                 expected billing conflicts (HTTP 409)
  Http/Controllers/Api/       thin API controllers
  Http/Middleware/            merchant bearer authentication
  Http/Requests/              Form Requests
  Http/Resources/             explicit public response fields
  Jobs/                       bounded aggregation and invoice generation
  Models/                     relationships, casts, mass-assignment rules
  Observers/                  plan cache invalidation
  Repositories/Contracts/     domain operations
  Repositories/Eloquent/      Eloquent implementations and optimized SQL
  Services/                   calculations and workflows
  Support/                    immutable UTC billing period
  Console/Commands/           due invoice dispatch and token rotation
```

Meaningful repository operations include `recordUsage`, `processPendingChunk`, `totalsForSegments`, `getTopCustomersByUsage`, `getChurnRiskCustomers`, `finalize` and `eachDue`. There is no generic CRUD base repository. `BillingCalculator` is independent of persistence and uses Brick Math arbitrary-precision integer arithmetic. Repositories return Eloquent domain models where that is useful; the pattern is a boundary around queries, not a claim of zero coupling to Laravel.

The earlier `CustomerRepository`, `UsageEventRepository` and date-only `UsageService` are preserved for compatibility. The metered API uses `UsageEventRepositoryInterface` and `EloquentMeteredUsageEventRepository`. Historical rows without a pricing segment are deliberately excluded from billing aggregation. Do not use the legacy service for newly billable usage.

## Schema and indexes

| Table | Purpose and important constraints/indexes |
| --- | --- |
| merchants | Tenant and unique nullable SHA-256 token hash; plaintext tokens are never persisted |
| plans | Merchant pricing; unique `(merchant_id, name)` |
| customers | Tenant-owned identity; unique `(merchant_id, email)` |
| subscriptions | One subscription per customer; due-cycle index on `cycle_ends_at`; `(merchant_id, id)` for scoped enumeration |
| subscription_segments | Effective-dated immutable pricing snapshots; unique `(subscription_id, starts_at)` |
| usage_events | Append-oriented events; unique `(customer_id, idempotency_key)`; `(aggregated_at, id)` for pending work; `(subscription_segment_id, aggregated_at, id)` for billing drain; `(subscription_segment_id, occurred_at)` for plan-change safety; `(merchant_id, occurred_at, id)` for audit/archival; existing `(customer_id, usage_date)` retained |
| daily_usage_aggregates | Unique `(subscription_segment_id, usage_date)`; `(merchant_id, usage_date, customer_id)` for dashboard windows; `(customer_id, usage_date)` |
| invoices | Unique `(subscription_id, cycle_starts_at)`; `(merchant_id, id)` for tenant invoice lists |
| invoice_lines | Per-segment monetary result and proration audit inputs; unique `(invoice_id, subscription_segment_id)` |

All IDs and aggregate quantities use appropriate bigint columns; each event is capped at 2,147,483,647 positive units. Money uses unsigned bigint minor units and micros, and calculations fail rather than silently overflow the PHP integer result. Segment/date uniquely determines its subscription, customer and merchant, so the daily aggregate key is equivalent to the suggested larger merchant/customer/segment/date key without repeating all four values in the unique index. Foreign keys restrict deletion of billing history. Merchant/customer fields on events and daily totals are deliberate denormalization for ingestion auditing and tenant/window reporting.

Tenant and temporal consistency are enforced at application repository/action boundaries, using scoped lookups and transaction locks. SQL foreign keys alone do not prevent someone with direct database access from constructing an inconsistent cross-tenant history. Invoice generation rejects gaps and overlaps in pricing history. Administration/imports must use the same application boundaries.

## Five million usage rows and further growth

No dashboard request scans raw events. Pending-event work uses indexed null markers and bounded ordered chunks; finalized-cycle usage reads the much smaller segment/day table. Daily totals reduce one customer's many API events to a row per pricing segment per UTC day. Events are assigned to a segment once at ingestion, so billing never needs a temporal join over millions of raw events.

The normal write path adds one event and updates its aggregation marker once. Batch aggregation combines events with the same day/segment before incrementing the daily total. Additional indexes have write/storage costs; use production-like data, `EXPLAIN ANALYZE`, slow-query logs and observed tenant distribution before adding more. Five million rows is a schema/query target, not a benchmark performed here.

Review on MySQL 8.4.7 confirmed that the pending-event query uses `usage_pending_aggregation` with `ref` access and the merchant/month usage query uses `daily_merchant_date_customer` with `range` access. Ranking summed customer totals still needs a temporary grouping/sort; this operates on the filtered daily table. These plans were checked on demo data, so their estimated rows and timing do not establish five-million-row throughput.

Future work: shard queue jobs by merchant/customer, batch inserts, separate the ingestion and reporting databases, archive finalized event data, and retain compact idempotency tombstones if raw events are removed. Otherwise an old retry could become a new event after archival. Partitioning raw events by `occurred_at` requires a schema redesign: MySQL partitioned InnoDB tables have foreign-key restrictions, and partition columns must participate in every unique key. Simply adding a date to the idempotency key would weaken retry semantics. A separate idempotency ledger and revised event foreign-key strategy should precede partitioning. See [MySQL partitioning limitations](https://dev.mysql.com/doc/refman/8.4/en/partitioning-limitations.html) and [unique-key requirements](https://dev.mysql.com/doc/refman/8.4/en/partitioning-limitations-partitioning-keys-unique-keys.html).

Legacy unsegmented rows should be archived or separately migrated before operating at scale; they are skipped by the worker and must not dominate its pending-index scan. A large rollout should stage new columns, indexes and backfills rather than applying this small-project migration directly to a busy 5M-row table.

## Authentication and API

Every `/api` endpoint requires:

```http
Authorization: Bearer <merchant-token>
Accept: application/json
Content-Type: application/json
```

`php artisan merchant:token <merchant-id>` issues a 256-bit random token, displays it once and stores only its SHA-256 hash. Rotation invalidates the old token. Tokens currently grant access to the whole merchant API; scoped tokens and a user/role management layer are future work. Merchant IDs supplied in request bodies are ignored. Missing/invalid credentials return 401; another tenant's record returns 404.

| Method and path | Operation |
| --- | --- |
| POST `/api/usage` | Append an idempotent usage event |
| GET / POST `/api/plans` | Paginated plans / create pricing |
| PUT `/api/plans/{plan}` | Update pricing with cache invalidation |
| POST `/api/subscriptions` | Start a customer's subscription |
| GET `/api/subscriptions/{subscription}` | Inspect subscription/pricing segments |
| PUT `/api/subscriptions/{subscription}/plan` | Change plan immediately |
| GET `/api/merchants/{merchant}/dashboard` | Aggregate-based merchant metrics |
| GET `/api/invoices` | Paginated finalized invoices |
| GET `/api/invoices/{invoice}` | Invoice with pricing/proration lines |

Paths follow the requested unversioned `/api` contract. Versioning should precede any incompatible public change. Customer provisioning is supplied by factories/seeders in this exercise; an identity/customer-management API is outside the requested endpoint scope.

Example usage:

```json
{
  "customer_id": 1,
  "units": 50,
  "occurred_at": "2026-10-01T10:30:00Z",
  "idempotency_key": "evt_abc123"
}
```

Use a timestamp at or before the current UTC time and within the customer's unfinalized subscription history. A new event returns 201 with a `data` resource; an identical retry returns 200 and the original event ID. A changed payload under the same key returns 409. Validation errors return 422. Keys are 1–100 ASCII letters/digits or `._:-`, with case-sensitive database collation. Timestamps require an explicit zone and whole seconds; fractional seconds are rejected. Input offsets are normalized to UTC before comparison/storage.

Example plan:

```json
{
  "name": "Starter",
  "currency": "INR",
  "billing_cycle": "monthly",
  "base_price_minor": 10000,
  "included_usage_units": 1000,
  "overage_rate_micros": 1000
}
```

`10000` minor units means INR 100.00. `1000` micros means INR 0.001 per usage unit. INR, USD, EUR and GBP are supported; each has two decimal places. Monthly and yearly calendar cycles are supported. Example subscription body: `{"customer_id":1,"plan_id":1,"starts_at":"2026-10-01T00:00:00Z"}`. Omitting `starts_at` starts now. Plan-change body: `{"plan_id":2}`. Changes retain currency and cycle; a conversion between monthly/yearly or currencies is rejected with 409.

## Ingestion and idempotency

1. Apply an IP limiter, authenticate the token and apply the merchant's usage budget.
2. Validate the Form Request and build `RecordUsageDTO`.
3. Return a matching existing event quickly when possible.
4. In a short transaction, lock the customer's subscription, recheck retry status and reject already finalized timestamps.
5. Find the inclusive-start/exclusive-end pricing segment and insert through `createOrFirst`.
6. Return an explicit resource. No aggregate query, billing work or per-event queue dispatch occurs here.

The database unique constraint, not the preliminary retry lookup, is authoritative. A duplicate-key race resolves to the original row, whose units and normalized timestamp must still match. Keys are scoped per customer; different customers may reuse the same key. A retry remains valid after its invoice has finalized.

The subscription row lock serializes ingestion, plan changes and finalization for that customer. Different customers can ingest concurrently. This intentionally trades maximum throughput for an understandable finalization guarantee; an extremely hot customer needs a different ingestion/cutoff architecture. Transactions retry deadlocks up to five times.

## Aggregation and queue decisions

`AggregateDailyUsageJob` processes up to 50 chunks per job, with 1,000 events per chunk by default (`AGGREGATION_CHUNK_SIZE`). A chunk locks pending rows in ascending ID order, groups them by segment/day, safely creates a daily total if absent, atomically increments it, and marks those event IDs as aggregated in the same transaction. No offset pagination is used. An interrupted/failed chunk rolls back both the totals and markers. Committed chunks are skipped on retry; new late events in an unfinalized cycle increment the existing daily row once.

Workers may contend on the same oldest rows; ordered locks, atomic increments and transaction retries preserve totals. A completed bounded job dispatches a continuation when needed. The 1,000-row default bounds memory and the SQL `WHERE IN` marker update; tune it against lock duration, DB latency and event volume. This is incremental aggregation, not a rebuild from raw history. Rebuilding must reset markers and totals together under an operational lock; deleting only daily totals would lose committed usage.

Dashboard data is eventually consistent with the worker, normally around the one-minute scheduling delay plus queue lag. Before finalization, the billing job drains remaining pending events for its subscription while holding the subscription lock. Normally these are a small tail because background aggregation is running. A large backlog produces a longer invoice transaction; pre-aggregation and queue-lag monitoring are necessary in production.

## Billing and exact proration

UTC calendar periods are `[month start, next month start)` or `[year start, next year start)`. All starts are inclusive and ends exclusive. A mid-cycle start is billed only from the subscription start to the calendar boundary. Leap years and different month lengths are handled by actual period timestamps.

For each intersecting pricing segment:

```text
segment_start = max(segment.starts_at, cycle.start)
segment_end   = min(segment.ends_at or cycle.end, cycle.end)
duration      = segment_end_timestamp - segment_start_timestamp
cycle_seconds = cycle.end_timestamp - cycle.start_timestamp

base_minor        = round_half_up(base_price_minor * duration / cycle_seconds)
included_units    = floor(plan_included_units * duration / cycle_seconds)
usage_units       = aggregate total for this segment within the cycle
overage_units     = max(0, usage_units - included_units)
overage_minor     = round_half_up(overage_units * overage_rate_micros / 10000)
line_total_minor  = base_minor + overage_minor
invoice_total     = sum(line_total_minor)
```

One currency unit contains 100 minor units or 1,000,000 micros. Overage is rounded once per segment after multiplying all its overage units, not once per event. Base rounding and allowance flooring also happen per segment, without redistributing rounding residue. This can differ by cents/units from rounding a blended invoice only once; the chosen rule is deterministic and visible in invoice lines. Unused allowance is not shared between segments or carried to another cycle.

Example: a 30-day cycle, INR 100.00 base, 100 included units, INR 0.01 per overage unit, starting halfway through the cycle with 80 units: base 5,000 minor, allowance 50, overage 30, total 5,030 minor (INR 50.30).

All multiplication/division uses arbitrary-precision integers, then safely converts final results to PHP integers. No floating-point billing operations are used. Extremely large results outside the supported integer range fail the transaction rather than save a rounded/corrupt value.

## Mid-cycle upgrade and downgrade

A plan change locks the subscription, closes its open segment at the current UTC second, and opens a new segment with a copy of plan ID/name/base/allowance/rate. Already recorded events retain their original segment; new events are assigned from their occurrence time, including late arrivals for older unfinalized segments. An event exactly at the change time belongs to the new segment.

Plan edits affect future subscriptions/changes only. They do not mutate existing segment snapshots. Repeating a request for the currently selected plan does not add a segment or refresh its historical price. Backdated/scheduled changes are deliberately not exposed. A change in the same second as already recorded old-plan usage is rejected so existing events cannot contradict the inclusive/exclusive boundary; retry in the next second.

## Invoice finalization and late arrivals

Finalization reads daily totals with locking current reads, avoiding stale totals under MySQL REPEATABLE READ when another aggregator commits after the invoice transaction snapshot. A queued invoice job carries the subscription ID and original cycle start. It locks the subscription, returns any existing invoice for that cycle, validates chronological processing and the grace window, drains pending usage, validates contiguous history, writes the invoice and its lines, and advances the subscription cursor in one transaction. The unique subscription/cycle constraint is a second defense against duplicate invoices. Job dispatch uniqueness is an optimization, not the integrity guarantee.

The default grace window is 300 seconds after cycle end (`INVOICE_GRACE_SECONDS`). Before actual finalization, late usage can still enter that cycle. Once finalized, new events earlier than the subscription cursor return 409; identical retries still return their original result. The exercise has no credit-note/revision flow. Production systems needing later corrections should append adjustments rather than rewrite finalized invoices.

## Plan cache and invalidation

`PlanPricingService` caches scalar pricing arrays and reconstructs typed DTOs on retrieval; this respects Laravel 13 persistent-cache unserialization restrictions. Cache-aside keys are `merchant:{merchantId}:plan:{planId}:pricing`, with a one-hour TTL. Cache reads and supported pricing writes share a short cache lock to prevent a stale read from repopulating the key during an update. `PlanObserver` explicitly forgets the key after a successful database commit. Rolled-back changes do not invalidate the committed pricing. The TTL is a fallback, not the update mechanism. Updates use the pricing service; bulk SQL updates bypass observers and are unsupported for pricing administration. Lock leases should exceed measured pricing write latency.

## Dashboard definitions

- **Top five:** total aggregated usage for the current UTC calendar month, ordered by units descending, customer ID ascending for ties. Includes all pricing segments; limited to the authenticated merchant.
- **Projected overage:** per currency, apply actual usage to completed segments in the current cycle. For an active segment, predict `round_half_up(units_so_far * planned_segment_seconds / elapsed_segment_seconds)` and apply its full prorated allowance and rate with the same billing calculator. A zero-elapsed segment contributes zero. It assumes the current rate continues and no future plan change. Month-to-date/year-to-date history is selected according to each subscription's cycle. Currency amounts are integer minor units and are never added across currencies.
- **Churn risk:** compare the most recent two completed UTC calendar months. Include customers with positive usage in the earlier month and `recent_units * 2 < previous_units`. Exactly 50% and zero earlier usage are excluded; a customer with earlier usage and no recent rows is included. Full completed months are comparable windows, though their lengths can differ. The current partial month is excluded. The response returns up to 100 customers, ordered by ID, with the limit and window dates explicitly included.

Projection streams subscriptions in chunks of 100 with eager-loaded relevant segments and a grouped aggregate query per cycle type per chunk. It keeps a running integer total per currency instead of retaining every segment charge in memory. Top/churn queries group the indexed daily table and join customer names. No raw-event scans or per-customer aggregate queries occur in the dashboard.

## Rate limits

Usage defaults to 600 requests/minute per authenticated merchant, including retries. Other merchant endpoints default to 120/minute. A preliminary 1,200/minute IP limit protects authentication queries. These values are configurable, except the fixed preliminary limit. Rate-limit responses are HTTP 429 with retry headers. Shared cache is required across web instances; array cache is unsuitable for deployment. Tune limits and add upstream protection for actual traffic. See [Laravel routing rate limits](https://laravel.com/framework/docs/routing).

## Tests

Fast suite (SQLite in-memory, real repository queries):

```sh
php artisan test --compact
```

MySQL suite uses **only the disposable `mp_test` database** configured in `phpunit.mysql.xml`:

```sql
CREATE DATABASE mp_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```sh
php vendor/bin/phpunit --configuration=phpunit.mysql.xml
```

Adjust the test-only connection credentials if your local MySQL differs. Never point this config at application data: schema-refresh traits rebuild the test database. The MySQL suite releases separate PHP processes behind a barrier to exercise concurrent duplicate ingestion, aggregation and invoice generation against actual InnoDB locks. Another regression verifies current aggregate reads after an older transaction snapshot. These four tests are skipped in SQLite.

Use PHPUnit directly for the alternative configuration: the installed Artisan test wrapper also supplies `phpunit.xml`, so passing `--configuration` to it produces a duplicate-option warning. Agent output can hide that warning while all test assertions pass.

Coverage includes authentication, cross-tenant access, invalid inputs, rate limits, exact retry payloads, timezone normalization, chunk boundaries/retries, aggregation of late unbilled events, monthly/yearly billing, no/exact/excess allowance, mid-cycle start, upgrade/downgrade, multiple segments, boundary timestamps, immutable snapshots, money rounding/large intermediates, invoice retries, transaction rollback on line-write failure, late-event rejection, cache commit/rollback invalidation, dashboard windows/currencies and demo data.

Final review on PHP 8.4.15 / Laravel 13.34.0 / MySQL 8.4.7: **128 tests passed, 412 assertions**, including four MySQL-specific concurrency/isolation tests. The SQLite suite passes **124 tests, 368 assertions**, with those four MySQL-only tests skipped. The final review added eight cases covering real plan-change actions for upgrades/downgrades with late pre-change usage, closed versus active segment projections, a zero-elapsed plan-change boundary, yearly projection windows, cache hits without plan queries, required usage fields, and churn tenant isolation/no history. Existing coverage also exercises aggregation rollback/retry, simultaneous invoice generation, zero-event billing, case-sensitive retry keys, pre-subscription events, churn thresholds and projection chunk boundaries. Formatting passes, `npm run build` succeeds, and `composer audit --no-interaction` reports no security vulnerability advisories. No PHPStan/Larastan configuration is installed. The workspace has no Git metadata, so Pint's `--dirty` option cannot run; the changed test files were formatted explicitly and the complete `php vendor/bin/pint --test` check passes.

The frontend build emits an optional `fontaine` warning about optimized font fallbacks; assets are generated successfully without that package. No dependency was added for this optional optimization.

`composer install --no-interaction`, `php artisan optimize:clear --no-interaction`, route/schedule inspection, and the complete formatting check succeeded. For the destructive smoke check, the command process explicitly set `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_DATABASE=mp_test`, `DB_URL=` and database cache/queues, verified the resolved database name, then ran `php artisan migrate:fresh --seed --no-interaction`. All seven migrations and the demo seeder succeeded. `billing:dispatch` correctly found no remaining due cycles after seeding; `schedule:run` dispatched aggregation; `queue:work database --queue=billing,metering,default --stop-when-empty --sleep=1 --timeout=60 --tries=5 --no-interaction` processed the aggregation job and exited with an empty queue. `queue:failed` reported no failed jobs. The existing `mp` application data was not reset. Redis was not exercised; database cache/queue behavior and array-cache tests were verified.

Formatting:

```sh
php vendor/bin/pint --format agent
```

## Assumptions and timebox trade-offs

One subscription per customer, UTC calendar anchors, whole-second timestamps, positive integer usage, two-decimal supported currencies, no taxes, discounts, credits, cancellation, payments or refunds. Currency/cycle conversions require a separate workflow. Customers can start in the past if the application is provisioning their historical subscription; retroactive usage still requires a matching segment and an unfinalized cycle.

The public API is tenant-token based rather than a complete user login system. Demo seeding is explicit and uses application workflows with historical segments for reproducible examples. It reuses demo tenants, customers and invoices on rerun and resumes partial demo seeding through idempotent events. Its time anchor is the original Demo SaaS creation time; reruns preserve the example rather than continuously generate new daily usage. Use reserved demo names only on development databases.

Database-backed queue/cache permit a working local WAMP demo; production should use Redis and supervised workers. The root page provides a responsive merchant dashboard with token sign-in, daily usage and CSV export, top customers, projected overage by currency, and churn risk. Use the token issued by `php artisan merchant:token <merchant-id>` to sign in. Browser sessions store only its hash; rotation also revokes browser access. Five-million-row load testing, Redis availability testing, long-duration distributed race testing, production monitoring and database backup/restore drills are outside this local verification.

Next improvements: a transactional ingestion/outbox and explicit period cutoff ledger for very hot customers; worker sharding and noncontending event claims; scoped/revocable tokens, audit trails and customer provisioning; bill/credit-note lifecycle and monetary limits; metrics for lag/deadlocks/query latency; alerting and Horizon; production-sized index/partition benchmarks; Docker/CI infrastructure and migration rollout plans. These are operational follow-ups, not claimed completed features.

## Assignment artifacts

The implementation request is preserved as text in `prompts/implementation-request.txt`. The take-home PDF asks for screenshots of actual AI prompts and a narrated recording; those must be captured from the real chat/IDE and recorded by the submitter. Text is not presented as a substitute screenshot. No repository publication or recruiter submission has been performed.

## Final requirement matrix

PASS below means the implementation was inspected and its behavior was verified by the cited tests or query evidence. The scale requirement evaluates the implemented strategy and documented limitations, not a five-million-row throughput benchmark. Paths are relative to this repository. Test classes are under `tests/Feature` unless marked Unit.

| Interview Requirement | Status | Evidence | Tests |
| --- | --- | --- | --- |
| Normalized schema | PASS | `database/migrations/2026_10_01_111525_create_usage_domain_tables.php`, `2026_10_01_114248_create_subscription_billing_tables.php`; live schema/FKs inspected | Service and HTTP suites on MySQL |
| Correct indexes | PASS | Metering/index migrations; live schema; pending query uses `usage_pending_aggregation`, merchant/date query uses `daily_merchant_date_customer` | MySQL EXPLAIN on demo data |
| 5M+ event strategy | PASS | Indexed pending chunks, segment/day totals, scale/retention/partitioning discussion above | Query inspection; no 5M load benchmark |
| POST /usage | PASS | `routes/api.php`, `UsageController`, `RecordUsageRequest`, `RecordUsageAction` | `Http/UsageControllerTest` |
| High throughput | PASS | Short per-customer transaction; no synchronous billing, aggregation or per-event dispatch | `UsageControllerTest::test_records_usage_and_returns_201_without_synchronous_aggregation`; concurrency suite; hot-customer contention documented |
| Idempotency | PASS | Unique customer/key constraint; `createOrFirst`; retry payload comparison | Usage HTTP tests; `MySqlConcurrencyTest::test_concurrent_ingestion_creates_only_one_usage_event` |
| Rate limiting | PASS | `AppServiceProvider` merchant usage limiter; route middleware | `UsageControllerTest::test_rate_limit_returns_429_after_the_configured_tenant_budget` |
| Repository Pattern | PASS | Domain contracts, Eloquent repositories, `AppServiceProvider` bindings | Real repository queries throughout service/HTTP suites |
| Queued aggregation | PASS | `AggregateDailyUsageJob` implements `ShouldQueue`; scheduled every minute | `Services/AggregationServiceTest` |
| Chunked processing | PASS | Bounded pending query; 1,000 events/chunk and 50 chunks/job defaults | Small-chunk, subscription drain and continuation tests |
| Aggregation retry safety | PASS | Daily unique key; increments and markers share a transaction | Rollback/retry and concurrent worker tests |
| Billing | PASS | `BillingService`, `BillingCalculator`, invoice repository | `Services/BillingServiceTest`; Unit `Services/BillingCalculatorTest` |
| Proration | PASS | UTC seconds ratio; half-up base rounding; floored allowance | Mid-cycle, leap-year and rounding tests |
| Overage | PASS | Nonnegative excess units; micros to minor units using Brick Math | Zero/below/exact/above allowance and fractional-rate tests |
| Mid-cycle start | PASS | Segment intersection with calendar billing period | `BillingServiceTest::test_subscription_start_mid_cycle_prorates_base_price_and_allowance` |
| Upgrade | PASS | Effective-dated pricing snapshots; transactional change action | Pricing provider's upgrade case; actual change action plus late usage test |
| Downgrade | PASS | Same temporal model and original/new rates | Pricing provider's downgrade case; actual change action plus late usage test |
| Boundary timestamps | PASS | Inclusive starts, exclusive ends; boundary usage selects new segment | Boundary billing test; late-usage action tests; projection boundary test |
| Invoice idempotency | PASS | Unique subscription/cycle; subscription lock; invoice/lines/cursor transaction | Service/job retries, concurrent billing and write rollback tests |
| Pricing cache | PASS | Tenant/plan key; scalar cache-aside payload; one-hour TTL | `PlanPricingServiceTest` persistent round-trip and zero-query cache hit |
| Cache invalidation | PASS | Shared service lock; observer invalidates after commit | Committed update/rollback tests; historical pricing edit billing test |
| Top 5 dashboard | PASS | Merchant/date SQL SUM, descending units and ID tie-breaker, LIMIT 5 | `DashboardServiceTest::test_top_five_customers_are_ranked_using_monthly_aggregates_and_scoped_to_tenant` |
| Projected overage | PASS | Actual closed-segment usage; active-segment run rate; per-currency totals | Monthly/currency, segment change, zero-elapsed, yearly and chunk tests |
| >50% churn risk | PASS | Completed months; positive previous usage; `2 * recent < previous` | 51%, exactly 50%, 49%, absent prior/current and tenant isolation cases |
| Dashboard scalability | PASS | SQL daily aggregates; lazy subscription chunks; no raw-event query | 101-subscription regression; MySQL EXPLAIN; demo-sized evidence only |
| Tenant isolation | PASS | Token-derived merchant; scoped repositories, writes and reads | Usage/subscription/invoice/dashboard/cache/browser isolation tests |
| README | PASS | Setup, architecture, schema, formulas, limits, trade-offs, operation and verified results | N/A; compared with code and actual commands |

The engineering checklist is **27/27 PASS**. Complete assignment delivery still needs a shared GitHub repository, actual prompt screenshots and the submitter's narrated 5–10 minute walkthrough. This local workspace is not a Git checkout; publication/access cannot be verified here. Those submission artifacts are separate from the verified backend behavior.
