<?php

namespace Tests\Unit;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\OrderService;
use App\Services\PlanCreditService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class PlanCreditServiceTest extends TestCase
{
    public function testNoResetUsesTheLowerOfTimeAndTrafficRatios(): void
    {
        $end = Carbon::create(2026, 6, 15)->startOfDay();
        $start = $end->copy()->subYear();
        $now = $start->getTimestamp() + intdiv($end->getTimestamp() - $start->getTimestamp(), 2);
        $user = $this->user($end->getTimestamp(), 20);
        $plan = $this->plan(2);
        $orders = new Collection([$this->order('year_price', 120000)]);

        $result = (new PlanCreditService())->calculate($user, $plan, $orders, $now);

        $this->assertSame(60000, $result['amount']);
    }

    /**
     * @dataProvider resetMethods
     */
    public function testAllResetMethodsProduceBoundedCredit(?int $method): void
    {
        $now = Carbon::create(2025, 6, 15)->startOfDay()->getTimestamp();
        $user = $this->user(Carbon::create(2026, 6, 15)->startOfDay()->getTimestamp(), 20);
        $plan = $this->plan($method);
        $orders = new Collection([$this->order('year_price', 120000)]);

        $result = (new PlanCreditService())->calculate($user, $plan, $orders, $now);

        $this->assertGreaterThan(0, $result['amount']);
        $this->assertLessThanOrEqual(120000, $result['amount']);
    }

    /**
     * @dataProvider periodicPeriods
     */
    public function testAllPeriodicBillingPeriodsUseTheirConfiguredDuration(string $period, int $months): void
    {
        $end = Carbon::create(2026, 6, 15)->startOfDay();
        $start = $end->copy()->subMonthsNoOverflow($months);
        $now = $start->getTimestamp() + intdiv($end->getTimestamp() - $start->getTimestamp(), 2);
        $user = $this->user($end->getTimestamp(), 20);
        $plan = $this->plan(2);
        $orders = new Collection([$this->order($period, 120000)]);

        $result = (new PlanCreditService())->calculate($user, $plan, $orders, $now);

        $this->assertSame(60000, $result['amount']);
    }

    /**
     * @dataProvider periodicCombinations
     */
    public function testEveryPeriodicBillingAndResetCombinationIsSupported(string $period, int $months, ?int $method): void
    {
        $end = Carbon::create(2026, 6, 15)->startOfDay();
        $start = $end->copy()->subMonthsNoOverflow($months);
        $now = $start->getTimestamp() + intdiv($end->getTimestamp() - $start->getTimestamp(), 2);
        $user = $this->user($end->getTimestamp(), 20);
        $plan = $this->plan($method);
        $orders = new Collection([$this->order($period, 120000)]);

        $result = (new PlanCreditService())->calculate($user, $plan, $orders, $now);

        $this->assertGreaterThan(0, $result['amount']);
        $this->assertLessThanOrEqual(120000, $result['amount']);
    }

    public function testResetPlanValuesFutureCyclesAtFullValue(): void
    {
        $now = Carbon::create(2025, 6, 15)->startOfDay()->getTimestamp();
        $user = $this->user(Carbon::create(2027, 1, 1)->startOfDay()->getTimestamp(), 90);
        $orders = new Collection([$this->order('two_year_price', 240000)]);

        $resetResult = (new PlanCreditService())->calculate($user, $this->plan(3), $orders, $now);
        $noResetResult = (new PlanCreditService())->calculate($user, $this->plan(2), $orders, $now);

        $this->assertGreaterThan($noResetResult['amount'], $resetResult['amount']);
    }

    public function testOneTimePlanUsesRemainingTrafficOnly(): void
    {
        $user = $this->user(null, 25);
        $plan = $this->plan(2);
        $orders = new Collection([$this->order('onetime_price', 10000)]);

        $result = (new PlanCreditService())->calculate($user, $plan, $orders, time());

        $this->assertSame(7500, $result['amount']);
    }

    public function testVipDiscountDoesNotSubtractCouponTwice(): void
    {
        $order = $this->order('month_price', 90000);
        $order->discount_amount = 10000;
        $user = new User();
        $user->discount = 10;

        (new OrderService($order))->setVipDiscount($user);

        $this->assertSame(81000, $order->total_amount);
        $this->assertSame(19000, $order->discount_amount);
    }

    public static function resetMethods(): array
    {
        return [[null], [0], [1], [2], [3], [4]];
    }

    public static function periodicPeriods(): array
    {
        return [
            ['month_price', 1],
            ['quarter_price', 3],
            ['half_year_price', 6],
            ['year_price', 12],
            ['two_year_price', 24],
            ['three_year_price', 36],
        ];
    }

    public static function periodicCombinations(): array
    {
        $combinations = [];
        foreach (self::periodicPeriods() as [$period, $months]) {
            foreach ([null, 0, 1, 2, 3, 4] as $method) {
                $combinations[] = [$period, $months, $method];
            }
        }

        return $combinations;
    }

    private function user(?int $expiredAt, int $usedTraffic): User
    {
        $user = new User();
        $user->expired_at = $expiredAt;
        $user->transfer_enable = 100;
        $user->u = $usedTraffic;
        $user->d = 0;
        return $user;
    }

    private function plan(?int $resetMethod): Plan
    {
        $plan = new Plan();
        $plan->reset_traffic_method = $resetMethod;
        return $plan;
    }

    private function order(string $period, int $amount): Order
    {
        $order = new Order();
        $order->period = $period;
        $order->total_amount = $amount;
        $order->balance_amount = 0;
        $order->surplus_amount = 0;
        $order->refund_amount = 0;
        $order->created_at = 0;
        return $order;
    }
}
