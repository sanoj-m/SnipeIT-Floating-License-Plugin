<?php

namespace SnipeIt\FloatingLicenses\Console;

use Illuminate\Console\Command;
use SnipeIt\FloatingLicenses\Services\FloatingLicenseService;

class ExpireFloatingAllocations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'floating-licenses:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire stale floating license allocations (expired leases or idle heartbeats)';

    /**
     * Execute the console command.
     */
    public function handle(FloatingLicenseService $service): int
    {
        $count = $service->expireStale();

        $this->info(trans('floating-licenses::floating.message.expired_count', ['count' => $count]));

        return Command::SUCCESS;
    }
}
