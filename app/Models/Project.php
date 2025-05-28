<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Project extends Model
{
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'panel_brand',
        'panel_model',
        'installation_name',
        'inspector_name',
        'cell_count',
        'column_count',
        'user_id',
    ];

    protected $appends = ['created_by'];
    public function getCreatedByAttribute() {
        return $this->user->name;
    }

    public function children()
    {
        return $this->hasMany(Folder::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
