<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class ProcessedImage extends Model
{
    use HasFactory;
    protected $fillable = [
        'image_id',
        'corrected_path',
        'detection_path',
        'ai_response_json',
        'error_edits_json',
        'public_token',
        'public_token_expires_at',
        'public_view_enabled',
        'thumb_path',
        'thumb_url',
    ];

    protected $casts = [
        'error_edits_json' => 'array',
        'metrics' => 'array',
        'results' => 'array',
        'errors' => 'array',
    ];

    public function issuePublicToken(?Carbon $expiresAt = null): void
    {
        $this->public_token = (string) Str::uuid();
        $this->public_token_expires_at = $expiresAt ?: now()->addMonths(6);
        $this->public_view_enabled = true;
        $this->save();
    }

    /** Revoca acceso público */
    public function revokePublicToken(): void
    {
        $this->public_token = null;
        $this->public_token_expires_at = null;
        $this->public_view_enabled = false;
        $this->save();
    }

    /** Valida token */
    public function isPublicTokenValid(?string $token): bool
    {
        if (!$this->public_view_enabled) return false;
        if (!$token || $this->public_token !== $token) return false;
        if ($this->public_token_expires_at && now()->greaterThan($this->public_token_expires_at)) return false;
        return true;
    }

    public function getOriginalUrlAttribute()
    {
        return $this->original_path
            ? Storage::disk('wasabi')->url($this->original_path)
            : null;
    }

    public function getCorrectedUrlAttribute()
    {
        return $this->corrected_path
            ? Storage::disk('wasabi')->url($this->corrected_path)
            : null;
    }

    public function image()
    {
        return $this->belongsTo(Image::class);
    }
}
