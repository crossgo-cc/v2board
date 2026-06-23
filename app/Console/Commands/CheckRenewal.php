<?php

namespace App\Console\Commands;

use App\Services\MailService;
use App\Services\PlanService;
use App\Services\OrderService;
use Illuminate\Console\Command;
use App\Models\User;
use App\Models\Order;
use App\Utils\Helper;
use Illuminate\Support\Facades\DB;

use Exception;

class CheckRenewal extends Command
{
    private const RENEW_WINDOW = 86400 * 3;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:renewal';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动续费';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);

        $now = time();
        User::where('auto_renewal', 1)
            ->whereNotNull('plan_id')
            ->whereNotNull('expired_at')
            ->where('expired_at', '>', $now)
            ->where('expired_at', '<', $now + self::RENEW_WINDOW)
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    $this->handleUser($user->id);
                }
            });
    }

    private function handleUser(int $userId): void
    {
        DB::beginTransaction();
        try {
            $user = User::lockForUpdate()->find($userId);
            if (!$this->shouldRenew($user)) {
                DB::commit();
                return;
            }

            $latestOrder = Order::where('user_id', $user->id)
                ->where('period', '!=', 'reset_price')
                ->where('period', '!=', 'onetime_price')
                ->where('period', '!=', 'deposit')
                ->where('status', 3)
                ->orderBy('created_at', 'desc')
                ->first();
            if (!$latestOrder) {
                DB::commit();
                info('用户自动续费跳过：无可用订单', [$userId]);
                return;
            }
            $latestPeriod = $latestOrder->period;

            $planService = new PlanService($user->plan_id);
            $plan = $planService->plan;
            if (!$plan) {
                DB::commit();
                info('用户自动续费跳过：套餐不存在', [$userId]);
                return;
            }

            if (!$plan->renew) {
                $user->auto_renewal = 0;
                if (!$user->save()) {
                    throw new Exception('自动续费失败');
                }
                DB::commit();
                return;
            }

            if ($plan[$latestPeriod] === null || (int)$plan[$latestPeriod] < 0) {
                $user->auto_renewal = 0;
                if (!$user->save()) {
                    throw new Exception('自动续费失败');
                }
                DB::commit();
                info('用户自动续费跳过：套餐周期价格不可用', [$userId, $latestPeriod]);
                return;
            }

            $renewalPrice = (int)$plan[$latestPeriod];
            if ($user->balance < $renewalPrice) {
                $user->auto_renewal = 0;
                if (!$user->save()) {
                    throw new Exception('自动续费失败');
                }
                DB::commit();
                try {
                    $mailService = new MailService();
                    $mailService->remindAutoRenewalBalanceNotEnough($user, $renewalPrice);
                } catch (Exception $e) {
                    info('自动续费余额不足邮件发送失败', [$e->getMessage(), $userId]);
                }
                return;
            }

            $order = new Order();
            $orderService = new OrderService($order);
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = $latestPeriod;
            $order->trade_no = Helper::generateOrderNo();
            $order->balance_amount = $renewalPrice;
            $order->total_amount = 0;
            $orderService->setVipDiscount($user);
            $order->type = 2;
            $order->status = 3;
            $order->paid_at = time();

            $user->balance = $user->balance - $renewalPrice;
            $user->expired_at = $this->getTime($latestPeriod, $user->expired_at);
            if (!$user->save()) {
                throw new Exception('自动续费失败');
            }
            if (!$order->save()) {
                throw new Exception('自动续费失败');
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            info('用户自动续费失败', [$e->getMessage(), $userId]);
        }
    }

    private function shouldRenew(?User $user): bool
    {
        if (!$user) return false;
        if (!$user->auto_renewal) return false;
        if ($user->plan_id === null) return false;
        if ($user->expired_at === null) return false;
        $now = time();
        if ($user->expired_at <= $now) return false;
        if ($user->expired_at - $now >= self::RENEW_WINDOW) return false;
        return true;
    }

    private function getTime($str, $timestamp)
    {
        if ($timestamp < time()) {
            $timestamp = time();
        }
        switch ($str) {
            case 'month_price':
                return strtotime('+1 month', $timestamp);
            case 'quarter_price':
                return strtotime('+3 month', $timestamp);
            case 'half_year_price':
                return strtotime('+6 month', $timestamp);
            case 'year_price':
                return strtotime('+12 month', $timestamp);
            case 'two_year_price':
                return strtotime('+24 month', $timestamp);
            case 'three_year_price':
                return strtotime('+36 month', $timestamp);
        }
    }
}
