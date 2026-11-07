<?php

namespace Tests\Feature\Security;

use App\Models\PulseMatch;
use App\Models\PulsePlace;
use App\Models\PulseProfile;
use App\Models\PulseSave;
use App\Models\PulseSignal;
use App\Models\User;
use App\Services\Pulse\Entities\SignalIndexer;
use App\Services\Pulse\Lines\AuditLog;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\PrivacyExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * An anonymous professional (discoverability = anonymous) is found by skills, experience band and region only. This
 * test builds a person with a distinctive name, email, suburb, photo, current employer and a headline that names
 * them, then walks every surface the would-be employer can reach and searches the whole response (HTML, the
 * data-wire-snapshot attributes Livewire sends to the browser, JSON, notifications, mail, exports, audit rows) for any
 * of it.
 */
class AnonymousIdentityLeakTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    private User $employer;

    private User $pro;

    private PulseSignal $signal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);

        $this->employer = $this->w('employerOwner');
        $this->pro = $this->w('pro');

        // The suburb and city the person lives in, and the real indexing path.
        $region = PulsePlace::query()->where('level', 'region')->first();
        $suburb = PulsePlace::query()->create(['level' => 'city', 'country_code' => 'ZA', 'region' => 'Gauteng', 'city' => self::SECRET_SUBURB, 'name' => self::SECRET_SUBURB, 'slug' => 'rosebank-ext-7']);

        $profile = PulseProfile::query()->where('user_id', $this->pro->id)->first();
        $profile->forceFill([
            'headline' => 'Thandiwe Zulu-Private, bookkeeper at '.self::SECRET_EMPLOYER,
            'skills' => ['Bookkeeping', 'Payroll', 'VAT'],
            'experience_years' => 7,
            'show_place' => true,
            'place_id' => $suburb->id,
            'discoverability' => 'anonymous',
            'moderation' => 'approved',
            'avatar_media_id' => null,
            'work_history' => [['title' => 'Bookkeeper', 'org' => self::SECRET_EMPLOYER, 'current' => true, 'hide_from_employer' => false]],
        ])->save();
        $this->pro->forceFill(['profile_photo_path' => 'profile-photos/thandiwe-zulu-private.jpg'])->save();

        PulseSignal::query()->where('owner_id', $this->pro->id)->delete();
        app(SignalIndexer::class)->syncSubject($profile->fresh());
        $this->signal = PulseSignal::query()->where('owner_id', $this->pro->id)->where('kind', 'talent')->firstOrFail();
        $this->assertTrue((bool) $this->signal->anonymous);

        PulseMatch::query()->create(['user_id' => $this->employer->id, 'signal_id' => $this->signal->id, 'score' => 80, 'reasons' => [['code' => 'skill_role', 'text' => 'They know bookkeeping, you are hiring a bookkeeper', 'weight' => 40, 'ref' => 'bookkeeping']], 'computed_at' => now()]);
    }

    /** @return list<string> */
    private function secrets(): array
    {
        return [
            'Thandiwe', 'Zulu', 'thandi.private', $this->pro->email, self::SECRET_EMPLOYER, 'Mkhize', 'Rosebank', 'rosebank-ext-7',
            'thandiwe-zulu-private', (string) $this->pro->profile_photo_path,
        ];
    }

    private function assertClean(string $haystack, string $where): void
    {
        foreach ($this->secrets() as $secret) {
            if ($secret === '') {
                continue;
            }
            $this->assertStringNotContainsStringIgnoringCase($secret, $haystack, "{$where} leaks [{$secret}]");
        }

        $id = $this->pro->id;
        $profileId = PulseProfile::query()->where('user_id', $id)->value('id');

        $this->assertDoesNotMatchRegularExpression('~/u/'.$id.'\b~', $haystack, "{$where} links to the professional's page");
        $this->assertDoesNotMatchRegularExpression('/(?:user_id|owner_id|recipient_id)(?:&quot;|"):\s*'.$id.'\b/', $haystack, "{$where} carries the user id");
        $this->assertDoesNotMatchRegularExpression('/(?:subject_id)(?:&quot;|"):\s*'.$profileId.'\b/', $haystack, "{$where} carries the profile id");
    }

    public function test_the_indexed_signal_itself_carries_nothing_that_names_the_person(): void
    {
        $row = json_encode($this->signal->only(['title', 'excerpt', 'topics', 'skills', 'place_id']));
        $this->assertClean($row, 'the signal row');
        $this->assertSame('Gauteng', PulsePlace::query()->find($this->signal->place_id)?->name, 'an anonymous professional is only ever as precise as a region');
    }

    public function test_every_page_the_employer_can_open_is_clean_including_livewire_state(): void
    {
        $this->actingAs($this->employer);

        // The positive control comes first (looking at a beat in full marks it seen): the card an employer is meant to see is on the Floor.
        $work = $this->get('/work')->getContent();
        $this->assertStringContainsStringIgnoringCase('5 to 9 years', $work);
        $this->assertClean($work, 'GET /work');
        PulseSave::query()->create(['user_id' => $this->employer->id, 'saveable_type' => 'signal', 'saveable_id' => $this->signal->id]);

        foreach (['/floor', '/work', '/search?q=bookkeeping', '/saved', '/s/'.$this->signal->id, '/lines', '/lines/'.$this->w('invite')->id, '/me/notifications', '/me/privacy', '/community'] as $url) {
            $response = $this->get($url);
            $this->assertSame(200, $response->getStatusCode(), $url);
            $this->assertClean($response->getContent(), "GET {$url}");
        }

    }

    public function test_the_search_cannot_be_used_to_find_the_suburb_or_the_name(): void
    {
        $search = fn (string $q) => app(\App\Services\Pulse\Discovery\SearchService::class)->search($this->employer, $q);

        foreach (['Rosebank', 'Thandiwe', 'Zulu', 'Mkhize', 'thandi.private', $this->pro->email] as $needle) {
            $found = collect($search($needle)['results'])->filter(fn ($r) => ($r['beat']->kind ?? null) === 'talent');
            $this->assertCount(0, $found, "a search for [{$needle}] found the anonymous professional");
        }

        $this->assertCount(1, collect($search('bookkeeping')['results'])->filter(fn ($r) => ($r['beat']->kind ?? null) === 'talent'));

        // A place filter at city level must not narrow below the region.
        $suburb = PulsePlace::query()->where('slug', 'rosebank-ext-7')->first();
        $other = PulsePlace::query()->create(['level' => 'city', 'country_code' => 'ZA', 'region' => 'Gauteng', 'city' => 'Benoni', 'name' => 'Benoni', 'slug' => 'benoni-1']);
        $count = fn (string $slug) => collect(app(\App\Services\Pulse\Discovery\SearchService::class)->search($this->employer, 'bookkeeping', ['place' => $slug])['results'])
            ->filter(fn ($x) => ($x['beat']->kind ?? null) === 'talent')->count();
        $this->assertSame($count($other->slug), $count($suburb->slug), 'filtering by the suburb must not reveal whether the person lives in it');
    }

    public function test_the_beat_array_has_no_profile_id_no_owner_and_no_precise_time(): void
    {
        $round = app(\App\Services\Pulse\Discovery\Floor::class)->round($this->employer, 'work');
        $beat = collect($round['beats'])->first(fn ($b) => $b->kind === 'talent');
        $this->assertNotNull($beat);

        $array = $beat->toArray();
        $this->assertNull($array['subject_id']);
        $this->assertNull($beat->signal->owner_id);
        $this->assertNull($beat->from['name']);
        $this->assertSame(0, (int) ($beat->published_at?->secondsSinceMidnight() ?? 0), 'the time of publication is rounded to a day or more');
        $this->assertClean(json_encode($array), 'Beat::toArray');
    }

    public function test_notifications_mail_exports_and_audit_rows_the_employer_can_reach_are_clean(): void
    {
        $lines = app(LineService::class);
        $invite = $this->w('invite');

        // The professional accepts and talks. The employer's notifications and the audit rows about the line are then read.
        $lines->accept($this->pro, $invite);
        $lines->send($this->pro, $invite->fresh(), 'Happy to talk about the role.');
        $lines->send($this->employer, $invite->fresh(), 'Great, tomorrow at ten?');

        $rows = DB::table('notifications')->where('notifiable_id', $this->employer->id)->pluck('data')->implode("\n");
        $this->assertNotSame('', $rows);
        $this->assertClean($rows, 'the employer\'s notifications');

        $this->assertClean(json_encode(PrivacyExport::forUser($this->employer)), "the employer's data export");

        $audit = DB::table('pulse_audit_log')->get()->map(fn ($r) => json_encode($r))->implode("\n");
        // Audit rows are for moderators and security; they may hold ids but must never hold the person's words or name.
        foreach (['Thandiwe', 'Zulu', 'Mkhize', 'Rosebank', $this->pro->email] as $secret) {
            $this->assertStringNotContainsStringIgnoringCase($secret, $audit, "the audit log holds [{$secret}]");
        }

        // After the person reveals themselves, the employer may see the name: the positive control.
        $lines->reveal($this->pro, $invite->fresh());
        $this->assertStringContainsString('Thandiwe', $this->actingAs($this->employer)->get('/lines/'.$invite->id)->getContent());
    }

    public function test_the_employer_cannot_message_or_notify_the_person_into_giving_the_name_away(): void
    {
        $lines = app(LineService::class);
        $invite = $this->w('invite');
        $lines->accept($this->pro, $invite);

        // Mail is built from the notification payloads; render the ones the employer would get.
        $notices = $this->employer->notifications()->get();
        foreach ($notices as $n) {
            $mail = (new \App\Notifications\PulseNotice($n->data, ['mail']))->toMail($this->employer);
            $this->assertClean($mail->subject.' '.implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines), 'a notification email');
        }
        $this->assertNotEmpty($notices);
    }

    public function test_a_hidden_employer_is_never_shown_the_person_and_the_toggle_is_honoured(): void
    {
        $profile = PulseProfile::query()->where('user_id', $this->pro->id)->first();
        $profile->forceFill(['work_history' => [['title' => 'Bookkeeper', 'org' => 'Acme Works Pty', 'employer' => 'Acme Works Pty', 'current' => true, 'hide_from_employer' => true]]])->save();

        $round = app(\App\Services\Pulse\Discovery\Floor::class)->round($this->employer, 'work');
        $this->assertNull(collect($round['beats'])->first(fn ($b) => $b->kind === 'talent'), 'a person who hid themselves from this employer is not shown to it');

        $this->actingAs($this->employer)->get('/s/'.$this->signal->id)->assertNotFound();
    }
}
