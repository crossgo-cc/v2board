<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        $params = $request->validate([
            'pageSize' => 'sometimes|integer|between:1,10'
        ]);

        $pageSize = $params['pageSize'] ?? 3;
        $notices = Cache::remember("guest_notice_fetch_{$pageSize}", 60, function () use ($pageSize) {
            $notices = collect();

            Notice::select([
                'id',
                'title',
                'content',
                'img_url',
                'tags',
                'created_at'
            ])
                ->where('show', 1)
                ->orderByDesc('is_pinned')
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->chunk(50, function ($items) use ($notices, $pageSize) {
                    foreach ($items as $notice) {
                        if (!in_array('首页', $notice->tags, true)) continue;

                        $notices->push([
                            'id' => $notice->id,
                            'title' => $notice->title,
                            'content' => $notice->content,
                            'img_url' => $notice->img_url,
                            'created_at' => $notice->created_at
                        ]);

                        if ($notices->count() >= $pageSize) return false;
                    }
                });

            return $notices;
        });

        return response([
            'data' => $notices
        ]);
    }
}
