<?php
namespace App\Services;


use App\Jobs\SendEmailJob;
use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class TicketService {
    public function reply($ticket, $message, $userId)
    {
        DB::beginTransaction();
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        if ($userId !== $ticket->user_id) {
            $ticket->reply_status = 1;
        } else {
            $ticket->reply_status = 0;
        }
        if (!$ticketMessage || !$ticket->save()) {
            DB::rollback();
            return false;
        }
        DB::commit();
        return $ticketMessage;
    }

    public function replyByAdmin($ticketId, $message, $userId):void
    {
        $ticket = Ticket::where('id', $ticketId)
            ->first();
        if (!$ticket) {
            abort(500, '工单不存在');
        }
        
        DB::beginTransaction();
        $ticketMessage = TicketMessage::create([
            'user_id' => $userId,
            'ticket_id' => $ticket->id,
            'message' => $message
        ]);
        $ticket->status = 0;
        if ($userId !== $ticket->user_id) {
            $ticket->reply_status = 1;
        } else {
            $ticket->reply_status = 0;
        }
        $ticket->touch();
        if (!$ticketMessage || !$ticket->save()) {
            DB::rollback();
            abort(500, '工单回复失败');
        }
        DB::commit();
        $this->sendEmailNotify($ticket, $ticketMessage);
    }

    public function sendAdminEmailNotify(Ticket $ticket, string $message, $userId = null, string $action = 'new')
    {
        $emails = config('v2board.ticket_notify_email', []);
        if (empty($emails)) {
            return;
        }

        $user = $userId ? User::find($userId) : User::find($ticket->user_id);
        $actionText = $action === 'reply' ? '回复了工单' : '提交了新工单';
        $userEmail = $user ? $user->email : '未知';
        $subject = '用户' . $actionText . ' #' . $ticket->id . ' - ' . $ticket->subject;
        $content = "用户邮箱：" . e($userEmail) . "\r\n工单ID：#{$ticket->id}\r\n主题：" . e($ticket->subject) . "\r\n内容：" . e($message);
        foreach ($emails as $email) {
            $this->sendNotifyEmail($email, $subject, $content);
        }
    }

    // 半小时内不再重复通知
    private function sendEmailNotify(Ticket $ticket, TicketMessage $ticketMessage)
    {
        if (!(int)config('v2board.ticket_reply_email_notify_enable', 1)) {
            return;
        }

        $user = User::find($ticket->user_id);
        if (!$user) {
            return;
        }

        $cacheKey = 'ticket_sendEmailNotify_' . $ticket->user_id;
        if (!Cache::get($cacheKey)) {
            Cache::put($cacheKey, 1, 1800);
            $this->sendNotifyEmail(
                $user->email,
                '您在' . config('v2board.app_name', 'V2Board') . '的工单得到了回复',
                "主题：" . e($ticket->subject) . "\r\n回复内容：" . e($ticketMessage->message)
            );
        }
    }

    private function sendNotifyEmail($email, $subject, $content)
    {
        SendEmailJob::dispatch([
            'email' => $email,
            'subject' => str_replace(["\r", "\n"], ' ', $subject),
            'template_name' => 'notify',
            'template_value' => [
                'name' => config('v2board.app_name', 'V2Board'),
                'url' => config('v2board.app_url'),
                'content' => $content
            ]
        ]);
    }
}
