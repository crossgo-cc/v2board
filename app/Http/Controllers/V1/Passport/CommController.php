<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\CommSendEmailVerify;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\User;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use ReCaptcha\ReCaptcha;
use Illuminate\Support\Facades\RateLimiter;

use function PHPUnit\Framework\isEmpty;

class CommController extends Controller
{
    private function isEmailVerify()
    {
        return response([
            'data' => (int)config('v2board.email_verify', 0) ? 1 : 0
        ]);
    }

    public function sendEmailVerify(CommSendEmailVerify $request)
    {
        $ip = $request->ip();
        if (RateLimiter::tooManyAttempts($ip, 3)) {
            abort(429, "请求过于频繁，请稍后再试");
        }
        RateLimiter::hit($ip, 60);

        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, "验证码有误");
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = strtolower(trim((string)$email));
        $isforget = $request->input('isforget');
        $email_exists = User::where('email', $email)->exists();
        //检查是否在白名单内
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, "邮箱后缀不处于白名单中");
            }
        }
        // 检查是否是gmail别名邮箱
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, "不支持 Gmail 别名邮箱");
            }
        }
        if (isset($isforget)) {
            if ($isforget == 0 && $email_exists) {
                abort(500, "该邮箱已存在");
            } 
            if ($isforget == 1 && !$email_exists) {
                abort(500, "该邮箱不存在系统中");
            }
        }
        if (Cache::get(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $cacheKeyEmail))) {
            abort(500, "验证码已发送，请过一会儿再请求");
        }
        $code = (string)rand(100000, 999999);
        $subject = config('v2board.app_name', 'V2Board') . "邮箱验证码";

        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => $subject,
            'template_name' => 'verify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'code' => $code,
                'url' => config('v2board.app_url')
            ]
        ]);

        Cache::put(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail), $code, 300);
        Cache::put(CacheKey::get('LAST_SEND_EMAIL_VERIFY_TIMESTAMP', $cacheKeyEmail), time(), 60);
        return response([
            'data' => true
        ]);
    }

    public function pv(Request $request)
    {
        $inviteCode = InviteCode::where('code', $request->input('invite_code'))->first();
        if ($inviteCode) {
            $inviteCode->pv = $inviteCode->pv + 1;
            $inviteCode->save();
        }

        return response([
            'data' => true
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
