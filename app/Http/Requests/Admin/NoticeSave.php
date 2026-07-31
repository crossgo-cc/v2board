<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => 'nullable|integer|exists:v2_notice,id',
            'title' => 'required|string|max:255',
            'content' => 'required|string',
            'is_pinned' => 'sometimes|boolean',
            'img_url' => 'nullable|url|max:255',
            'tags' => 'nullable|array|max:10',
            'tags.*' => 'string|max:32'
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'content.required' => '内容不能为空',
            'img_url.url' => '图片URL格式不正确',
            'tags.array' => '标签格式不正确',
            'tags.max' => '标签不能超过10个',
            'tags.*.string' => '标签格式不正确',
            'tags.*.max' => '单个标签不能超过32个字符'
        ];
    }
}
