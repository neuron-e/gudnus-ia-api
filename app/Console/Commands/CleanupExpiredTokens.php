<?php

namespace App\Console\Commands;

use App\Models\ProcessedImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CleanupExpiredTokens extends Command
{
    protected $signature = 'tokens:cleanup';

    protected $description = 'Disable expired public tokens for processed images';

    public function handle(): int
    {
        $expiredCount = ProcessedImage::where('public_view_enabled', true)
            ->whereNotNull('public_token_expires_at')
            ->where('public_token_expires_at', '<', now())
            ->update(['public_view_enabled' => false]);

        if ($expiredCount > 0) {
            $this->info("Disabled {$expiredCount} expired tokens.");
            Log::info("Disabled {$expiredCount} expired public tokens");
        } else {
            $this->info('No expired tokens found.');
        }

        return Command::SUCCESS;
    }
}
