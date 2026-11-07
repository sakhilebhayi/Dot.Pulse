<?php

namespace App\Console\Commands;

use App\Services\Pulse\Lines\SweepLines;
use Illuminate\Console\Command;

class PulseLinesSweep extends Command
{
    protected $signature = 'pulse:lines:sweep';

    protected $description = 'Expire stale quotes, close unanswered requests (telling the sender), lapse open doors, reveal reviews after the blind period';

    public function handle(SweepLines $sweep): int
    {
        $done = $sweep->run();

        $this->info(sprintf(
            'quotes expired: %d, requests closed as expired: %d, doors lapsed: %d, reviews revealed: %d',
            $done['quotes'], $done['requests'], $done['doors'], $done['reviews'],
        ));

        return self::SUCCESS;
    }
}
