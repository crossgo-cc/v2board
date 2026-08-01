<?php

namespace App\Http\Controllers\V1\Passport;

use App\Http\Controllers\Controller;
use App\Http\Requests\Passport\AuthForget;
use App\Http\Requests\Passport\AuthLogin;
use App\Http\Requests\Passport\AuthRegister;
use App\Jobs\SendEmailJob;
use App\Models\InviteCode;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Utils\CacheKey;
use App\Utils\Dict;
use App\Utils\Helper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use ReCaptcha\ReCaptcha;

class AuthController extends Controller
{
    public function register(AuthRegister $request)
    {
        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            $registerCountByIP = Cache::get(CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip())) ?? 0;
            if ((int)$registerCountByIP >= (int)config('v2board.register_limit_count', 3)) {
                abort(500, '注册频繁，请等待 ' . config('v2board.register_limit_expire', 60) . ' 分钟后再次尝试');
            }
        }
        if ((int)config('v2board.recaptcha_enable', 0)) {
            $recaptcha = new ReCaptcha(config('v2board.recaptcha_key'));
            $recaptchaResp = $recaptcha->verify($request->input('recaptcha_data'));
            if (!$recaptchaResp->isSuccess()) {
                abort(500, "验证码有误");
            }
        }
        if ((int)config('v2board.email_whitelist_enable', 0)) {
            if (!Helper::emailSuffixVerify(
                $request->input('email'),
                config('v2board.email_whitelist_suffix', Dict::EMAIL_WHITELIST_SUFFIX_DEFAULT))
            ) {
                abort(500, "邮箱后缀不处于白名单中");
            }
        }
        if ((int)config('v2board.email_gmail_limit_enable', 0)) {
            $prefix = explode('@', $request->input('email'))[0];
            if (strpos($prefix, '.') !== false || strpos($prefix, '+') !== false) {
                abort(500, "不支持 Gmail 别名邮箱");
            }
        }
        if ((int)config('v2board.stop_register', 0)) {
            abort(500, "本站已关闭注册");
        }
        if ((int)config('v2board.invite_force', 0)) {
            if (empty($request->input('invite_code'))) {
                abort(500, "必须使用邀请码才可以注册");
            }
        }
        $email = $request->input('email');
        $cacheKeyEmail = is_string($email) ? strtolower(trim($email)) : '';
        if ((int)config('v2board.email_verify', 0)) {
            $inputCode = $request->input('email_code');
            if (!is_string($inputCode) || !preg_match('/^\d{6}$/', $inputCode)) {
                abort(500, "邮箱验证码有误");
            }
            $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
            if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
                abort(500, "邮箱验证码有误");
            }
        }
        $password = $request->input('password');
        $exist = User::where('email', $email)->first();
        if ($exist) {
            abort(500, "邮箱已在系统中存在");
        }
        $user = new User();
        $user->email = $email;
        $user->password = password_hash($password, PASSWORD_DEFAULT);
        $user->uuid = Helper::guid(true);
        $user->token = Helper::guid();
        if ($request->input('invite_code')) {
            $inviteCode = InviteCode::where('code', $request->input('invite_code'))
                ->where('status', 0)
                ->first();
            if (!$inviteCode) {
                if ((int)config('v2board.invite_force', 0)) {
                    abort(500, "邀请码无效");
                }
            } else {
                $user->invite_user_id = $inviteCode->user_id ? $inviteCode->user_id : null;
                if (!(int)config('v2board.invite_never_expire', 0)) {
                    $inviteCode->status = 1;
                    $inviteCode->save();
                }
            }
        }

        // try out
        if ((int)config('v2board.try_out_plan_id', 0)) {
            $plan = Plan::find(config('v2board.try_out_plan_id'));
            if ($plan) {
                $user->transfer_enable = $plan->transfer_enable * 1073741824;
                $user->device_limit = $plan->device_limit;
                $user->plan_id = $plan->id;
                $user->group_id = $plan->group_id;
                $user->expired_at = time() + (config('v2board.try_out_hour', 1) * 3600);
                $user->speed_limit = $plan->speed_limit;
            }
        }

        if (!$user->save()) {
            abort(500, "注册失败");
        }
        if ((int)config('v2board.email_verify', 0)) {
            Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        }

        $user->last_login_at = time();
        $user->save();

        if ((int)config('v2board.register_limit_by_ip_enable', 0)) {
            Cache::put(
                CacheKey::get('REGISTER_IP_RATE_LIMIT', $request->ip()),
                (int)$registerCountByIP + 1,
                (int)config('v2board.register_limit_expire', 60) * 60
            );
        }

        $authService = new AuthService($user);

        return response()->json([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function login(AuthLogin $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        if ((int)config('v2board.password_limit_enable', 1)) {
            $passwordErrorCount = (int)Cache::get(CacheKey::get('PASSWORD_ERROR_LIMIT', $email), 0);
            if ($passwordErrorCount >= (int)config('v2board.password_limit_count', 5)) {
                abort(500, '密码错误次数过多，请 ' . config('v2board.password_limit_expire', 60) . ' 分钟后再试');
            }
        }

        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, "邮箱或密码错误");
        }
        if (!Helper::multiPasswordVerify(
            $user->password_algo,
            $user->password_salt,
            $password,
            $user->password)
        ) {
            if ((int)config('v2board.password_limit_enable')) {
                Cache::put(
                    CacheKey::get('PASSWORD_ERROR_LIMIT', $email),
                    (int)$passwordErrorCount + 1,
                    60 * (int)config('v2board.password_limit_expire', 60)
                );
            }
            abort(500, "邮箱或密码错误");
        }

        if ($user->banned) {
            abort(500, "该账户已被停止使用");
        }

        $authService = new AuthService($user);
        return response([
            'data' => $authService->generateAuthData($request)
        ]);
    }

    public function token2Login(Request $request)
    {
        if ($request->input('token')) {
            $redirect = '/#/login?verify=' . $request->input('token') . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
            if (config('v2board.app_url')) {
                $location = config('v2board.app_url') . $redirect;
            } else {
                $location = url($redirect);
            }
            return redirect()->to($location);
        }

        if ($request->input('verify')) {
            $key =  CacheKey::get('TEMP_TOKEN', $request->input('verify'));
            $userId = Cache::get($key);
            if (!$userId) {
                abort(500, "令牌有误");
            }
            $user = User::find($userId);
            if (!$user) {
                abort(500, "该用户不存在");
            }
            if ($user->banned) {
                abort(500, "该账户已被停止使用");
            }
            Cache::forget($key);
            $authService = new AuthService($user);
            return response([
                'data' => $authService->generateAuthData($request)
            ]);
        }
    }

    public function getQuickLoginUrl(Request $request)
    {
        $authorization = $request->input('auth_data') ?? $request->header('authorization');
        if (!$authorization) abort(403, '未登录或登陆已过期');

        $user = AuthService::decryptAuthData($authorization);
        if (!$user) abort(403, '未登录或登陆已过期');

        $code = Helper::guid();
        $key = CacheKey::get('TEMP_TOKEN', $code);
        Cache::put($key, $user['id'], 60);
        $redirect = '/#/login?verify=' . $code . '&redirect=' . ($request->input('redirect') ? $request->input('redirect') : 'dashboard');
        if (config('v2board.app_url')) {
            $url = config('v2board.app_url') . $redirect;
        } else {
            $url = url($redirect);
        }
        return response([
            'data' => $url
        ]);
    }

    public function forget(AuthForget $request)
    {
        $email     = $request->input('email');
        $inputCode = $request->input('email_code');
        $password  = $request->input('password');

        if (!is_string($email) || !is_string($inputCode) || !is_string($password)) {
            abort(500, "邮箱验证码有误");
        }
        if (!preg_match('/^\d{6}$/', $inputCode)) {
            abort(500, "邮箱验证码有误");
        }

        $cacheKeyEmail         = strtolower(trim($email));
        $forgetRequestLimitKey = CacheKey::get('FORGET_REQUEST_LIMIT', $cacheKeyEmail);
        $forgetRequestLimit    = (int)Cache::get($forgetRequestLimitKey);
        if ($forgetRequestLimit >= 3) {
            abort(500, "重置失败，请稍后再试");
        }

        $cachedCode = Cache::get(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        if ($cachedCode === null || $cachedCode === '' || !hash_equals((string)$cachedCode, $inputCode)) {
            Cache::put($forgetRequestLimitKey, $forgetRequestLimit + 1, 300);
            abort(500, "邮箱验证码有误");
        }
        $user = User::where('email', $email)->first();
        if (!$user) {
            abort(500, "该邮箱不存在系统中");
        }
        $user->password      = password_hash($password, PASSWORD_DEFAULT);
        $user->password_algo = null;
        $user->password_salt = null;
        if (!$user->save()) {
            abort(500, "重置失败");
        }
        Cache::forget(CacheKey::get('EMAIL_VERIFY_CODE', $cacheKeyEmail));
        (new AuthService($user))->removeAllSession();
        return response([
            'data' => true
        ]);
    }
}
