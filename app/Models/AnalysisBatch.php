<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AnalysisBatch extends Model
{
    use HasFactory;
    protected $fillable = [
        'project_id',
        'image_ids',
        'total_images',
        'processed_images',
        'errors', // ✅ Agregado
        'status'
    ];

}
