<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImageBatch extends Model
{
    protected $fillable = [
        'project_id', 'type', 'total', 'processed', 'errors', 'error_messages', 'status'
    ];

    protected $casts = [
        'error_messages' => 'array',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}

