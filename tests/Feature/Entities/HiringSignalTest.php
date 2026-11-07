<?php

namespace Tests\Feature\Entities;

use App\Models\PulseSignal;
use App\Services\Pulse\Entities\EntityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;

class HiringSignalTest extends EntitiesTestCase
{
    private function signalSkills($entity): array
    {
        return (array) PulseSignal::where('subject_type', 'entity')->where('subject_id', $entity->id)->first()->skills;
    }

    public function test_a_hiring_signal_lapses_after_sixty_days_unless_renewed_and_the_owners_are_told(): void
    {
        $owner = $this->infodotPerson();
        $page = $this->page($owner, ['moderation' => 'approved']);
        $service = app(EntityService::class);

        $page = $service->update($owner, $page, ['hiring_signal' => 'active', 'hiring_skills' => ['Welding']]);
        $this->assertEqualsWithDelta(now()->addDays(60)->getTimestamp(), $page->hiring_signal_until->getTimestamp(), 5);
        $this->assertNotEmpty($this->signalSkills($page));

        $this->travel(59)->days();
        Artisan::call('pulse:expire');
        $this->assertSame('active', $page->fresh()->hiring_signal);

        // Renewing at day 59 buys another 60 days.
        $page = $service->renewHiringSignal($owner, $page->fresh());
        $this->travel(30)->days();
        Artisan::call('pulse:expire');
        $this->assertSame('active', $page->fresh()->hiring_signal, 'renewed, so day 89 is still inside the new period');

        $this->travel(31)->days();
        Artisan::call('pulse:expire');

        $fresh = $page->fresh();
        $this->assertSame('none', $fresh->hiring_signal);
        $this->assertNull($fresh->hiring_signal_until);
        $this->assertSame([], $this->signalSkills($fresh), 'the page is no longer findable by hiring skills');
        $this->assertSame(1, $owner->notifications()->where('data->type', 'hiring_signal_lapsed')->count());
    }

    public function test_switching_it_off_stops_the_clock_and_only_owners_and_admins_renew(): void
    {
        $owner = $this->infodotPerson();
        $page = $this->page($owner);
        $service = app(EntityService::class);

        $on = $service->update($owner, $page, ['hiring_signal' => 'building', 'hiring_skills' => ['Welding']]);
        $this->assertNotNull($on->hiring_signal_until);

        try {
            $service->renewHiringSignal($this->member($on, 'editor'), $on);
            $this->fail('an editor may not renew');
        } catch (AuthorizationException) {
        }

        $off = $service->update($owner, $on, ['hiring_signal' => 'none']);
        $this->assertNull($off->hiring_signal_until);

        $this->expectException(ValidationException::class);
        $service->renewHiringSignal($owner, $off);
    }
}
