<?php

namespace Tests\Feature\Entities;

use App\Models\PulseAuditEntry;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\EntityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class EntityServiceTest extends EntitiesTestCase
{
    private function service(): EntityService
    {
        return app(EntityService::class);
    }

    public function test_create_makes_the_creator_the_owner_and_audits(): void
    {
        $owner = $this->person();

        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'tagline' => 'Books for small business', 'is_seller' => true]);

        $this->assertSame('karoo-books', $entity->slug);
        $this->assertSame('live', $entity->status);
        $this->assertSame('owner', $entity->memberRole($owner));
        $this->assertSame('unverified', $entity->verification_status);
        $this->assertDatabaseHas('pulse_audit_log', ['actor_id' => $owner->id, 'action' => 'entity.create']);
    }

    public function test_slugs_stay_unique_and_never_collide_with_routes(): void
    {
        $a = $this->service()->create($this->person(), ['name' => 'Same Name', 'is_seller' => true]);
        $b = $this->service()->create($this->person(), ['name' => 'Same Name', 'is_seller' => true]);
        $c = $this->service()->create($this->person(), ['name' => 'New', 'is_seller' => true]);

        $this->assertSame('same-name', $a->slug);
        $this->assertSame('same-name-2', $b->slug);
        $this->assertNotSame('new', $c->slug);
    }

    public function test_a_trashed_pages_slug_is_not_reused(): void
    {
        $a = $this->service()->create($this->person(), ['name' => 'Gone Soon', 'is_seller' => true]);
        $a->delete();

        $b = $this->service()->create($this->person(), ['name' => 'Gone Soon', 'is_seller' => true]);

        $this->assertSame('gone-soon-2', $b->slug);
    }

    public function test_it_needs_a_name_and_a_reason_to_exist(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->create($this->person(), ['name' => 'X']);
    }

    public function test_a_page_sells_or_hires(): void
    {
        $this->expectException(ValidationException::class);
        $this->service()->create($this->person(), ['name' => 'Nothing Ltd', 'is_seller' => false, 'is_employer' => false]);
    }

    public function test_text_is_sanitised(): void
    {
        $entity = $this->service()->create($this->person(), [
            'name' => "  <b>Karoo</b>\n   Books  ",
            'about' => "<script>alert(1)</script>Hello\r\n\r\n\r\n\r\nWorld",
            'is_seller' => true,
        ]);

        $this->assertSame('Karoo Books', $entity->name);
        $this->assertStringNotContainsString('<', $entity->about);
        $this->assertSame("alert(1)Hello\n\nWorld", $entity->about);
    }

    public function test_the_per_user_page_quota_is_enforced(): void
    {
        config(['pulse.entities.max_per_user' => 2]);
        $owner = $this->person();

        $this->service()->create($owner, ['name' => 'One', 'is_seller' => true]);
        $this->service()->create($owner, ['name' => 'Two', 'is_seller' => true]);

        $this->expectException(ValidationException::class);
        $this->service()->create($owner, ['name' => 'Three', 'is_seller' => true]);
    }

    public function test_default_quota_is_five(): void
    {
        $this->assertSame(5, config('pulse.entities.max_per_user'));
        $this->assertSame(100, config('pulse.entities.max_offers'));
        $this->assertSame(50, config('pulse.entities.max_roles'));
        $this->assertSame(10, config('pulse.entities.max_open_needs'));
    }

    public function test_moderation_state_follows_who_wrote_it(): void
    {
        $stranger = $this->service()->create($this->person(), ['name' => 'Stranger Co', 'is_seller' => true]);
        $infodot = $this->service()->create($this->infodotPerson(), ['name' => 'Linked Co', 'is_seller' => true]);

        $this->assertSame('pending', $stranger->moderation);
        $this->assertSame('approved', $infodot->moderation);
    }

    public function test_update_by_owner_and_admin_but_not_editor_responder_or_stranger(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);
        $admin = $this->member($entity, 'admin');
        $editor = $this->member($entity, 'editor');
        $responder = $this->member($entity, 'responder');

        $this->assertSame('First', $this->service()->update($owner, $entity, ['tagline' => 'First'])->tagline);
        $this->assertSame('Second', $this->service()->update($admin, $entity, ['tagline' => 'Second'])->tagline);

        foreach ([$editor, $responder, $this->person()] as $nobody) {
            try {
                $this->service()->update($nobody, $entity, ['tagline' => 'Hacked']);
                $this->fail('expected an authorisation failure');
            } catch (AuthorizationException) {
                $this->assertSame('Second', $entity->fresh()->tagline);
            }
        }
    }

    public function test_the_slug_does_not_change_when_the_name_does(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Old Name', 'is_seller' => true]);

        $updated = $this->service()->update($owner, $entity, ['name' => 'Brand New Name']);

        $this->assertSame('old-name', $updated->slug);
    }

    public function test_changing_public_text_sends_it_back_through_moderation(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->service()->create($owner, ['name' => 'Linked Co', 'is_seller' => true]);
        $this->assertSame('approved', $entity->moderation);

        $this->rules(Fakes\HoldRules::class);
        $updated = $this->service()->update($owner, $entity, ['about' => 'Click here for amazing deals']);

        $this->assertSame('held', $updated->moderation);
        $this->assertSame('held', PulseSignal::where('subject_type', 'entity')->value('status'));
    }

    public function test_blocked_text_is_refused(): void
    {
        $this->rules(Fakes\BlockRules::class);

        $this->expectException(ValidationException::class);
        $this->service()->create($this->person(), ['name' => 'Bad Co', 'is_seller' => true]);
    }

    public function test_website_and_phone_are_recorded_as_claims(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true, 'website' => 'https://karoo.example', 'public_phone' => '+27 11 555 0100']);

        $this->assertSame('https://karoo.example', $entity->claims['website']['value']);
        $this->assertNotEmpty($entity->claims['website']['at']);
        $this->assertSame('claimed', $entity->fresh()->verification_status);
    }

    public function test_hiring_signal_needs_an_employer_page(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Seller Only', 'is_seller' => true]);

        $this->expectException(ValidationException::class);
        $this->service()->update($owner, $entity, ['hiring_signal' => 'active']);
    }

    // ---- members ----------------------------------------------------------------------------------------------

    public function test_owner_adds_and_changes_members(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);
        $newcomer = $this->person();

        $this->service()->addMember($owner, $entity, $newcomer, 'editor');
        $this->assertSame('editor', $entity->memberRole($newcomer));

        $this->service()->addMember($owner, $entity, $newcomer, 'admin');
        $this->assertSame('admin', $entity->memberRole($newcomer));
        $this->assertSame(1, PulseEntityMember::where('entity_id', $entity->id)->where('user_id', $newcomer->id)->count());
    }

    public function test_an_admin_cannot_create_or_touch_owners_and_admins(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);
        $admin = $this->member($entity, 'admin');
        $other = $this->person();

        $this->service()->addMember($admin, $entity, $other, 'responder');
        $this->assertSame('responder', $entity->memberRole($other));

        foreach (['owner', 'admin'] as $role) {
            try {
                $this->service()->addMember($admin, $entity, $other, $role);
                $this->fail('an admin must not grant '.$role);
            } catch (AuthorizationException) {
                $this->assertSame('responder', $entity->memberRole($other));
            }
        }

        $this->expectException(AuthorizationException::class);
        $this->service()->removeMember($admin, $entity, $owner);
    }

    public function test_an_editor_a_responder_and_a_stranger_cannot_manage_members(): void
    {
        $entity = $this->page();
        $target = $this->person();

        foreach ([$this->member($entity, 'editor'), $this->member($entity, 'responder'), $this->person()] as $nobody) {
            try {
                $this->service()->addMember($nobody, $entity, $target, 'responder');
                $this->fail('expected an authorisation failure');
            } catch (AuthorizationException) {
                $this->assertNull($entity->memberRole($target));
            }
        }
    }

    public function test_the_last_owner_cannot_be_removed_or_demoted_or_leave(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);

        foreach ([
            fn () => $this->service()->removeMember($owner, $entity, $owner),
            fn () => $this->service()->addMember($owner, $entity, $owner, 'admin'),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('the last owner must stay');
            } catch (ValidationException) {
                $this->assertSame('owner', $entity->memberRole($owner));
            }
        }
    }

    public function test_with_two_owners_one_may_leave(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);
        $second = $this->person();
        $this->service()->addMember($owner, $entity, $second, 'owner');

        $this->service()->removeMember($owner, $entity, $owner);

        $this->assertNull($entity->memberRole($owner));
        $this->assertSame('owner', $entity->memberRole($second));
    }

    public function test_a_member_can_leave_but_cannot_remove_someone_else(): void
    {
        $entity = $this->page();
        $editor = $this->member($entity, 'editor');
        $responder = $this->member($entity, 'responder');

        try {
            $this->service()->removeMember($editor, $entity, $responder);
            $this->fail('expected an authorisation failure');
        } catch (AuthorizationException) {
        }

        $this->service()->removeMember($editor, $entity, $editor);
        $this->assertNull($entity->memberRole($editor));
    }

    // ---- followers, archive, stats ------------------------------------------------------------------------------

    public function test_follow_and_unfollow_keep_the_count_true(): void
    {
        $entity = $this->page();
        $a = $this->person();
        $b = $this->person();

        $this->service()->follow($a, $entity);
        $this->service()->follow($a, $entity);
        $this->service()->follow($b, $entity);
        $this->assertSame(2, $entity->fresh()->followers_count);

        $this->service()->unfollow($a, $entity);
        $this->assertSame(1, $entity->fresh()->followers_count);
    }

    public function test_a_page_that_is_not_visible_cannot_be_followed(): void
    {
        $entity = $this->page(null, ['moderation' => 'pending']);

        $this->expectException(AuthorizationException::class);
        $this->service()->follow($this->person(), $entity);
    }

    public function test_archive_retires_every_signal_and_restore_brings_them_back(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);
        $entity->forceFill(['moderation' => 'approved'])->save();
        $offer = \App\Models\PulseOffer::factory()->create(['entity_id' => $entity->id, 'created_by' => $owner->id]);

        $this->assertSame('live', PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->value('status'));

        $this->service()->archive($owner, $entity);
        $this->assertNotContains('live', PulseSignal::pluck('status')->all());
        $this->assertNull(PulseEntity::live()->find($entity->id));

        $this->service()->restore($owner, $entity->fresh());
        $this->assertSame('live', PulseSignal::where('subject_type', 'offer')->where('subject_id', $offer->id)->value('status'));
    }

    public function test_only_an_owner_can_archive(): void
    {
        $entity = $this->page();
        $this->expectException(AuthorizationException::class);
        $this->service()->archive($this->member($entity, 'admin'), $entity);
    }

    public function test_stats_are_real_counts_and_null_when_nothing_happened(): void
    {
        $entity = $this->page();
        \App\Models\PulseOffer::factory()->count(2)->create(['entity_id' => $entity->id]);
        \App\Models\PulseOffer::factory()->paused()->create(['entity_id' => $entity->id]);
        \App\Models\PulseRole::factory()->create(['entity_id' => $entity->id]);

        $stats = $this->service()->stats($entity->fresh());

        $this->assertSame(2, $stats['offers']);
        $this->assertSame(1, $stats['roles']);
        $this->assertSame(0, $stats['confirmed_outcomes']);
        $this->assertNull($stats['response_rate']);
        $this->assertNull($stats['median_response_seconds']);
    }

    public function test_stats_read_conversations_when_they_exist(): void
    {
        $entity = $this->page();
        $initiator = $this->person();
        $now = now()->startOfSecond();

        foreach ([[60, true], [180, true], [null, false], [120, true]] as $i => [$seconds, $answered]) {
            \Illuminate\Support\Facades\DB::table('pulse_conversations')->insert([
                'type' => 'direct', 'created_at' => $now, 'updated_at' => $now,
                'kind' => 'enquiry', 'status' => 'open', 'initiator_id' => $initiator->id, 'entity_id' => $entity->id,
                'first_response_at' => $answered ? $now->copy()->addSeconds($seconds) : null,
                'outcome' => $i === 0 ? 'won' : 'none',
                'outcome_initiator_at' => $i === 0 ? $now : null, 'outcome_recipient_at' => $i === 0 ? $now : null,
            ]);
        }

        $stats = $this->service()->stats($entity);

        $this->assertSame(4, $stats['conversations']);
        $this->assertSame(0.75, $stats['response_rate']);
        $this->assertSame(120, $stats['median_response_seconds']);
        $this->assertSame(1, $stats['confirmed_outcomes']);
    }

    public function test_writes_are_audited(): void
    {
        $owner = $this->person();
        $entity = $this->service()->create($owner, ['name' => 'Karoo Books', 'is_seller' => true]);
        $this->service()->update($owner, $entity, ['tagline' => 'Hi']);
        $this->service()->addMember($owner, $entity, $this->person(), 'editor');

        $actions = PulseAuditEntry::pluck('action')->all();
        $this->assertContains('entity.update', $actions);
        $this->assertContains('entity.member.add', $actions);
    }
}
