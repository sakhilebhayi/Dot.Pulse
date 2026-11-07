<?php

namespace App\Livewire\Pulse\Business\Support;

use Illuminate\Validation\ValidationException;

/** Puts a service's ValidationException on the matching field, so a refusal is said where the person is looking. */
trait HandlesServiceErrors
{
    /** @param array<string, string> $map service key => field name, for keys the form names differently */
    protected function fail(ValidationException $e, array $map = [], string $fallback = 'notice'): void
    {
        foreach ($e->errors() as $key => $messages) {
            $this->addError($map[$key] ?? $key, $messages[0]);
        }
    }

    protected function firstError(ValidationException $e): string
    {
        return (string) collect($e->errors())->flatten()->first();
    }
}
