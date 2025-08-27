<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class UnifiedBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'type',
        'status',
        'config',
        'input_data',
        'total_items',
        'processed_items',
        'failed_items',
        'skipped_items',
        'active_jobs',
        'job_ids',
        'started_at',
        'completed_at',
        'last_activity_at',
        'estimated_duration_seconds',
        'storage_path',
        'generated_files',
        'download_url',
        'expires_at',
        'cancellation_reason',
        'cancellation_started_at',
        'error_summary',
        'last_error',
        'retry_count',
        'created_by',
        'metadata'
    ];

    protected $casts = [
        'config' => 'array',
        'input_data' => 'array',
        'job_ids' => 'array',
        'generated_files' => 'array',
        'error_summary' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'expires_at' => 'datetime',
        'cancellation_started_at' => 'datetime'
    ];

    // ✅ Relaciones
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    // ✅ Scopes útiles
    /**
     * Scope: Batches activos
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['pending', 'processing', 'paused']);
    }


    public function scopeStuck($query, $hours = 12)
    {
        return $query->where('status', 'processing')
            ->where('last_activity_at', '<', now()->subHours($hours))
            ->where('active_jobs', '>', 0);
    }

    public function scopeExpired($query)
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '<', now());
    }


    /**
     * Scope: Batches completados
     */
    public function scopeCompleted($query)
    {
        return $query->whereIn('status', ['completed', 'completed_with_errors']);
    }

    /**
     * Scope: Batches fallidos
     */
    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'cancelled']);
    }


    // ✅ Métodos de estado
    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'processing', 'paused']);
    }

    public function isCompleted(): bool
    {
        return in_array($this->status, ['completed', 'completed_with_errors']);
    }

    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    public function isExpired(int $hours = 24): bool
{
    if (!$this->isActive()) {
        return false;
    }

    return $this->last_activity_at &&
        $this->last_activity_at->diffInHours(now()) >= $hours;
}

    public function isCancelled(): bool
    {
        return in_array($this->status, ['cancelled', 'cancelling']);
    }

    public function hasExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isStuck(int $hours = 12): bool
    {
        return $this->status === 'processing' &&
            $this->active_jobs > 0 &&
            $this->last_activity_at &&
            $this->last_activity_at->diffInHours(now()) >= $hours;
    }

    // ✅ Métodos de progreso
    public function getProgressPercentage(): float
    {
        if ($this->total_items <= 0) {
            return 0.0;
        }

        $completed = $this->processed_items + $this->failed_items;
        return round(($completed / $this->total_items) * 100, 2);
    }

    public function getEstimatedTimeRemaining(): ?int
    {
        if (!$this->isActive() || $this->processed_items <= 0) {
            return null;
        }

        $elapsedMinutes = $this->started_at ?
            $this->started_at->diffInMinutes(now()) : 0;

        if ($elapsedMinutes <= 0) {
            return null;
        }

        $remainingItems = $this->total_items - $this->processed_items - $this->failed_items;
        $itemsPerMinute = $this->processed_items / $elapsedMinutes;

        if ($itemsPerMinute <= 0) {
            return null;
        }

        return (int) ceil($remainingItems / $itemsPerMinute);
    }

    // ✅ Métodos de gestión de jobs
    public function incrementActiveJobs(): void
    {
        $this->increment('active_jobs');
        $this->touch('last_activity_at');
    }

    public function decrementActiveJobs(): void
    {
        if ($this->active_jobs > 0) {
            $this->decrement('active_jobs');
            $this->touch('last_activity_at');
        }
    }

    public function addJobId(string $jobId): void
    {
        $jobIds = $this->job_ids ?? [];
        $jobIds[] = $jobId;
        $this->update(['job_ids' => array_unique($jobIds)]);
    }

    public function removeJobId(string $jobId): void
    {
        $jobIds = $this->job_ids ?? [];
        $this->update(['job_ids' => array_values(array_diff($jobIds, [$jobId]))]);
    }

    // ✅ Métodos de progreso
    public function incrementProcessed(): void
    {
        $this->increment('processed_items');
        $this->decrementActiveJobs();

        // Auto-completar si terminamos
        if ($this->shouldAutoComplete()) {
            $this->markAsCompleted();
        }
    }

    public function incrementFailed(string $error = null): void
    {
        $this->increment('failed_items');
        $this->decrementActiveJobs();

        if ($error) {
            $errors = $this->error_summary ?? [];
            $errors[] = [
                'error' => $error,
                'timestamp' => now()->toISOString()
            ];
            $this->update(['error_summary' => $errors, 'last_error' => $error]);
        }

        // Auto-completar si terminamos
        if ($this->shouldAutoComplete()) {
            $this->markAsCompleted();
        }
    }

    public function incrementSkipped(): void
    {
        $this->increment('skipped_items');
        $this->decrementActiveJobs();

        if ($this->shouldAutoComplete()) {
            $this->markAsCompleted();
        }
    }

    // ✅ Auto-completion logic
    private function shouldAutoComplete(): bool
    {
        $totalCompleted = $this->processed_items + $this->failed_items + $this->skipped_items;
        return $this->active_jobs <= 0 && $totalCompleted >= $this->total_items;
    }

    public function markAsCompleted(): void
    {
        $status = $this->failed_items > 0 ? 'completed_with_errors' : 'completed';

        $this->update([
            'status' => $status,
            'completed_at' => now(),
            'active_jobs' => 0
        ]);

        Log::info("✅ Batch {$this->id} auto-completado como {$status}");
    }

    // ✅ Métodos de storage
    public function getStorageBasePath(): string
    {
        return $this->storage_path ?? "projects/{$this->project_id}/batches/{$this->id}";
    }

    public function addGeneratedFile(string $path, string $type = null): void
    {
        $files = $this->generated_files ?? [];
        $files[] = [
            'path' => $path,
            'type' => $type,
            'created_at' => now()->toISOString()
        ];
        $this->update(['generated_files' => $files]);
    }

    // ✅ Métodos de logging
    public function logInfo(string $message, array $context = []): void
    {
        $context = array_merge([
            'batch_id' => $this->id,
            'batch_type' => $this->type,
            'batch_status' => $this->status
        ], $context);

        Log::info("Batch {$this->id}: {$message}", $context);
    }

    /**
     * Log de error
     */
    public function logError(string $message, array $context = []): void
    {
        $context = array_merge([
            'batch_id' => $this->id,
            'batch_type' => $this->type,
            'batch_status' => $this->status
        ], $context);

        Log::error("Batch {$this->id}: {$message}", $context);

        // ✅ Actualizar último error en batch
        $this->update([
            'last_error' => $message,
            'last_activity_at' => now()
        ]);
    }
}
