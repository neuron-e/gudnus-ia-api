<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Image extends Model
{
    use HasFactory;
    protected $fillable = [
        'folder_id',
        'original_path',
        'status',
        'is_processed', // boolean
    ];

    public function folder()
    {
        return $this->belongsTo(Folder::class);
    }

    public function processedImage()
    {
        return $this->hasOne(ProcessedImage::class);
    }

    public function analysisResult()
    {
        return $this->hasOne(ImageAnalysisResult::class);
    }
}
