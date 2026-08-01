<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Utils\Dict;
use Illuminate\Support\Facades\Http;

class CommController extends Controller
{
    public function config()
    {
        return response([
            'data' => [
                'app_name' => config('v2board.app_name', 'V2Board'),
                'tos_url' => config('v2board.tos_url'),
                'is_email_verify' => (int)config('v2board.email_verify', 0) ? 1 : 0,
                'is_invite_force' => (int)config('v2board.invite_force', 0) ? 1 : 0,
                'email_whitelist_suffix' => (int)config('v2board.email_whitelist_enable', 0)
                    ? $this->getEmailSuffix()
                    : 0,
                'is_recaptcha' => (int)config('v2board.recaptcha_enable', 0) ? 1 : 0,
                'recaptcha_site_key' => config('v2board.recaptcha_site_key'),
                'app_description' => config('v2board.app_description'),
                'app_url' => config('v2board.app_url'),
                'logo' => config('v2board.logo'),
                'is_register' => (int)config('v2board.stop_register', 0) ? 0 : 1,
                'is_try_out' => (int)config('v2board.try_out_plan_id', 0) ? 1 : 0,
                'try_out_hour' => (int)config('v2board.try_out_hour', 1),
                'currency' => config('v2board.currency', 'CNY'),
                'currency_symbol' => config('v2board.currency_symbol', '¥'),
                'telegram_discuss_link' => config('v2board.telegram_discuss_link'),
            ]
        ]);
    }

    private function getEmailSuffix()
    {
        $suffix = config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT);
        if (!is_array($suffix)) {
            return preg_split('/,/', $suffix);
        }
        return $suffix;
    }
}
