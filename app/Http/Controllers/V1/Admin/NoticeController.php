<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class NoticeController extends Controller
{
    public function fetch(Request $request)
    {
        return response([
            'data' => Notice::orderByDesc('is_pinned')
                ->orderByDesc('id')
                ->get()
        ]);
    }

    public function save(NoticeSave $request)
    {
        $data = $request->validated();
        $id = $data['id'] ?? null;
        unset($data['id']);
        $data['tags'] = $data['tags'] ?? [];

        if (!$id) {
            Notice::create($data);
        } else {
            $notice = Notice::findOrFail($id);
            $notice->update($data);
        }
        $this->forgetGuestCache();

        return response([
            'data' => true
        ]);
    }

    public function show(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'show' => 'required|boolean'
        ]);
        $notice = Notice::findOrFail($data['id']);
        $notice->show = $data['show'];
        $notice->save();
        $this->forgetGuestCache();

        return response([
            'data' => true
        ]);
    }

    public function pin(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer',
            'is_pinned' => 'required|boolean'
        ]);
        $notice = Notice::findOrFail($data['id']);
        $notice->is_pinned = $data['is_pinned'];
        $notice->save();
        $this->forgetGuestCache();

        return response([
            'data' => true
        ]);
    }

    public function drop(Request $request)
    {
        $data = $request->validate([
            'id' => 'required|integer'
        ]);
        $notice = Notice::findOrFail($data['id']);
        $notice->delete();
        $this->forgetGuestCache();

        return response([
            'data' => true
        ]);
    }

    private function forgetGuestCache(): void
    {
        for ($pageSize = 1; $pageSize <= 10; $pageSize++) {
            Cache::forget("guest_notice_fetch_{$pageSize}");
        }
    }
}
