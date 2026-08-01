<?php

namespace App\Http\Controllers\V1\Guest;

use App\Http\Controllers\Controller;
use App\Models\Plan;
use App\Services\PlanService;
use Illuminate\Support\Facades\Cache;

class PlanController extends Controller
{
    public function fetch()
    {
        $plans = Cache::remember('guest_plan_fetch', 60, function () {
            $counts = PlanService::countActiveUsers();
            return Plan::select([
                'id',
                'name',
                'content',
                'transfer_enable',
                'device_limit',
                'speed_limit',
                'month_price',
                'quarter_price',
                'half_year_price',
                'year_price',
                'two_year_price',
                'three_year_price',
                'onetime_price',
                'capacity_limit'
            ])
                ->where('show', 1)
                ->orderBy('sort', 'ASC')
                ->get()
                ->map(function (Plan $plan) use ($counts) {
                    if ($plan->capacity_limit !== null) {
                        $count = isset($counts[$plan->id]) ? (int)$counts[$plan->id]->count : 0;
                        $plan->capacity_limit = max(0, (int)$plan->capacity_limit - $count);
                    }

                    return $plan;
                });
        });

        return response([
            'data' => $plans
        ]);
    }
}
