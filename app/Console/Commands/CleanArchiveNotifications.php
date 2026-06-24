<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class CleanArchiveNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:clean-archive';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete ARCHIVE notifications older than 10 days';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $deleted = DB::table('notifications')
            ->where('notifiable_id', 2)
            ->where('created_at', '<', now()->subDays(10))
            ->delete();

        $this->info("Deleted {$deleted} old notifications.");

        return self::SUCCESS;
    }
}
