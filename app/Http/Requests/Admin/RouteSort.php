<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class RouteSort extends FormRequest
{
    public function rules()
    {
        return [
            'route_ids' => 'required|array',
            'route_ids.*' => 'required|integer|distinct|exists:v2_server_route,id'
        ];
    }

    public function messages()
    {
        return [
            'route_ids.required' => '路由ID不能为空',
            'route_ids.array' => '路由ID格式有误',
            'route_ids.*.required' => '路由ID不能为空',
            'route_ids.*.integer' => '路由ID格式有误',
            'route_ids.*.distinct' => '路由ID不能重复',
            'route_ids.*.exists' => '路由不存在'
        ];
    }
}
