<?php

namespace App\Providers;

use App\Models\Plan;
use App\Observers\PlanObserver;
use App\Repositories\Contracts\CustomerRepository;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Repositories\Contracts\InvoiceRepositoryInterface;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Repositories\Contracts\PlanRepositoryInterface;
use App\Repositories\Contracts\SubscriptionRepositoryInterface;
use App\Repositories\Contracts\UsageAggregateRepositoryInterface;
use App\Repositories\Contracts\UsageEventRepository;
use App\Repositories\Contracts\UsageEventRepositoryInterface;
use App\Repositories\Eloquent\EloquentCustomerRepository;
use App\Repositories\Eloquent\EloquentDashboardRepository;
use App\Repositories\Eloquent\EloquentInvoiceRepository;
use App\Repositories\Eloquent\EloquentMerchantRepository;
use App\Repositories\Eloquent\EloquentMeteredUsageEventRepository;
use App\Repositories\Eloquent\EloquentPlanRepository;
use App\Repositories\Eloquent\EloquentSubscriptionRepository;
use App\Repositories\Eloquent\EloquentUsageAggregateRepository;
use App\Repositories\Eloquent\EloquentUsageEventRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CustomerRepository::class, EloquentCustomerRepository::class);
        $this->app->bind(UsageEventRepository::class, EloquentUsageEventRepository::class);
        $this->app->bind(MerchantRepositoryInterface::class, EloquentMerchantRepository::class);
        $this->app->bind(PlanRepositoryInterface::class, EloquentPlanRepository::class);
        $this->app->bind(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);
        $this->app->bind(UsageEventRepositoryInterface::class, EloquentMeteredUsageEventRepository::class);
        $this->app->bind(UsageAggregateRepositoryInterface::class, EloquentUsageAggregateRepository::class);
        $this->app->bind(InvoiceRepositoryInterface::class, EloquentInvoiceRepository::class);
        $this->app->bind(DashboardRepositoryInterface::class, EloquentDashboardRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Plan::observe(PlanObserver::class);
        Model::preventLazyLoading(! $this->app->isProduction());
        RateLimiter::for('api-auth', fn (Request $request) => Limit::perMinute(1200)->by('ip:'.$request->ip()));
        RateLimiter::for('usage', fn (Request $request) => Limit::perMinute((int) config('billing.usage_requests_per_minute'))->by('usage:'.$request->attributes->get('merchant_id')));
        RateLimiter::for('merchant-api', fn (Request $request) => Limit::perMinute((int) config('billing.api_requests_per_minute'))->by('api:'.$request->attributes->get('merchant_id')));
    }
}
