<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthForget extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email'      => 'required|string|email:strict|max:64',
            'password'   => 'required|string|min:8|max:64',
            'email_code' => 'required|string|digits:6',
        ];
    }

    public function messages()
    {
        return [
            'email.required'      => "邮箱不能为空",
            'email.string'        => "邮箱格式不正确",
            'email.email'         => "邮箱格式不正确",
            'email.max'           => "邮箱格式不正确",
            'password.required'   => "密码不能为空",
            'password.string'     => "密码不能为空",
            'password.min'        => "密码必须大于 8 个字符",
            'password.max'        => "密码必须大于 8 个字符",
            'email_code.required' => "邮箱验证码不能为空",
            'email_code.string'   => "邮箱验证码有误",
            'email_code.digits'   => "邮箱验证码有误",
        ];
    }
}
