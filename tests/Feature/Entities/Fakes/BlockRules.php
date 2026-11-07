<?php

namespace Tests\Feature\Entities\Fakes;

use App\Models\User;

class BlockRules
{
    public function assess(string $text, User $author, array $ctx = []): array
    {
        return ['verdict' => 'block', 'flags' => ['blocked_term']];
    }
}
