<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'auto_renewal' => 'in:0,1',
            'auto_reset_traffic' => 'in:0,1',
            'remind_expire' => 'in:0,1',
            'remind_traffic' => 'in:0,1'
        ];
    }

    public function messages()
    {
        return [
            'show.in' => "过期提醒参数有误",
            'renew.in' => "流量提醒参数有误"
        ];
    }
}
