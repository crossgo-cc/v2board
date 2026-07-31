<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class NoticeFetch extends FormRequest
{
    public function rules()
    {
        return [
            'id' => 'sometimes|required|integer|min:1',
            'current' => 'sometimes|integer|min:1',
            'pageSize' => 'sometimes|integer|between:1,100'
        ];
    }

    public function messages()
    {
        return [
            'id.required' => '公告ID不能为空',
            'id.integer' => '公告ID格式不正确',
            'current.integer' => '页码格式不正确',
            'current.min' => '页码不能小于1',
            'pageSize.integer' => '分页条数格式不正确',
            'pageSize.between' => '分页条数必须在1到100之间'
        ];
    }
}
