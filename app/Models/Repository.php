<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Repository extends Model
{
    protected $fillable = [
        'github_id', 'owner', 'name', 'full_name', 'description', 'language',
        'visibility', 'html_url', 'default_branch', 'stars', 'forks',
        'open_issues', 'topics', 'archived', 'is_fork', 'pushed_at', 'notes', 'synced_at',
    ];

    protected function casts(): array
    {
        return [
            'topics'    => 'array',
            'archived'  => 'boolean',
            'is_fork'   => 'boolean',
            'pushed_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }
}
