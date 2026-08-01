<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserTransfer extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'transfer_amount' => 'required|integer|min:1'
        ];
    }

    public function messages()
    {
        return [
            'transfer_amount.required' => "划转金额不能为空",
            'transfer_amount.integer' => "划转金额参数有误",
            'transfer_amount.min' => "划转金额参数有误"
        ];
    }
}
