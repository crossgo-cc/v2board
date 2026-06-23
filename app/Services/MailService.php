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
            'subject' => __('The traffic usage in :app_name has reached 95%', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
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
            'subject' => __('The service in :app_name is about to expire', [
               'app_name' =>  config('v2board.app_name', 'V2board')
            ]),
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
            'subject' => __('The automatic traffic reset in :app_name failed', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => __('Your traffic has reached the automatic reset threshold, but your account balance is insufficient, the system failed to automatically purchase the traffic reset package. Reset package price: :reset_price :currency. Current balance: :balance :currency. Please top up and re-enable automatic traffic reset.', [
                    'reset_price' => number_format($resetPrice / 100, 2),
                    'balance' => number_format($user->balance / 100, 2),
                    'currency' => $currency
                ])
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
            'subject' => __('The automatic renewal in :app_name failed', [
                'app_name' => config('v2board.app_name', 'V2board')
            ]),
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => __('Your subscription is about to expire, but your account balance is insufficient, the system failed to automatically renew your subscription. Renewal price: :renewal_price :currency. Current balance: :balance :currency. Please top up and re-enable automatic renewal.', [
                    'renewal_price' => number_format($renewalPrice / 100, 2),
                    'balance' => number_format($user->balance / 100, 2),
                    'currency' => $currency
                ])
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
