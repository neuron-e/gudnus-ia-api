<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ZipAnalysis extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'project_id',
        'original_filename',
        'file_path',
        'file_size',
        'status',
        'progress',
        'total_files',
        'valid_images',
        'images_data',
        'error_message',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'progress' => 'integer',
        'total_files' => 'integer',
        'valid_images' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
