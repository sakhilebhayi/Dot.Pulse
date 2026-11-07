<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\PulseDemoSeeder;
use Illuminate\Console\Command;

class PulseDemoSeed extends Command
{
    protected $signature = 'pulse:demo-seed {--url=http://127.0.0.1:8000 : Base address used in the login helper table}';

    protected $description = 'Create the fictional Dot.Pulse demo world (people, pages, offers, roles, needs, doors, lines). Refuses to run in production. Idempotent.';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('The demo world is never created in production (APP_ENV=production). Nothing was done.');

            return self::FAILURE;
        }

        $seeder = app(PulseDemoSeeder::class);
        $seeder->setCommand($this);
        $seeder->run();

        $this->newLine();
        $this->info('Sign in as anyone through the local-only dev route (registered under local and testing only):');

        $rows = [];
        foreach (PulseDemoSeeder::cast() as $key => $who) {
            $user = User::where('email', PulseDemoSeeder::email($key))->first();
            $rows[] = [$who['name'], $who['role'], rtrim((string) $this->option('url'), '/').'/dev/login/'.$user?->id];
        }
        $this->table(['Person', 'Who', 'Login'], $rows);

        return self::SUCCESS;
    }
}
