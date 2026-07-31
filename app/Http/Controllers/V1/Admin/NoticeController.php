<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\NoticeSave;
use App\Models\Notice;
use Illuminate\Http\Request;

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
            if ($this->isStaff($request) && $notice->show) {
                abort(403, '员工不能修改已发布公告');
            }
            $notice->update($data);
        }

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
        if ($this->isStaff($request) && $notice->show) {
            abort(403, '员工不能置顶已发布公告');
        }
        $notice->is_pinned = $data['is_pinned'];
        $notice->save();

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
        if ($this->isStaff($request) && $notice->show) {
            abort(403, '员工不能删除已发布公告');
        }
        $notice->delete();

        return response([
            'data' => true
        ]);
    }

    private function isStaff(Request $request): bool
    {
        $user = $request->input('user', []);

        return !empty($user['is_staff']) && empty($user['is_admin']);
    }
}
