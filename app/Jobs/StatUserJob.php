<?php

namespace App\Jobs;

use App\Models\StatUser;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class StatUserJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $data;
    protected $server;
    protected $protocol;
    protected $recordType;

    public $tries = 3;
    public $timeout = 60;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(array $data, array $server, $protocol, $recordType = 'd')
    {
        $this->onQueue('stat');
        $this->data =$data;
        $this->server = $server;
        $this->protocol = $protocol;
        $this->recordType = $recordType;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        $recordAt = strtotime(date('Y-m-d'));
        if ($this->recordType === 'm') {
            //
        }
        if (empty($this->data)) {
            return;
        }

        $table = (new StatUser())->getTable();
        $now = time();

        try {
            DB::transaction(function () use ($table, $now, $recordAt) {
                foreach (array_chunk($this->data, 500, true) as $chunk) {
                    $placeholders = [];
                    $bindings = [];
                    foreach ($chunk as $userId => $trafficData) {
                        if (!is_numeric($userId) || !is_array($trafficData) || !isset($trafficData[0], $trafficData[1])) {
                            continue;
                        }
                        $placeholders[] = '(?, ?, ?, ?, ?, ?, ?, ?)';
                        array_push(
                            $bindings,
                            $userId,
                            $this->server['rate'],
                            $trafficData[0],
                            $trafficData[1],
                            $this->recordType,
                            $recordAt,
                            $now,
                            $now
                        );
                    }
                    if (empty($bindings)) {
                        continue;
                    }
                    DB::statement(
                        "INSERT INTO {$table} (`user_id`, `server_rate`, `u`, `d`, `record_type`, `record_at`, `created_at`, `updated_at`) VALUES "
                        . implode(',', $placeholders)
                        . " ON DUPLICATE KEY UPDATE `u` = `u` + VALUES(`u`), `d` = `d` + VALUES(`d`), `record_type` = VALUES(`record_type`), `updated_at` = VALUES(`updated_at`)",
                        $bindings
                    );
                }
            });
        } catch (\Exception $e) {
            abort(500, '用户统计数据失败'. $e->getMessage());
        }
    }
}
