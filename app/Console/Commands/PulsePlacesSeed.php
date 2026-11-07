<?php

namespace App\Console\Commands;

use Database\Seeders\PulsePlaceSeeder;
use Illuminate\Console\Command;

class PulsePlacesSeed extends Command
{
    protected $signature = 'pulse:places:seed';

    protected $description = 'Load or refresh the Dot.Pulse gazetteer (countries, South African provinces, cities and suburbs, world cities). Idempotent.';

    public function handle(PulsePlaceSeeder $seeder): int
    {
        $seeder->run();
        $this->info('Gazetteer loaded: '.$seeder->count().' places.');

        return self::SUCCESS;
    }
}
