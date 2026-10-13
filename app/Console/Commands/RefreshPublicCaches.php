<?php

namespace App\Console\Commands;

use App\Services\Website\PublicCache;
use Illuminate\Console\Command;

/**
 * Daily housekeeping (spec 17.1, 17.4, SCR-034). Clears the homepage, stats, menu and sitemap caches, so a change that comes
 * from time passing, such as an open role past its deadline, shows up without waiting for an edit. They rebuild on the next visit.
 */
class RefreshPublicCaches extends Command
{
    protected $signature = 'groibe:refresh-public-caches';

    protected $description = 'Clear the public homepage, stats, menu and sitemap caches so they rebuild fresh';

    public function handle(PublicCache $cache): int
    {
        $cache->flush();

        $this->info('Public caches cleared.');

        return self::SUCCESS;
    }
}
