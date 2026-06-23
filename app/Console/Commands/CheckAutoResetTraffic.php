<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Models\Plan;
use App\Models\User;
use App\Services\MailService;
use App\Utils\Helper;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class CheckAutoResetTraffic extends Command
{
    private const THRESHOLD = 0.9;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'check:autoResetTraffic';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = '自动购买流量重置包';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        ini_set('memory_limit', -1);
        if (Redis::exists('traffic_reset_lock')) {
            return;
        }
        $lockValue = 'auto_reset_traffic:' . time() . ':' . getmypid();
        Redis::setex('traffic_reset_lock', 300, $lockValue);

        try {
            User::where('auto_reset_traffic', 1)
                ->where('banned', 0)
                ->whereNotNull('plan_id')
                ->where('transfer_enable', '>', 0)
                ->where(function ($query) {
                    $query->where('expired_at', '>', time())
                        ->orWhereNull('expired_at');
                })
                ->whereRaw('(u + d) >= transfer_enable * ?', [self::THRESHOLD])
                ->select('id')
                ->orderBy('id')
                ->chunkById(100, function ($users) {
                    foreach ($users as $user) {
                        $this->handleUser($user->id);
                    }
                });
        } finally {
            if (Redis::get('traffic_reset_lock') === $lockValue) {
                Redis::del('traffic_reset_lock');
            }
        }
    }

    private function handleUser(int $userId): void
    {
        DB::beginTransaction();
        try {
            $user = User::lockForUpdate()->find($userId);
            if (!$user || !$this->shouldReset($user)) {
                DB::commit();
                return;
            }

            $plan = Plan::find($user->plan_id);
            if (!$plan || $plan->reset_price === null || (int)$plan->reset_price < 0) {
                $user->auto_reset_traffic = 0;
                if (!$user->save()) {
                    throw new Exception('自动重置流量失败');
                }
                DB::commit();
                return;
            }

            $resetPrice = (int)$plan->reset_price;
            if ($user->balance < $resetPrice) {
                $user->auto_reset_traffic = 0;
                if (!$user->save()) {
                    throw new Exception('自动重置流量失败');
                }
                DB::commit();
                try {
                    $mailService = new MailService();
                    $mailService->remindAutoResetTrafficBalanceNotEnough($user, $resetPrice);
                } catch (Exception $e) {
                    info('自动重置流量余额不足邮件发送失败', [$e->getMessage(), $userId]);
                }
                return;
            }

            $user->balance = $user->balance - $resetPrice;
            $user->u = 0;
            $user->d = 0;
            if (!$user->save()) {
                throw new Exception('自动重置流量失败');
            }

            $order = new Order();
            $order->user_id = $user->id;
            $order->plan_id = $plan->id;
            $order->period = 'reset_price';
            $order->trade_no = Helper::generateOrderNo();
            $order->type = 4;
            $order->total_amount = 0;
            $order->balance_amount = $resetPrice;
            $order->status = 3;
            $order->paid_at = time();
            if (!$order->save()) {
                throw new Exception('自动重置流量失败');
            }

            DB::commit();
        } catch (Exception $e) {
            DB::rollBack();
            info('用户自动重置流量失败', [$e->getMessage(), $userId]);
        }
    }

    private function shouldReset(User $user): bool
    {
        if (!$user->auto_reset_traffic || $user->banned || $user->plan_id === null) {
            return false;
        }
        if (!$user->transfer_enable || $user->transfer_enable <= 0) {
            return false;
        }
        if ($user->expired_at !== null && $user->expired_at <= time()) {
            return false;
        }
        return (($user->u + $user->d) / $user->transfer_enable) >= self::THRESHOLD;
    }
}
