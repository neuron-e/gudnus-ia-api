<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProcessedImage extends Model
{
    use HasFactory;
    protected $fillable = [
        'image_id',
        'corrected_path',
        'detection_path',
        'ai_response_json',
    ];

    public function image()
    {
        return $this->belongsTo(Image::class);
    }
}
