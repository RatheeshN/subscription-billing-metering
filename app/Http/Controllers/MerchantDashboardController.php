<?php

namespace App\Http\Controllers;

use App\Http\Requests\MerchantDashboardLoginRequest;
use App\Repositories\Contracts\DashboardRepositoryInterface;
use App\Repositories\Contracts\MerchantRepositoryInterface;
use App\Services\DashboardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MerchantDashboardController extends Controller
{
    public function __construct(private MerchantRepositoryInterface $merchants, private DashboardRepositoryInterface $dashboardRepository, private DashboardService $dashboard) {}

    public function index(Request $request): View
    {
        $hash = $request->session()->get('merchant_token_hash');
        $merchant = is_string($hash) ? $this->merchants->findByTokenHash($hash) : null;
        if ($merchant === null) {
            $request->session()->forget('merchant_token_hash');

            return view('welcome', ['merchant' => null]);
        }

        $asOf = CarbonImmutable::now('UTC');
        $daily = $this->dashboardRepository->getDailyUsage($merchant->id, $asOf->startOfMonth(), $asOf);
        $days = collect(range(1, $asOf->day))->map(fn (int $day) => [
            'date' => $asOf->setDay($day)->toDateString(),
            'label' => $asOf->setDay($day)->format('M j'),
            'units' => $daily->get($asOf->setDay($day)->toDateString(), 0),
        ]);
        $maximum = max(1, $days->max('units'));
        $points = $days->map(fn (array $day, int $index) => round(20 + $index * 720 / max(1, $days->count() - 1), 2).','.round(190 - $day['units'] / $maximum * 160, 2)
        )->implode(' ');

        return view('welcome', [
            'merchant' => $merchant,
            'dashboard' => $this->dashboard->get($merchant->id),
            'asOf' => $asOf,
            'days' => $days,
            'usageTotal' => $daily->sum(),
            'chartMaximum' => $maximum,
            'chartPoints' => $points,
        ]);
    }

    public function store(MerchantDashboardLoginRequest $request): RedirectResponse
    {
        $hash = hash('sha256', $request->validated('token'));
        if ($this->merchants->findByTokenHash($hash) === null) {
            return redirect()->route('dashboard.index')->withErrors(['token' => 'That merchant token is not valid.']);
        }
        $request->session()->regenerate();
        $request->session()->put('merchant_token_hash', $hash);

        return redirect()->route('dashboard.index');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('dashboard.index');
    }
}
