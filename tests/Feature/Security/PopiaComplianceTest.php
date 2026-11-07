<?php

namespace Tests\Feature\Security;

use App\Livewire\Pulse\Me\Discoverability;
use App\Livewire\Pulse\Me\Onboarding;
use App\Livewire\Pulse\Me\Privacy;
use App\Models\Community;
use App\Models\PulseComment;
use App\Models\PulseConversation;
use App\Models\PulseMedia;
use App\Models\PulseMessage;
use App\Models\PulsePost;
use App\Models\PulseProfile;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Discovery\Digest\DigestBuilder;
use App\Services\Pulse\Entities\MediaService;
use App\Services\Pulse\Entities\SignalIndexer;
use App\Services\Pulse\Lines\ConsentLedger;
use App\Services\Pulse\Lines\ContactGate;
use App\Services\Pulse\Lines\LineException;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\PrivacyEraser;
use App\Services\Pulse\Lines\PrivacyExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/** POPIA as behaviour: the age gate, consent that really withdraws, a complete export, an erasure that holds. */
class PopiaComplianceTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->buildWorld();
    }

    // ------------------------------------------------------------------ age gate

    public function test_an_unconfirmed_account_cannot_become_discoverable_by_any_route(): void
    {
        $minor = $this->w('minor');
        PulseProfile::query()->where('user_id', $minor->id)->update(['headline' => 'Bookkeeper', 'skills' => json_encode(['bookkeeping'])]);

        $component = Livewire::actingAs($minor)->test(Discoverability::class)->set('mode', 'open');
        $this->assertSame('off', PulseProfile::query()->where('user_id', $minor->id)->value('discoverability'));
        $this->assertNotNull($component->get('problem'));

        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(\App\Services\Pulse\Entities\ProfileService::class)->setDiscoverability($minor, 'anonymous');
    }

    public function test_finishing_onboarding_without_the_age_declaration_is_refused_even_when_the_steps_are_forged(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        PulseProfile::query()->create(['user_id' => $user->id, 'role' => 'customer']);

        Livewire::actingAs($user)->test(Onboarding::class)->set('adult', false)->call('finish');

        $profile = PulseProfile::query()->where('user_id', $user->id)->first();
        $this->assertNull($profile->age_confirmed_at);
        $this->assertNull($profile->onboarded_at);
    }

    public function test_nobody_without_the_declaration_is_discoverable_knockable_or_visible_as_a_signal(): void
    {
        $minor = $this->w('minor');
        DB::table('pulse_profiles')->where('user_id', $minor->id)->update(['discoverability' => 'open', 'headline' => 'Minor headline', 'skills' => json_encode(['bookkeeping'])]);

        // Indexing refuses the profile.
        app(SignalIndexer::class)->syncSubject(PulseProfile::query()->where('user_id', $minor->id)->first());
        $this->assertSame(0, PulseSignal::query()->where('owner_id', $minor->id)->where('kind', 'talent')->where('status', 'live')->count());

        // Nobody can start contact with them, and they cannot start contact with anybody.
        $gate = app(ContactGate::class);
        $this->assertFalse($gate->can($this->w('stranger'), $minor)['allowed']);
        $this->assertSame('age', $gate->can($minor, $this->w('seller'))['code']);

        $this->expectException(LineException::class);
        app(LineService::class)->knock($minor, ['entity' => $this->w('seller')->id], 'enquiry', $this->card());
    }

    // ------------------------------------------------------------------ consent

    public function test_withdrawing_consent_changes_what_the_platform_does(): void
    {
        $pro = $this->w('pro');
        $ledger = app(ConsentLedger::class);
        $keys = collect($ledger->entries($pro))->pluck('key');

        $this->assertTrue($keys->contains('discoverability.off'));
        $ledger->withdraw($pro, 'discoverability.off');

        $this->assertSame('off', PulseProfile::query()->where('user_id', $pro->id)->value('discoverability'));
        $this->assertSame('paused', $this->w('talentSignal')->fresh()->status);
        $this->assertNull(collect(app(\App\Services\Pulse\Discovery\Floor::class)->round($this->w('employerOwner'), 'work')['beats'])->first(fn ($b) => $b->kind === 'talent'));
        $this->actingAs($this->w('employerOwner'))->get('/s/'.$this->w('talentSignal')->id)->assertNotFound();

        // The contact policy.
        $stranger = $this->w('stranger');
        $mine = $this->w('needOwner');
        DB::table('pulse_profiles')->where('user_id', $mine->id)->update(['contact_policy' => 'anyone']);
        $this->assertTrue(app(ContactGate::class)->can($stranger, $mine->fresh())['allowed']);
        $ledger->withdraw($mine, 'contact_policy.nobody');
        $this->assertFalse(app(ContactGate::class)->can($stranger, $mine->fresh())['allowed']);

        // The digest.
        app(\App\Services\Pulse\Lines\NotificationPrefs::class)->update($mine, ['digest' => true]);
        $ledger->withdraw($mine, 'digest.off');
        $this->assertFalse((bool) \App\Models\PulseNotificationPref::query()->where('user_id', $mine->id)->value('digest'));
    }

    public function test_a_consent_row_belonging_to_someone_else_cannot_be_withdrawn(): void
    {
        $theirs = $this->w('line'); // the stranger's line with the seller page

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(ConsentLedger::class)->withdraw($this->w('needOwner'), 'line.close:'.$theirs->id);
    }

    public function test_the_digest_unsubscribe_link_works_without_signing_in_and_only_for_that_person(): void
    {
        $a = $this->w('stranger');
        $b = $this->w('needOwner');
        foreach ([$a, $b] as $u) {
            app(\App\Services\Pulse\Lines\NotificationPrefs::class)->update($u, ['digest' => true]);
        }

        $url = \Illuminate\Support\Facades\URL::signedRoute('pulse.digest.unsubscribe', ['user' => $a->id]);
        $this->get($url)->assertOk();

        $this->assertFalse((bool) \App\Models\PulseNotificationPref::query()->where('user_id', $a->id)->value('digest'));
        $this->assertTrue((bool) \App\Models\PulseNotificationPref::query()->where('user_id', $b->id)->value('digest'));

        // The signature of one person cannot be pointed at another, and an unsigned or altered link does nothing.
        $forged = str_replace('/'.$a->id.'?', '/'.$b->id.'?', $url);
        $this->get($forged)->assertForbidden();
        $this->get(route('pulse.digest.unsubscribe', ['user' => $b->id]))->assertForbidden();
        $this->post($forged)->assertForbidden();
        $this->assertTrue((bool) \App\Models\PulseNotificationPref::query()->where('user_id', $b->id)->value('digest'));
    }

    // ------------------------------------------------------------------ export

    public function test_the_export_holds_what_pulse_holds_about_the_person_and_nothing_private_about_others(): void
    {
        $me = $this->w('stranger');
        $other = $this->w('sellerOwner');
        $other->forceFill(['email' => 'seller.private@example.test'])->save();

        $community = Community::factory()->create();
        PulsePost::factory()->create(['user_id' => $me->id, 'community_id' => $community->id, 'title' => 'My distinctive post title', 'body' => 'My distinctive post body']);
        $post = PulsePost::factory()->create(['user_id' => $other->id, 'community_id' => $community->id]);
        PulseComment::factory()->create(['pulse_post_id' => $post->id, 'user_id' => $me->id, 'body' => 'My distinctive comment']);
        PulseSave::query()->create(['user_id' => $me->id, 'saveable_type' => 'signal', 'saveable_id' => $this->w('talentSignal')->id]);

        $json = json_encode(PrivacyExport::forUser($me), JSON_UNESCAPED_UNICODE);

        foreach (['My distinctive post title', 'My distinctive comment', 'leaking geyser'] as $mine) {
            $this->assertStringContainsString($mine, $json, "the export lacks: {$mine}");
        }
        foreach (['seller.private@example.test', $other->email, $this->w('pro')->email, 'Thandiwe'] as $theirs) {
            $this->assertStringNotContainsString($theirs, $json, "the export holds someone else's: {$theirs}");
        }
    }

    public function test_a_moderators_own_export_never_carries_the_lines_they_reviewed(): void
    {
        $mod = $this->w('moderator');
        \App\Models\PulseReport::query()->create(['reporter_id' => $this->w('stranger')->id, 'reportable_type' => PulseConversation::class, 'reportable_id' => $this->w('line')->id, 'reason' => 'spam', 'status' => 'open']);

        $json = json_encode(PrivacyExport::forUser($mod));

        $this->assertStringNotContainsString('Fix a leaking geyser', $json);
        $this->assertSame([], PrivacyExport::forUser($mod)['lines']);
    }

    public function test_the_export_route_is_for_the_signed_in_person_only(): void
    {
        $this->get('/me/privacy/export')->assertRedirect(route('login'));
        $this->actingAs($this->w('stranger'))->get('/me/privacy/export')->assertOk();
    }

    // ------------------------------------------------------------------ erasure

    public function test_erasure_removes_the_person_from_every_surface_and_deletes_their_files(): void
    {
        $me = $this->w('stranger');
        $lines = app(LineService::class);
        $line = $this->w('line');

        // A photo in a message, an avatar, a post, a comment, a note on a line.
        $messageMedia = app(MediaService::class)->store($me, UploadedFile::fake()->image('a.png', 64, 64), 'message', null, $line->id);
        $avatarMedia = app(MediaService::class)->store($me, UploadedFile::fake()->image('b.png', 64, 64), 'avatar');
        PulseProfile::query()->where('user_id', $me->id)->update(['avatar_media_id' => $avatarMedia, 'headline' => 'Plumber looking', 'bio' => 'My private bio']);
        $lines->send($me, $line->fresh(), 'My private words about my address 12 Vilakazi Street');
        $post = PulsePost::factory()->create(['user_id' => $me->id, 'title' => 'My erased post title', 'body' => 'My erased post body']);
        PulseComment::factory()->create(['pulse_post_id' => $post->id, 'user_id' => $me->id, 'body' => 'My erased comment']);
        PulseSave::query()->create(['user_id' => $me->id, 'saveable_type' => 'signal', 'saveable_id' => $this->w('talentSignal')->id]);
        \App\Models\PulseMatch::query()->create(['user_id' => $me->id, 'signal_id' => $this->w('talentSignal')->id, 'score' => 10, 'reasons' => [], 'computed_at' => now()]);
        $paths = PulseMedia::query()->whereIn('id', [$messageMedia, $avatarMedia])->get()->flatMap(fn ($m) => collect($m->variants)->pluck('path'))->all();
        $this->assertNotEmpty($paths);
        foreach ($paths as $p) {
            Storage::disk('local')->assertExists($p);
        }

        PrivacyEraser::erase($me);

        foreach ($paths as $p) {
            Storage::disk('local')->assertMissing($p);
        }
        $this->assertSame(0, PulseMedia::query()->where('user_id', $me->id)->count());
        $this->assertSame(0, PulseMessage::query()->where('user_id', $me->id)->where('body', 'like', '%Vilakazi%')->count());
        $this->assertNull(PulseMessage::query()->where('user_id', $me->id)->whereNotNull('media_id')->value('media_id'));
        $this->assertSame(0, PulseMessage::query()->where('user_id', $me->id)->whereNull('erased_at')->count());
        $this->assertNull(PulseConversation::query()->find($line->id)->card, 'the enquiry card the person wrote is gone from the line');
        $this->assertSame(0, PulseSave::query()->where('user_id', $me->id)->count());
        $this->assertSame(0, DB::table('pulse_matches')->where('user_id', $me->id)->count());
        $this->assertNull(PulseProfile::query()->where('user_id', $me->id)->value('bio'));
        $this->assertNull(PulseProfile::query()->where('user_id', $me->id)->value('avatar_media_id'));
        $this->assertSame(0, DB::table('pulse_audit_log')->where('actor_id', $me->id)->count());

        // Legacy community content is theirs too.
        $this->assertNull(PulsePost::query()->find($post->id), 'the post is gone');
        $this->assertSame(0, PulseComment::query()->where('user_id', $me->id)->whereNull('deleted_at')->count());
        $this->assertStringNotContainsString('My erased', json_encode(DB::table('pulse_posts')->get()));
        $this->assertStringNotContainsString('My erased', json_encode(DB::table('pulse_comments')->get()));

        // The other side still has its thread, with the person's words replaced and no name in notifications about them.
        $notes = DB::table('notifications')->whereIn('notifiable_id', [$this->w('sellerOwner')->id, $this->w('sellerResponder')->id, $this->w('sellerAdmin')->id])->pluck('data')->implode("\n");
        $this->assertStringNotContainsString($me->name, $notes);
    }

    public function test_an_erased_person_stays_erased_after_signing_in_again(): void
    {
        $me = $this->w('pro');
        PrivacyEraser::erase($me);

        // The sign in sync touches the account, never the profile content or findability.
        $this->actingAs($me->fresh())->get('/floor');
        $this->actingAs($me->fresh())->get('/me/privacy')->assertOk();

        $profile = PulseProfile::query()->where('user_id', $me->id)->first();
        $this->assertNull($profile->headline);
        $this->assertSame('off', $profile->discoverability);
        $this->assertSame('nobody', $profile->contact_policy);
        $this->assertSame(0, PulseSignal::query()->where('owner_id', $me->id)->where('status', 'live')->count());

        // And nothing can switch them back on without the age declaration the erasure left in place being used on purpose.
        app(SignalIndexer::class)->rebuild();
        $this->assertSame(0, PulseSignal::query()->where('owner_id', $me->id)->where('status', 'live')->count());
    }

    public function test_the_erase_form_needs_its_confirmation_phrase(): void
    {
        $me = $this->w('stranger');
        PulseProfile::query()->where('user_id', $me->id)->update(['bio' => 'Keep me']);

        Livewire::actingAs($me)->test(Privacy::class)->set('confirmation', 'yes')->call('erase');
        $this->assertSame('Keep me', PulseProfile::query()->where('user_id', $me->id)->value('bio'));
    }
}
