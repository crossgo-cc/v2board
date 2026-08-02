<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\NoticeFetch;
use App\Models\Notice;

class NoticeController extends Controller
{
    public function fetch(NoticeFetch $request)
    {
        $params = $request->validated();

        if (isset($params['id'])) {
            $notice = Notice::where('id', $params['id'])
                ->where('show', 1)
                ->first();

            if (!$notice) {
                return response([
                    'message' => 'Notice not found'
                ], 404);
            }

            return response([
                'data' => $notice
            ]);
        }

        $current = $params['current'] ?? 1;
        $pageSize = $params['pageSize'] ?? 5;

        $latestId = Notice::where('show', 1)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('id');
        $model = Notice::where('show', 1);
        $total = $model->count();
        $res = $model->orderByDesc('is_pinned')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->forPage($current, $pageSize)
            ->get();
        $res->each(function (Notice $notice) use ($latestId) {
            $notice->setAttribute(
                'is_latest',
                $latestId !== null && (int) $notice->getKey() === (int) $latestId
            );
        });

        return response([
            'data' => $res,
            'total' => $total
        ]);
    }
}
