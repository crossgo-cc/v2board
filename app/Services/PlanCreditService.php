<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class PlanCreditService
{
    private const PERIOD_MONTHS = [
        'month_price' => 1,
        'quarter_price' => 3,
        'half_year_price' => 6,
        'year_price' => 12,
        'two_year_price' => 24,
        'three_year_price' => 36,
    ];

    /**
     * Calculate the value of the user's currently active subscription.
     *
     * The existing schema does not store service boundaries, so periodic
     * orders are reconstructed backwards from the user's current expiry.
     */
    public function calculate(User $user, Plan $plan, ?Collection $orders = null, ?int $now = null): array
    {
        $now = $now ?? time();
        $orders = $orders ?? $this->getEligibleOrders($user);

        if ($orders->isEmpty()) {
            return ['amount' => 0, 'order_ids' => []];
        }

        $orderIds = $orders->pluck('id')
            ->filter(static fn ($id) => $id !== null)
            ->map(static fn ($id) => (int)$id)
            ->values()
            ->all();

        if ($user->expired_at === null) {
            return [
                'amount' => $this->calculateOneTimeCredit($user, $orders->first()),
                'order_ids' => $orderIds,
            ];
        }

        $intervals = $this->buildIntervals($orders, (int)$user->expired_at);
        if (!$intervals) {
            return ['amount' => 0, 'order_ids' => $orderIds];
        }

        $method = $this->resolveResetMethod($plan);
        $amount = $method === 2
            ? $this->calculateNoResetCredit($user, $intervals, $now)
            : $this->calculateResetCredit($user, $intervals, $method, $now);

        return [
            'amount' => max(0, min($amount, $this->sumIntervalValues($intervals))),
            'order_ids' => $orderIds,
        ];
    }

    private function getEligibleOrders(User $user): Collection
    {
        $query = Order::where('user_id', $user->id)
            ->where('status', 3);

        if ($user->expired_at === null) {
            return $query->where('period', 'onetime_price')
                ->orderBy('created_at', 'DESC')
                ->limit(1)
                ->get();
        }

        return $query
            ->whereNotIn('period', ['reset_price', 'onetime_price', 'deposit'])
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    private function calculateOneTimeCredit(User $user, Order $order): int
    {
        $quota = (float)$user->transfer_enable;
        if ($quota <= 0) {
            return 0;
        }

        return (int)floor($this->orderValue($order) * $this->remainingTrafficRatio($user, $quota));
    }

    private function calculateNoResetCredit(User $user, array $intervals, int $now): int
    {
        $start = min(array_column($intervals, 'start'));
        $end = max(array_column($intervals, 'end'));
        $totalSeconds = $end - $start;
        if ($totalSeconds <= 0 || $now >= $end) {
            return 0;
        }

        $remainingTimeRatio = $this->ratio($end - max($now, $start), $totalSeconds);
        $remainingTrafficRatio = $this->remainingTrafficRatio($user, (float)$user->transfer_enable);

        return (int)floor($this->sumIntervalValues($intervals) * min($remainingTimeRatio, $remainingTrafficRatio));
    }

    private function calculateResetCredit(User $user, array $intervals, int $method, int $now): int
    {
        $serviceStart = min(array_column($intervals, 'start'));
        $serviceEnd = max(array_column($intervals, 'end'));
        if ($serviceEnd <= $now) {
            return 0;
        }

        $cursor = $this->cycleStartAt($serviceStart, $method, $serviceEnd);
        $credit = 0.0;
        $quota = (float)$user->transfer_enable;
        $remainingTrafficRatio = $this->remainingTrafficRatio($user, $quota);

        while ($cursor->getTimestamp() < $serviceEnd) {
            $next = $this->nextCycleBoundary($cursor, $method, $serviceEnd);
            $coveredStart = max($serviceStart, $cursor->getTimestamp());
            $coveredEnd = min($serviceEnd, $next->getTimestamp());

            if ($coveredEnd > $coveredStart) {
                $cycleValue = $this->valueForInterval($intervals, $coveredStart, $coveredEnd);
                if ($now >= $coveredStart && $now < $coveredEnd) {
                    $remainingTimeRatio = $this->ratio($coveredEnd - $now, $coveredEnd - $coveredStart);
                    $credit += $cycleValue * min($remainingTimeRatio, $remainingTrafficRatio);
                } elseif ($coveredStart >= $now) {
                    $credit += $cycleValue;
                }
            }

            $cursor = $next;
        }

        return (int)floor($credit);
    }

    private function buildIntervals(Collection $orders, int $serviceEnd): array
    {
        $cursor = Carbon::createFromTimestamp($serviceEnd);
        $intervals = [];

        foreach ($orders->sortByDesc('created_at') as $order) {
            $months = self::PERIOD_MONTHS[$order->period] ?? null;
            if ($months === null) {
                continue;
            }

            $start = $cursor->copy()->subMonthsNoOverflow($months);
            $intervals[] = [
                'start' => $start->getTimestamp(),
                'end' => $cursor->getTimestamp(),
                'value' => $this->orderValue($order),
            ];
            $cursor = $start;
        }

        return array_reverse($intervals);
    }

    private function orderValue(Order $order): int
    {
        return max(
            0,
            (int)$order->total_amount
            + (int)$order->balance_amount
            + (int)$order->surplus_amount
            - (int)$order->refund_amount
        );
    }

    private function sumIntervalValues(array $intervals): int
    {
        return (int)array_sum(array_column($intervals, 'value'));
    }

    private function valueForInterval(array $intervals, int $start, int $end): float
    {
        $value = 0.0;
        foreach ($intervals as $interval) {
            $duration = $interval['end'] - $interval['start'];
            $overlap = min($end, $interval['end']) - max($start, $interval['start']);
            if ($duration <= 0 || $overlap <= 0) {
                continue;
            }
            $value += $interval['value'] * ($overlap / $duration);
        }

        return $value;
    }

    private function remainingTrafficRatio(User $user, float $quota): float
    {
        if ($quota <= 0) {
            return 0.0;
        }

        return $this->ratio($quota - ((float)$user->u + (float)$user->d), $quota);
    }

    private function ratio(float $numerator, float $denominator): float
    {
        if ($denominator <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, $numerator / $denominator));
    }

    private function resolveResetMethod(Plan $plan): int
    {
        $method = $plan->reset_traffic_method;
        if ($method === null) {
            $method = config('v2board.reset_traffic_method', 0);
        }

        $method = (int)$method;
        return in_array($method, [0, 1, 2, 3, 4], true) ? $method : 2;
    }

    private function cycleStartAt(int $timestamp, int $method, int $anchorTimestamp): Carbon
    {
        $date = Carbon::createFromTimestamp($timestamp);
        switch ($method) {
            case 0:
                return $date->copy()->startOfMonth();
            case 1:
                $anchorDay = Carbon::createFromTimestamp($anchorTimestamp)->day;
                $candidate = $this->monthlyBoundary($date->year, $date->month, $anchorDay);
                $previous = $date->copy()->subMonthNoOverflow();
                return $candidate->getTimestamp() <= $timestamp
                    ? $candidate
                    : $this->monthlyBoundary($previous->year, $previous->month, $anchorDay);
            case 3:
                return $date->copy()->startOfYear();
            case 4:
                $anchor = Carbon::createFromTimestamp($anchorTimestamp);
                $candidate = $this->yearlyBoundary($date->year, $anchor->month, $anchor->day);
                return $candidate->getTimestamp() <= $timestamp
                    ? $candidate
                    : $this->yearlyBoundary($date->year - 1, $anchor->month, $anchor->day);
        }

        return $date->copy()->startOfDay();
    }

    private function nextCycleBoundary(Carbon $boundary, int $method, int $anchorTimestamp): Carbon
    {
        switch ($method) {
            case 0:
                return $boundary->copy()->addMonthNoOverflow()->startOfMonth();
            case 1:
                $next = $boundary->copy()->addMonthNoOverflow();
                return $this->monthlyBoundary($next->year, $next->month, Carbon::createFromTimestamp($anchorTimestamp)->day);
            case 3:
                return $boundary->copy()->addYear()->startOfYear();
            case 4:
                $next = $boundary->copy()->addYear();
                $anchor = Carbon::createFromTimestamp($anchorTimestamp);
                return $this->yearlyBoundary($next->year, $anchor->month, $anchor->day);
        }

        return $boundary->copy()->addDay();
    }

    private function monthlyBoundary(int $year, int $month, int $day): Carbon
    {
        $firstDay = Carbon::create($year, $month, 1)->startOfDay();
        return $firstDay->copy()->day(min($day, $firstDay->daysInMonth));
    }

    private function yearlyBoundary(int $year, int $month, int $day): Carbon
    {
        $firstDay = Carbon::create($year, $month, 1)->startOfDay();
        return $firstDay->copy()->day(min($day, $firstDay->daysInMonth));
    }
}
