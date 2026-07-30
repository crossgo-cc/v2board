<?php

namespace App\Console\Commands;

use App\Services\TicketImageService;
use Illuminate\Console\Command;

class CleanupTicketImages extends Command
{
    protected $signature = 'ticket-images:cleanup';
    protected $description = '清理超过保留期限的工单图片';

    public function handle(): int
    {
        $days = (int)config('v2board.ticket_image_retention_days', 0);
        if ($days === 0) {
            $this->info('工单图片自动清理未启用');
            return self::SUCCESS;
        }

        try {
            $count = (new TicketImageService())->deleteExpired($days);
            $this->info("已清理 {$count} 张过期工单图片");
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
