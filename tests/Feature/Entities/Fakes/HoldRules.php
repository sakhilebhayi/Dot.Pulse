<?php

namespace Tests\Feature\Entities\Fakes;

use App\Models\User;

class HoldRules
{
    public function assess(string $text, User $author, array $ctx = []): array
    {
        return ['verdict' => 'hold', 'flags' => ['link_density']];
    }
}
