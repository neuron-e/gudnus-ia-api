<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ImageAnalysisResult extends Model
{
    use HasFactory;
    protected $fillable = [
        'image_id',
        'rows',
        'columns',
        'integrity_score',
        'luminosity_score',
        'uniformity_score',
        'microcracks_count',
        'finger_interruptions_count',
        'black_edges_count',
        'cells_with_different_intensity',
        'ai_response_json',
    ];

    protected $casts = [
        'ai_response_json' => 'array',
    ];

    public function image()
    {
        return $this->belongsTo(Image::class);
    }
}
