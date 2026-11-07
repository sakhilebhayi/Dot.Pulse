<?php

namespace Tests\Feature\Discovery;

use App\Models\PulseMatch;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulsePost;
use App\Models\PulseRole;
use App\Models\PulseSignal;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DiscoveryFixtures;
use Tests\TestCase;

class CommandsAndScheduleTest extends TestCase
{
    use DiscoveryFixtures;
    use RefreshDatabase;

    public function test_the_four_commands_are_scheduled_and_none_can_overlap(): void
    {
        $events = collect(app(Schedule::class)->events());

        foreach (['pulse:index' => '0 * * * *', 'pulse:match' => '*/10 * * * *', 'pulse:digest' => '0 * * * *', 'pulse:expire' => '0 * * * *'] as $command => $expression) {
            $event = $events->first(fn ($e) => str_contains((string) $e->command, $command));

            $this->assertNotNull($event, "{$command} is scheduled");
            $this->assertSame($expression, $event->expression, "{$command} cadence");
            $this->assertTrue($event->withoutOverlapping, "{$command} must not overlap itself: the host has one shared crontab");
        }

        // Existing schedule entries are still there.
        $this->assertNotNull($events->first(fn ($e) => str_contains((string) $e->command, 'pulse:sweep-pending')));
        $this->assertNotNull($events->first(fn ($e) => str_contains((string) $e->command, 'pulse:publish-metrics')));
    }

    public function test_every_command_is_registered_with_artisan(): void
    {
        $all = array_keys(\Illuminate\Support\Facades\Artisan::all());

        foreach (['pulse:index', 'pulse:match', 'pulse:digest', 'pulse:expire', 'pulse:places:seed'] as $name) {
            $this->assertContains($name, $all);
        }
    }

    public function test_expire_closes_what_ran_out_and_leaves_the_rest(): void
    {
        $owner = $this->dUser();
        $entity = $this->dEntity($owner);

        $liveSignal = $this->dSignal(['owner_id' => $owner->id, 'expires_at' => now()->addDay()]);
        $doorSignal = $this->dSignal(['owner_id' => $owner->id, 'kind' => 'collab', 'expires_at' => now()->subMinute()]);
        $hiringSignal = $this->dSignal(['entity_id' => $entity->id, 'kind' => 'business', 'expires_at' => now()->subHour()]);
        $forever = $this->dSignal(['owner_id' => $owner->id]);

        $need = $this->dNeed($owner, ['expires_at' => now()->subMinute()]);
        $freshNeed = $this->dNeed($owner, ['expires_at' => now()->addWeek()]);
        $role = $this->dRole($entity, ['closes_at' => now()->subMinute()]);
        $openRole = $this->dRole($entity, ['closes_at' => now()->addWeek()]);
        $offer = $this->dOffer($entity, ['expires_at' => now()->subMinute()]);
        $liveOffer = $this->dOffer($entity);

        $this->artisan('pulse:expire')->assertSuccessful();

        $this->assertSame('live', $liveSignal->fresh()->status);
        $this->assertSame('expired', $doorSignal->fresh()->status);
        $this->assertSame('expired', $hiringSignal->fresh()->status);
        $this->assertSame('live', $forever->fresh()->status);
        $this->assertSame('closed', $need->fresh()->status);
        $this->assertSame('open', $freshNeed->fresh()->status);
        $this->assertSame('closed', $role->fresh()->status);
        $this->assertSame('live', $openRole->fresh()->status);
        $this->assertSame('paused', $offer->fresh()->status, 'an offer is paused, not deleted');
        $this->assertSame('live', $liveOffer->fresh()->status);
        $this->assertNotNull(PulseOffer::find($offer->id));
    }

    public function test_expire_takes_the_signal_of_a_closed_role_with_it_and_tidies_old_matches(): void
    {
        $owner = $this->dUser();
        $entity = $this->dEntity($owner);
        $role = $this->dRole($entity, ['status' => 'filled']);
        $signal = $this->dSignal(['kind' => 'role', 'subject_type' => 'role', 'subject_id' => $role->id, 'entity_id' => $entity->id]);

        $gone = $this->dSignal(['status' => 'removed']);
        PulseSignal::where('id', $gone->id)->update(['updated_at' => now()->subDays(40)]);
        $user = $this->dUser();
        $this->dMatch($user, $gone);
        $recentGone = $this->dSignal(['status' => 'removed']);
        $this->dMatch($user, $recentGone);

        $this->artisan('pulse:expire')->assertSuccessful();

        $this->assertSame('expired', $signal->fresh()->status);
        $this->assertNull(PulseMatch::where('signal_id', $gone->id)->first(), 'matches for a month-old removal are purged');
        $this->assertNotNull(PulseMatch::where('signal_id', $recentGone->id)->first());
    }

    public function test_match_command_recomputes_people_whose_inputs_changed_and_matches_new_signals(): void
    {
        $g = $this->dGeo();
        $buyer = $this->dUser(['place' => $g->jhb, 'interests' => ['plumbing']]);
        $this->dNeed($buyer, ['tags' => ['plumbing']]);
        $signal = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $this->dUser()->id, 'place' => $g->jhb]);

        $this->artisan('pulse:match')->assertSuccessful();

        $this->assertNotNull(PulseMatch::where(['user_id' => $buyer->id, 'signal_id' => $signal->id])->first());
    }

    public function test_match_command_for_one_user_and_for_all(): void
    {
        $g = $this->dGeo();
        $u = $this->dUser(['place' => $g->jhb, 'interests' => ['plumbing']]);
        $signal = $this->dSignal(['topics' => ['plumbing'], 'owner_id' => $this->dUser()->id]);

        $this->artisan('pulse:match', ['--user' => $u->id])->assertSuccessful();
        $this->assertSame(1, PulseMatch::count());

        PulseMatch::query()->delete();
        $this->artisan('pulse:match', ['--all' => true])->assertSuccessful();
        $this->assertSame(1, PulseMatch::where('user_id', $u->id)->count());

        $this->artisan('pulse:match', ['--user' => 999999])->assertFailed();
    }

    public function test_index_command_indexes_posts_even_without_the_entities_indexer(): void
    {
        $post = PulsePost::factory()->create(['title' => 'A tiling tip', 'body' => 'Always seal the grout lines after a week.']);
        $pending = PulsePost::factory()->status('pending')->create();

        $this->artisan('pulse:index')->assertSuccessful();

        $signal = PulseSignal::where('subject_type', 'post')->where('subject_id', $post->id)->first();
        $this->assertNotNull($signal);
        $this->assertSame('live', $signal->status);
        $this->assertNull(PulseSignal::where('subject_id', $pending->id)->first(), 'a pending post is not indexed');

        // Idempotent.
        $this->artisan('pulse:index')->assertSuccessful();
        $this->assertSame(1, PulseSignal::where('subject_id', $post->id)->count());
    }
}
