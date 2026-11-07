<?php

namespace Tests\Feature\Entities;

use App\Models\PulseNeed;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\NeedService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class NeedServiceTest extends EntitiesTestCase
{
    private function service(): NeedService
    {
        return app(NeedService::class);
    }

    private function data(array $over = []): array
    {
        return array_merge([
            'title' => 'Looking for a bookkeeper in Gauteng',
            'details' => 'A small shop with about 80 invoices a month and VAT to file.',
            'tags' => ['bookkeeping', 'vat'],
            'budget_text' => 'Up to R3 000 a month',
        ], $over);
    }

    private function signal(PulseNeed $need): ?PulseSignal
    {
        return PulseSignal::where('subject_type', 'need')->where('subject_id', $need->id)->first();
    }

    public function test_a_need_goes_live_with_an_expiry(): void
    {
        $this->freezeTime();
        $user = $this->infodotPerson();

        $need = $this->service()->create($user, $this->data());

        $this->assertSame('open', $need->status);
        $this->assertSame('approved', $need->moderation);
        $this->assertSame(now()->addDays(30)->timestamp, $need->expires_at->timestamp);
        $this->assertSame('need', $this->signal($need)->kind);
        $this->assertSame('live', $this->signal($need)->status);
        $this->assertSame(['bookkeeping', 'vat'], $this->signal($need)->topics);
    }

    public function test_kinds_map_to_signal_kinds(): void
    {
        $user = $this->infodotPerson();
        $expected = ['need' => 'need', 'supplier' => 'need', 'collab' => 'collab', 'investor' => 'collab', 'mentor' => 'ask', 'intro' => 'ask'];

        foreach ($expected as $kind => $signalKind) {
            $need = $this->service()->create($user, $this->data(['kind' => $kind, 'title' => "Looking for $kind"]));
            $this->assertSame($signalKind, $this->signal($need)->kind, $kind);
            $this->service()->close($user, $need);
        }
    }

    public function test_close_reopen_and_the_signal(): void
    {
        $user = $this->infodotPerson();
        $need = $this->service()->create($user, $this->data());

        $this->service()->close($user, $need, true);
        $this->assertSame('fulfilled', $need->fresh()->status);
        $this->assertSame('expired', $this->signal($need)->status);

        $this->service()->reopen($user, $need->fresh());
        $this->assertSame('open', $need->fresh()->status);
        $this->assertSame('live', $this->signal($need)->status);
    }

    public function test_a_lapsed_need_is_expired_in_the_index(): void
    {
        $user = $this->infodotPerson();
        $need = $this->service()->create($user, $this->data());

        $this->travel(31)->days();
        app(\App\Services\Pulse\Entities\SignalIndexer::class)->syncSubject($need->fresh());

        $this->assertSame('expired', $this->signal($need)->status);
    }

    public function test_the_open_needs_quota(): void
    {
        config(['pulse.entities.max_open_needs' => 2]);
        $user = $this->person();

        $a = $this->service()->create($user, $this->data(['title' => 'Need number one']));
        $this->service()->create($user, $this->data(['title' => 'Need number two']));

        try {
            $this->service()->create($user, $this->data(['title' => 'Need number three']));
            $this->fail('quota');
        } catch (ValidationException) {
        }

        $this->service()->close($user, $a);
        $this->service()->create($user, $this->data(['title' => 'Need number three']));

        try {
            $this->service()->reopen($user, $a->fresh());
            $this->fail('quota on reopen');
        } catch (ValidationException) {
            $this->assertSame('closed', $a->fresh()->status);
        }
    }

    public function test_without_rules_a_strangers_need_is_held_until_approved(): void
    {
        $user = $this->person();
        $need = $this->service()->create($user, $this->data());

        $this->assertSame('pending', $need->moderation);
        $this->assertSame('held', $this->signal($need)->status);
    }

    public function test_only_the_owner_changes_a_need(): void
    {
        $owner = $this->person();
        $need = $this->service()->create($owner, $this->data());

        foreach ([$this->person(), $this->moderator()] as $other) {
            foreach (['close', 'reopen', 'delete'] as $method) {
                try {
                    $this->service()->$method($other, $need);
                    $this->fail("$method must be refused");
                } catch (AuthorizationException) {
                }
            }

            try {
                $this->service()->update($other, $need, ['title' => 'Hacked title']);
                $this->fail('update must be refused');
            } catch (AuthorizationException) {
            }
        }

        $this->assertSame('Updated title', $this->service()->update($owner, $need, ['title' => 'Updated title'])->title);
        $this->assertSame('Updated title', $this->signal($need)->title);
    }

    public function test_a_need_for_a_page_requires_membership(): void
    {
        $page = $this->page();
        $member = $this->member($page, 'editor');

        $this->assertSame($page->id, $this->service()->create($member, $this->data(['entity_id' => $page->id]))->entity_id);

        $this->expectException(ValidationException::class);
        $this->service()->create($this->person(), $this->data(['entity_id' => $page->id]));
    }

    public function test_delete_retires_the_signal(): void
    {
        $user = $this->person();
        $need = $this->service()->create($user, $this->data());

        $this->service()->delete($user, $need);

        $this->assertSame('removed', $this->signal($need)->status);
    }

    public function test_reach_is_normalised_on_the_signal(): void
    {
        $user = $this->infodotPerson();
        $need = $this->service()->create($user, $this->data(['reach' => 'country']));

        $this->assertSame('national', $this->signal($need)->reach);
    }
}
