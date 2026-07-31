<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Notice extends Model
{
    protected $table = 'v2_notice';
    protected $dateFormat = 'U';
    protected $guarded = ['id'];
    protected $attributes = [
        'show' => 0,
        'tags' => '[]'
    ];
    protected $casts = [
        'created_at' => 'timestamp',
        'updated_at' => 'timestamp',
        'show' => 'boolean',
        'tags' => 'array'
    ];

    public function getTagsAttribute($value)
    {
        if ($value === null || $value === '') {
            return [];
        }

        return $this->fromJson($value) ?? [];
    }
}
