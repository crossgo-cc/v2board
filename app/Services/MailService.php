<?php

namespace App\Services;

use App\Jobs\SendEmailJob;
use App\Models\User;
use App\Utils\CacheKey;
use Illuminate\Support\Facades\Cache;

class MailService
{
    public function remindTraffic (User $user)
    {
        if (!$user->remind_traffic) return;
        if (!$this->remindTrafficIsWarnValue($user->u, $user->d, $user->transfer_enable)) return;
        $flag = CacheKey::get('LAST_SEND_EMAIL_REMIND_TRAFFIC', $user->id);
        if (Cache::get($flag)) return;
        if (!Cache::put($flag, 1, 24 * 3600)) return;
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => '在 ' . config('v2board.app_name', 'V2board') . ' 的已用流量已达到 95%',
            'template_name' => 'remindTraffic',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url')
            ]
        ]);
    }

    public function remindExpire(User $user)
    {
        if (!($user->expired_at !== NULL && ($user->expired_at - 86400) < time() && $user->expired_at > time())) return;
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => '在 ' . config('v2board.app_name', 'V2board') . ' 的服务即将到期',
            'template_name' => 'remindExpire',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url')
            ]
        ]);
    }

    public function remindAutoResetTrafficBalanceNotEnough(User $user, int $resetPrice)
    {
        $flag = CacheKey::get('LAST_SEND_EMAIL_AUTO_RESET_TRAFFIC_BALANCE_NOT_ENOUGH', $user->id);
        if (Cache::get($flag)) return;
        if (!Cache::put($flag, 1, 24 * 3600)) return;

        $currency = config('v2board.currency', 'CNY');
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => '在 ' . config('v2board.app_name', 'V2board') . ' 的自动重置流量失败',
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => sprintf(
                    "你的流量已达到自动重置阈值，但账户余额不足，系统未能完成自动购买流量重置包。\n\n重置包价格：%s %s\n当前余额：%s %s\n\n为避免影响使用，请尽快处理：\n1. 前往「钱包」充值，再到「我的」页面重新开启「自动重置流量」开关；或\n2. 直接到「订阅」页面手动购买流量重置包。\n\n请注意：本次扣费已失败，系统不会自动创建订单或再次尝试扣费，请按上述方式手动处理。",
                    number_format($resetPrice / 100, 2),
                    $currency,
                    number_format($user->balance / 100, 2),
                    $currency
                )
            ]
        ]);
    }

    public function remindAutoRenewalBalanceNotEnough(User $user, int $renewalPrice)
    {
        $flag = CacheKey::get('LAST_SEND_EMAIL_AUTO_RENEWAL_BALANCE_NOT_ENOUGH', $user->id);
        if (Cache::get($flag)) return;
        if (!Cache::put($flag, 1, 24 * 3600)) return;

        $currency = config('v2board.currency', 'CNY');
        SendEmailJob::dispatch([
            'email' => $user->email,
            'subject' => '在 ' . config('v2board.app_name', 'V2board') . ' 的自动续费失败',
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => sprintf(
                    "你的订阅即将到期，但账户余额不足，系统未能完成自动续费。\n\n续费价格：%s %s\n当前余额：%s %s\n\n为避免影响使用，请尽快处理：\n1. 前往「钱包」充值，再到「我的」页面重新开启「自动续费」开关；或\n2. 直接到「订阅」页面手动下单续费。\n\n请注意：本次扣费已失败，系统不会自动创建订单或再次尝试扣费，请尽量在到期前完成上述操作。",
                    number_format($renewalPrice / 100, 2),
                    $currency,
                    number_format($user->balance / 100, 2),
                    $currency
                )
            ]
        ]);
    }

    private function remindTrafficIsWarnValue($u, $d, $transfer_enable)
    {
        $ud = $u + $d;
        if (!$ud) return false;
        if (!$transfer_enable) return false;
        $percentage = ($ud / $transfer_enable) * 100;
        if ($percentage < 95) return false;
        if ($percentage >= 100) return false;
        return true;
    }
}
