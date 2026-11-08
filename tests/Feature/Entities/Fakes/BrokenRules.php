<?php

namespace Tests\Feature\Entities\Fakes;

use App\Models\User;

class BrokenRules
{
    public function assess(string $text, User $author, array $ctx = []): array
    {
        throw new \RuntimeException('rules are down');
    }
}
