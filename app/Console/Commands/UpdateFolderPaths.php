<?php

namespace App\Console\Commands;

use App\Models\Folder;
use Illuminate\Console\Command;

class UpdateFolderPaths extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'folders:update-paths';
    protected $description = 'Regenerar full_path para todas las carpetas';

    /**
     * Execute the console command.
     */

    public function handle()
    {
        $folders = Folder::with('parent')->get();

        $count = 0;
        foreach ($folders as $folder) {
            $path = $folder->generateFullPath();
            if ($folder->full_path !== $path) {
                $folder->full_path = $path;
                $folder->save();
                $count++;
            }
        }

        $this->info("Se actualizaron {$count} carpetas con sus rutas completas.");
    }
}
