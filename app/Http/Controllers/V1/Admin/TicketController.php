<?php

namespace App\Http\Controllers\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Services\TicketImageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        if ($request->input('id')) {
            $ticket = Ticket::where('id', $request->input('id'))
                ->first();
            if (!$ticket) {
                abort(500, '工单不存在');
            }
            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = true;
                } else {
                    $ticket['message'][$i]['is_me'] = false;
                }
            }
            return response([
                'data' => $ticket
            ]);
        }
        $current = $request->input('current') ? $request->input('current') : 1;
        $pageSize = $request->input('pageSize') >= 10 ? $request->input('pageSize') : 10;
        $model = Ticket::orderBy('updated_at', 'DESC');
        if ($request->input('status') !== NULL) {
            $model->where('status', $request->input('status'));
        }
        if ($request->input('reply_status') !== NULL) {
            $model->whereIn('reply_status', $request->input('reply_status'));
        }
        if ($request->input('email') !== NULL) {
            $user = User::where('email', $request->input('email'))->first();
            if ($user) $model->where('user_id', $user->id);
        }
        $total = $model->count();
        $res = $model->forPage($current, $pageSize)
            ->get();
        return response([
            'data' => $res,
            'total' => $total
        ]);
    }

    public function reply(Request $request)
    {
        TicketImageService::normalizeRequestImages($request);
        $request->validate([
            'id' => 'required|integer',
            'message' => 'nullable|string|max:12000|required_without:images',
            'image_count' => 'nullable|integer|min:0|max:3',
            'images' => 'nullable|array|max:3|required_without:message',
            'images.*' => 'file|max:2048|mimetypes:image/jpeg,image/png,image/webp,image/gif',
        ], [
            'message.required_without' => '消息和图片不能同时为空',
            'message.max' => '消息内容不能超过12000个字符',
            'images.required_without' => '消息和图片不能同时为空',
            'images.max' => '每条消息最多上传3张图片',
            'images.*.max' => '单张图片不能超过2MB',
            'images.*.mimetypes' => '图片仅支持JPEG、PNG、WebP和GIF格式',
        ]);

        $images = TicketImageService::requestImages($request);
        if (!Ticket::where('id', $request->input('id'))->exists()) {
            abort(500, '工单不存在');
        }

        $ticketImageService = new TicketImageService();
        $imageBatch = ['token' => null, 'items' => []];
        $ticketService = new TicketService();

        try {
            $imageBatch = $ticketImageService->uploadBatch($images);
            $message = $ticketImageService->appendToMessage((string)$request->input('message'), $imageBatch);
            $ticketService->replyByAdmin(
                $request->input('id'),
                $message,
                $request->user['id']
            );
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $ticketImageService->cleanup($imageBatch);
            abort(500, $e->getMessage());
        }

        return response([
            'data' => true
        ]);
    }

    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, '参数错误');
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->first();
        if (!$ticket) {
            abort(500, '工单不存在');
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, '关闭失败');
        }
        return response([
            'data' => true
        ]);
    }
}
