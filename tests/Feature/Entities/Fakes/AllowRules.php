<?php

namespace Tests\Feature\Entities\Fakes;

use App\Models\User;

class AllowRules
{
    public function assess(string $text, User $author, array $ctx = []): array
    {
        return ['verdict' => 'allow', 'flags' => []];
    }
}
