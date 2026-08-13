<?php

namespace Tests\Unit;

use App\Models\Coupon;
use App\Models\Order;
use App\Services\CouponService;
use Tests\TestCase;

class CouponServiceTest extends TestCase
{
    public function testCouponDiscountIsAppliedToOrderTotal(): void
    {
        $coupon = new Coupon();
        $coupon->type = 2;
        $coupon->value = 30;
        $coupon->show = 1;
        $coupon->limit_use = null;
        $coupon->limit_use_with_user = null;
        $coupon->started_at = 0;
        $coupon->ended_at = PHP_INT_MAX;

        $service = (new \ReflectionClass(CouponService::class))
            ->newInstanceWithoutConstructor();
        $service->coupon = $coupon;

        $order = new Order();
        $order->plan_id = 1;
        $order->user_id = 1;
        $order->period = 'quarter_price';
        $order->total_amount = 14000;

        $this->assertTrue($service->use($order));
        $this->assertSame(4200, $order->discount_amount);
        $this->assertSame(9800, $order->total_amount);
    }
}
