<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\TicketSave;
use App\Http\Requests\User\TicketWithdraw;
use App\Models\User;
use App\Models\Order;
use App\Services\TicketService;
use App\Services\TicketImageService;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Utils\Dict;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function fetch(Request $request)
    {
        $userId = $request->user['id'];
        $ticketId = $request->input('id');

        if ($ticketId) {
            $ticket = Ticket::where('id', $ticketId)
                ->where('user_id', $userId)
                ->firstOrFail();

            $ticket['message'] = TicketMessage::where('ticket_id', $ticket->id)->get();
            for ($i = 0; $i < count($ticket['message']); $i++) {
                if ($ticket['message'][$i]['user_id'] !== $ticket->user_id) {
                    $ticket['message'][$i]['is_me'] = false;
                } else {
                    $ticket['message'][$i]['is_me'] = true;
                }
            }

            return response(['data' => $ticket]);

        }
        $ticket = Ticket::where('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->get();
        return response([
            'data' => $ticket
        ]);
    }

    public function save(TicketSave $request)
    {
        $ticketImageService = new TicketImageService();
        $imageBatch = ['items' => []];

        try {
            // 获取工单状态
            $ticketStatus = config('v2board.ticket_status', 0);

            switch ($ticketStatus) {
                case 0:
                    // 完全开放，不禁止任何工单
                    break;
                case 1:
                    // 仅限有付费订单用户
                    $hasOrder = Order::where('user_id', $request->user['id'])
                        ->whereIn('status', [3, 4])
                        ->exists();

                    if (!$hasOrder) {
                        throw new \Exception('请先购买套餐');
                    }
                    break;
                case 2:
                    // 完全禁止所有工单
                    throw new \Exception('当前套餐不允许发起工单');
                    break;
                default:
                    // 处理未知状态
                    throw new \Exception('未知的工单状态');
            }

            if ((int)Ticket::where('status', 0)->where('user_id', $request->user['id'])->count()) {
                throw new \Exception("存在其它工单尚未处理");
            }

            $imageBatch = rescue(
                fn () => $ticketImageService->uploadBatch($request->file('images', [])),
                fn () => abort(500, '图片上传暂时不可用，请联系管理员'),
                false
            );
            $message = $ticketImageService->appendToMessage($request->input('message'), $imageBatch);

            DB::beginTransaction();
            if ((int)Ticket::where('status', 0)->where('user_id', $request->user['id'])->lockForUpdate()->count()) {
                throw new \Exception("存在其它工单尚未处理");
            }

            $ticketData = $request->only(['subject', 'level']) + ['user_id' => $request->user['id']];
            $ticket = Ticket::create($ticketData);

            TicketMessage::create([
                'user_id' => $request->user['id'],
                'ticket_id' => $ticket->id,
                'message' => $message
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $ticketImageService->cleanup($imageBatch);
            abort(500, $e->getMessage());
        }

        $ticketService = new TicketService();
        $this->sendTicketNotifications($ticketService, $ticket, $message, $request->user['id'], 'new');
        return response([
            'data' => true
        ]);
    }

    public function reply(Request $request)
    {
        $request->validate([
            'id' => 'required|integer',
            'message' => 'nullable|string|max:12000|required_without:images',
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

        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, "工单不存在");
        }
        if ($ticket->status) {
            abort(500, "工单已关闭，无法回复");
        }
        if ($request->user['id'] == $this->getLastMessage($ticket->id)->user_id) {
            abort(500, "请等待技术支持回复");
        }

        $ticketImageService = new TicketImageService();
        $imageBatch = ['items' => []];
        $ticketService = new TicketService();

        try {
            $imageBatch = rescue(
                fn () => $ticketImageService->uploadBatch($request->file('images', [])),
                fn () => abort(500, '图片上传暂时不可用，请联系管理员'),
                false
            );
            $message = $ticketImageService->appendToMessage((string)$request->input('message'), $imageBatch);
            if (!$ticketService->reply($ticket, $message, $request->user['id'])) {
                throw new \RuntimeException("工单回复失败");
            }
        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $ticketImageService->cleanup($imageBatch);
            abort(500, $e->getMessage());
        }

        $this->sendTicketNotifications($ticketService, $ticket, $message, $request->user['id'], 'reply');
        return response([
            'data' => true
        ]);
    }


    public function close(Request $request)
    {
        if (empty($request->input('id'))) {
            abort(500, "参数错误");
        }
        $ticket = Ticket::where('id', $request->input('id'))
            ->where('user_id', $request->user['id'])
            ->first();
        if (!$ticket) {
            abort(500, "工单不存在");
        }
        $ticket->status = 1;
        if (!$ticket->save()) {
            abort(500, "关闭失败");
        }
        return response([
            'data' => true
        ]);
    }

    private function getLastMessage($ticketId)
    {
        return TicketMessage::where('ticket_id', $ticketId)
            ->orderBy('id', 'DESC')
            ->first();
    }

    public function withdraw(TicketWithdraw $request)
    {
        if ((int)config('v2board.withdraw_close_enable', 0)) {
            abort(500, 'user.ticket.withdraw.not_support_withdraw');
        }
        if (
			!in_array(
				$request->input('withdraw_method'),
				config(
					'v2board.commission_withdraw_method',
					Dict::WITHDRAW_METHOD_WHITELIST_DEFAULT
				)
			)
		) {
            abort(500, "不支持的提现方式");
        }
        $user = User::find($request->user['id']);
        $limit = config('v2board.commission_withdraw_limit', 100);
        if ($limit > ($user->commission_balance / 100)) {
            abort(500, '当前系统要求的最少提现佣金为：¥' . $limit . 'CNY');
        }
        DB::beginTransaction();
        $subject = "[提现申请] 本工单由系统发出";
        $ticket = Ticket::create([
            'subject' => $subject,
            'level' => 2,
            'user_id' => $request->user['id']
        ]);
        if (!$ticket) {
            DB::rollback();
            abort(500, "工单创建失败");
        }
        $message = sprintf(
			"%s\r\n%s",
            "提现方式" . "：" . $request->input('withdraw_method'),
            "提现账号" . "：" . $request->input('withdraw_account')
        );
        $ticketMessage = TicketMessage::create([
            'user_id' => $request->user['id'],
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if (!$ticketMessage) {
            DB::rollback();
            abort(500, "工单创建失败");
        }
        DB::commit();
        return response([
            'data' => true
        ]);
    }

    private function sendTicketNotifications(
        TicketService $ticketService,
        Ticket $ticket,
        string $message,
        int $userId,
        string $action
    ): void {
        try {
            $ticketService->sendAdminEmailNotify($ticket, $message, $userId, $action);
        } catch (\Throwable $e) {
            report($e);
        }
    }

}
