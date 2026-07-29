<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class TicketSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'subject' => 'required|string|max:120',
            'level' => 'required|in:0,1,2',
            'message' => 'required|string|max:12000',
            'images' => 'nullable|array|max:3',
            'images.*' => 'file|max:2048|mimetypes:image/jpeg,image/png,image/webp,image/gif',
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => __('Ticket subject cannot be empty'),
            'level.required' => __('Ticket level cannot be empty'),
            'level.in' => __('Incorrect ticket level format'),
            'message.required' => __('Message cannot be empty'),
            'subject.max' => '工单主题不能超过120个字符',
            'message.max' => '工单内容不能超过12000个字符',
            'images.max' => '每个工单最多上传3张图片',
            'images.*.max' => '单张图片不能超过2MB',
            'images.*.mimetypes' => '图片仅支持JPEG、PNG、WebP和GIF格式',
        ];
    }
}
