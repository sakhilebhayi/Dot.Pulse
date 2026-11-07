<?php

namespace Tests\Feature\Entities;

use App\Models\PulseEntity;
use App\Models\Team;
use App\Services\Pulse\Entities\VerificationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VerificationServiceTest extends EntitiesTestCase
{
    private function service(): VerificationService
    {
        return app(VerificationService::class);
    }

    /** @return array<string, array{key: string, label: string, state: string, at: ?string, source: string, detail: string}> */
    private function rungs(PulseEntity $e): array
    {
        $out = [];

        foreach ($this->service()->ladder($e->fresh()) as $rung) {
            $out[$rung['key']] = $rung;
        }

        return $out;
    }

    public function test_a_new_page_shows_only_what_is_true(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);

        $rungs = $this->rungs($entity);

        $this->assertSame('missing', $rungs['infodot_team']['state']);
        $this->assertSame('checked', $rungs['email_confirmed']['state']);
        $this->assertSame('pulse_account', $rungs['email_confirmed']['source']);
        $this->assertSame('missing', $rungs['registration']['state']);
        $this->assertSame('missing', $rungs['website']['state']);
        $this->assertSame('missing', $rungs['outcomes']['state']);
        $this->assertSame('checked', $rungs['time_on_pulse']['state']);
        $this->assertArrayNotHasKey('infodot_verified', $rungs);
        $this->assertSame('unverified', $entity->fresh()->verification_status);
    }

    public function test_every_rung_has_state_source_detail_and_time_shape(): void
    {
        foreach ($this->service()->ladder($this->page()) as $rung) {
            $this->assertSame(['key', 'label', 'state', 'at', 'source', 'detail'], array_keys($rung));
            $this->assertContains($rung['state'], ['checked', 'claimed', 'missing']);
            $this->assertNotSame('', $rung['source']);
            $this->assertNotSame('', $rung['detail']);

            if ($rung['state'] === 'checked') {
                $this->assertNotNull($rung['at'], $rung['key'].' is checked and must carry a time');
            }
        }
    }

    public function test_an_unconfirmed_owner_email_is_missing(): void
    {
        $owner = $this->person(['email_verified_at' => null]);

        $this->assertSame('missing', $this->rungs($this->page($owner))['email_confirmed']['state']);
    }

    public function test_the_registration_number_is_a_claim_and_never_proof(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);

        $this->service()->claimRegistration($owner, $entity, '2019/123456/07');

        $rung = $this->rungs($entity)['registration'];
        $this->assertSame('claimed', $rung['state']);
        $this->assertSame('business_claim', $rung['source']);
        $this->assertStringContainsString('Pulse has not checked it', $rung['detail']);
        $this->assertNotNull($rung['at']);
        $this->assertSame('claimed', $entity->fresh()->verification_status);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'entity.claim.registration']);
    }

    public function test_bad_registration_numbers_and_wrong_people_are_refused(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);

        foreach (['', 'x', '<script>', str_repeat('9', 60)] as $bad) {
            try {
                $this->service()->claimRegistration($owner, $entity, $bad);
                $this->fail("accepted '$bad'");
            } catch (ValidationException) {
            }
        }

        foreach ([$this->person(), $this->member($entity, 'editor')] as $nobody) {
            try {
                $this->service()->claimRegistration($nobody, $entity, '2019/123456/07');
                $this->fail('expected an authorisation failure');
            } catch (AuthorizationException) {
                $this->assertNull($entity->fresh()->registration_number);
            }
        }
    }

    public function test_linking_to_an_infodot_company_gives_team_member_not_verified(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner, 'Karoo Holdings');
        $entity = $this->page($owner);

        $this->service()->linkInfoDotCompany($owner, $entity, $team);

        $entity = $entity->fresh();
        $rung = $this->rungs($entity)['infodot_team'];
        $this->assertSame('infodot_member', $entity->verification_status);
        $this->assertSame('infodot', $entity->verification_source);
        $this->assertSame($team->infodot_team_uuid, $entity->verification_ref);
        $this->assertSame('checked', $rung['state']);
        $this->assertSame('infodot_redeem_teams', $rung['source']);
        $this->assertStringContainsString('Karoo Holdings', $rung['detail']);
        $this->assertStringContainsString('has not verified the business', $rung['detail']);
        $this->assertNull($entity->verified_at, 'membership is not verification');
        $this->assertNotContains('infodot_verified', array_keys($this->rungs($entity)));
        $this->assertNotSame('infodot_verified', $entity->verification_status);
        $this->assertSame('InfoDot team member', $this->service()->summary($entity)['label']);
    }

    public function test_only_an_owner_who_manages_the_company_can_link_it(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $team = $this->companyFor($this->person(), 'Not yours');
        $mine = $this->companyFor($owner, 'Mine');

        // a company the person is not in
        try {
            $this->service()->linkInfoDotCompany($owner, $entity, $team);
            $this->fail('not their company');
        } catch (ValidationException) {
        }

        // a personal team or a team with no InfoDot uuid
        $local = Team::forceCreate(['user_id' => $owner->id, 'name' => 'Local only', 'personal_team' => false]);

        try {
            $this->service()->linkInfoDotCompany($owner, $entity, $local);
            $this->fail('no InfoDot uuid');
        } catch (ValidationException) {
        }

        // an admin of the page is not the owner
        try {
            $this->service()->linkInfoDotCompany($this->member($entity, 'admin'), $entity, $mine);
            $this->fail('only an owner');
        } catch (ValidationException|AuthorizationException) {
        }

        $this->assertNull($entity->fresh()->infodot_business_ref);
    }

    public function test_a_company_links_to_one_page_only(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner);
        $first = $this->page($owner);
        $second = $this->page($owner);

        $this->service()->linkInfoDotCompany($owner, $first, $team);

        $this->expectException(ValidationException::class);
        $this->service()->linkInfoDotCompany($owner, $second, $team);
    }

    public function test_the_link_lapses_when_the_person_leaves_the_company(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner);
        $entity = $this->page($owner);
        $this->service()->linkInfoDotCompany($owner, $entity, $team);
        $this->assertSame('infodot_member', $entity->fresh()->verification_status);

        // InfoDot stops listing the company; the sign-in sync deletes the team here.
        DB::table('teams')->where('id', $team->id)->delete();

        $this->service()->refreshFromInfoDot($entity->fresh());

        $entity = $entity->fresh();
        $this->assertSame('unverified', $entity->verification_status);
        $this->assertNull($entity->verification_source);
        $this->assertNull($entity->infodot_verification);
        $this->assertSame('missing', $this->rungs($entity)['infodot_team']['state']);
    }

    public function test_unlink(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner);
        $entity = $this->page($owner);
        $this->service()->linkInfoDotCompany($owner, $entity, $team);

        $this->service()->unlinkInfoDotCompany($owner, $entity->fresh());

        $this->assertSame('unverified', $entity->fresh()->verification_status);
        $this->assertNull($entity->fresh()->infodot_business_ref);
    }

    // ---- the dormant path: a future teams[].verification object ----------------------------------------------------

    private function identity(string $uuid, ?array $verification): array
    {
        $team = ['id' => 5, 'uuid' => $uuid, 'name' => 'Karoo Holdings', 'personal' => false, 'role' => 'owner', 'infodot_role' => 'owner'];

        if ($verification !== null) {
            $team['verification'] = $verification;
        }

        return ['user' => ['id' => 7, 'name' => 'X', 'email' => 'x@example.test', 'email_verified' => true], 'teams' => [$team], 'current_team_id' => 5];
    }

    public function test_today_a_sign_in_without_a_verification_object_never_yields_verified(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner);
        $entity = $this->page($owner);
        $this->service()->linkInfoDotCompany($owner, $entity, $team);

        $this->service()->refreshFromInfoDot($entity->fresh(), $this->identity($team->infodot_team_uuid, null));

        $this->assertSame('infodot_member', $entity->fresh()->verification_status);
        $this->assertNull($entity->fresh()->verified_at);
    }

    public function test_a_future_verified_object_is_read_when_present(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner);
        $entity = $this->page($owner);
        $this->service()->linkInfoDotCompany($owner, $entity, $team);

        $this->service()->refreshFromInfoDot($entity->fresh(), $this->identity($team->infodot_team_uuid, [
            'status' => 'verified',
            'verified_at' => now()->subMonth()->toIso8601String(),
            'expires_at' => now()->addMonths(11)->toIso8601String(),
            'checks' => ['registration_document', 'official_email'],
            'registration_number' => '2019/123456/07', // never stored
        ]));

        $entity = $entity->fresh();
        $this->assertSame('infodot_verified', $entity->verification_status);
        $this->assertNotNull($entity->verified_at);
        $rungs = $this->rungs($entity);
        $this->assertSame('checked', $rungs['infodot_verified']['state']);
        $this->assertSame('infodot_redeem_verification', $rungs['infodot_verified']['source']);
        $this->assertStringNotContainsString('2019/123456/07', json_encode($entity->infodot_verification));
    }

    public function test_an_expired_revoked_or_unlinked_verification_object_does_not_count(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner);
        $entity = $this->page($owner);
        $this->service()->linkInfoDotCompany($owner, $entity, $team);

        foreach ([
            ['status' => 'verified', 'verified_at' => now()->subYears(2)->toIso8601String(), 'expires_at' => now()->subYear()->toIso8601String()],
            ['status' => 'revoked', 'verified_at' => now()->subMonth()->toIso8601String()],
            ['status' => 'pending'],
            ['status' => 'verified'],
        ] as $payload) {
            $this->service()->refreshFromInfoDot($entity->fresh(), $this->identity($team->infodot_team_uuid, $payload));
            $this->assertSame('infodot_member', $entity->fresh()->verification_status, json_encode($payload));
            $this->assertArrayNotHasKey('infodot_verified', $this->rungs($entity));
        }

        // a verification object about some other company is ignored
        $this->service()->refreshFromInfoDot($entity->fresh(), $this->identity('00000000-0000-0000-0000-000000000000', ['status' => 'verified', 'verified_at' => now()->toIso8601String()]));
        $this->assertSame('infodot_member', $entity->fresh()->verification_status);

        // and a verification object does nothing for a page that is not linked
        $other = $this->page($owner);
        $this->service()->refreshFromInfoDot($other, $this->identity($team->infodot_team_uuid, ['status' => 'verified', 'verified_at' => now()->toIso8601String()]));
        $this->assertSame('unverified', $other->fresh()->verification_status);
    }

    public function test_refresh_for_user_refreshes_their_pages(): void
    {
        $owner = $this->person();
        $team = $this->companyFor($owner);
        $a = $this->page($owner);
        $this->page($owner);
        $this->service()->linkInfoDotCompany($owner, $a, $team);

        $this->assertSame(2, $this->service()->refreshForUser($owner, $this->identity($team->infodot_team_uuid, ['status' => 'verified', 'verified_at' => now()->toIso8601String()])));
        $this->assertSame('infodot_verified', $a->fresh()->verification_status);
    }

    public function test_confirmed_outcomes_come_from_conversations_only_when_both_sides_confirmed(): void
    {
        $entity = $this->page();
        $person = $this->person();
        $now = now();
        $row = fn (array $over) => array_merge(['type' => 'direct', 'created_at' => $now, 'updated_at' => $now, 'kind' => 'enquiry', 'status' => 'closed', 'initiator_id' => $person->id, 'entity_id' => $entity->id], $over);

        DB::table('pulse_conversations')->insert($row(['outcome' => 'won', 'outcome_initiator_at' => $now, 'outcome_recipient_at' => null]));
        $this->assertSame('missing', $this->rungs($entity)['outcomes']['state']);

        DB::table('pulse_conversations')->insert($row(['outcome' => 'won', 'outcome_initiator_at' => $now, 'outcome_recipient_at' => $now]));
        $rung = $this->rungs($entity)['outcomes'];
        $this->assertSame('checked', $rung['state']);
        $this->assertSame('pulse_conversations', $rung['source']);
        $this->assertStringContainsString('1 result', $rung['detail']);
        $this->assertNotNull($rung['at']);
    }

    public function test_summary_counts(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner, ['website' => 'https://karoo.example']);

        $summary = $this->service()->summary($entity);

        $this->assertSame(2, $summary['checked']); // owner email, time on Pulse
        $this->assertSame(1, $summary['claimed']); // website
        $this->assertSame('Not verified', $summary['label']);
    }
}
