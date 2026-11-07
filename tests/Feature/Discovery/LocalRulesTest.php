<?php

namespace Tests\Feature\Discovery;

use App\Models\PulsePost;
use App\Models\User;
use App\Services\Pulse\Discovery\Moderation\LocalRules;
use App\Services\Pulse\Discovery\Moderation\ModerationPipeline;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalRulesTest extends TestCase
{
    use RefreshDatabase;

    private function veteran(): User
    {
        return User::factory()->create(['created_at' => now()->subYear()]);
    }

    /**
     * text, expected verdict, a flag code that must be present (or null for none expected)
     *
     * @return array<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function samples(): array
    {
        return [
            // -- ordinary text is published
            'plain post' => ['Looking for a reliable bookkeeper in Sandton, ideally someone who knows Pastel and VAT returns.', 'allow', null],
            'offer with price' => ['Geyser installation from R4 500, includes the old unit removal. Booking this week in Pretoria East.', 'allow', null],
            'question' => ['How do you price a quote for a tiling job when the client supplies the tiles?', 'allow', null],
            'short friendly' => ['Thanks everyone, the meetup on Thursday was great.', 'allow', null],
            'one link' => ['Our new price list is on https://example.co.za/prices if you want to have a look before Friday.', 'allow', null],
            'afrikaans' => ['Soek iemand om my dak te herstel in Stellenbosch, die lekplekke is by die kombuis.', 'allow', null],
            'isizulu' => ['Ngifuna umuntu ongakwazi ukukhipha izinsimbi zokuvikela endlini yami e-Durban.', 'allow', null],
            'caps acronyms' => ['We need a SHEQ officer with a valid PSIRA grade C and SARS eFiling experience for the Gauteng site.', 'allow', null],
            'price with zeros' => ['Stock clearance: 100 units at R1 000 000 total, collection only from our depot in Germiston.', 'allow', null],
            'warning without payload' => ['Please be careful when buying second hand phones, always meet at a police station.', 'allow', null],
            'job without fee' => ['We are hiring a learnership coordinator in Polokwane. Apply on the Pulse page, no fees of any kind.', 'allow', null],
            'normal exclamation' => ['We are open on Saturday! Come and see the new range of tiles.', 'allow', null],

            // -- contact details in public text wait for a person
            'cell number' => ['Call me on 082 555 1234 about the van, I am in Benoni.', 'hold', 'contact_in_public'],
            'plus27 number' => ['WhatsApp +27 82 555 1234 for a quote on the paving.', 'hold', 'contact_in_public'],
            'landline' => ['Office line 011 555 1234, we open at seven.', 'hold', 'contact_in_public'],
            'email address' => ['Send your CV to hr@acme-steel.co.za and mention the welding role.', 'hold', 'contact_in_public'],
            'international number' => ['Reach our Lagos office on +234 802 123 4567 during business hours.', 'hold', 'contact_in_public'],

            // -- links
            'shortener' => ['Great deal here https://bit.ly/3abcXYZ for a limited time, have a look.', 'hold', 'short_link'],
            'wa.me link' => ['Chat to us on https://wa.me/27825551234 about the stock.', 'hold', 'short_link'],
            'three links' => ['See https://a.example.com and https://b.example.com and https://c.example.com for our catalogue and our reviews.', 'hold', 'link_density'],
            'two links very short' => ['Visit https://a.example.com or https://b.example.com now', 'hold', 'link_density'],
            'link flood' => ['https://a.example.com https://b.example.com https://c.example.com https://d.example.com https://e.example.com https://f.example.com all of these are great', 'block', 'link_flood'],

            // -- shouting and repetition
            'shouting' => ['BUY NOW BEST PRICES IN GAUTENG DO NOT MISS OUT ON THIS OPPORTUNITY TODAY', 'hold', 'shouting'],
            'repeated word' => ['cheap cheap cheap cheap cheap cheap cheap tiles tiles', 'hold', 'repeated_text'],
            'repeated chars' => ['Looking for work aaaaaaaaaaaaaaaaaaaaaa please help', 'hold', 'repeated_text'],
            'repeated lines' => ["Best tiles in town.\nBest tiles in town.\nBest tiles in town.\nBest tiles in town.", 'hold', 'repeated_text'],

            // -- numbers
            'sa id number' => ['My ID is 8001015009087 if you need to verify me for the vehicle licence disc.', 'hold', 'id_number'],
            'card number' => ['Pay to card 4111 1111 1111 1111 expiry 12/29 and I will release the goods.', 'block', 'card_number'],

            // -- South African scam patterns
            'fake pop with fee' => ['I have paid, proof of payment attached. Now please pay the courier fee R450 so the funds can be released.', 'block', 'scam_fake_pop_fee'],
            'fake pop only' => ['Proof of payment attached, please check your banking app and dispatch the item to Durban.', 'hold', 'scam_fake_pop'],
            'learnership fee' => ['Learnership available at our company in Cape Town. Pay a registration fee of R350 to secure your place, limited spots.', 'block', 'scam_job_fee_block'],
            'job admin fee' => ['Cleaning vacancy open immediately, deposit R200 admin fee to our account to start your application.', 'block', 'scam_job_fee_block'],
            'fee soft' => ['Please pay the training fee R500 before the course starts on Monday.', 'hold', 'scam_job_fee'],
            'inheritance' => ['I am a barrister handling the fund of my late client, you are the next of kin of 8.5 million USD.', 'block', 'scam_advance_fee'],
            'lottery' => ['Congratulations you have won the lottery draw, send your details to claim.', 'block', 'scam_advance_fee'],
            'otp request' => ['To verify your account please send me your OTP and your banking details.', 'block', 'scam_credentials'],
            'loan upfront' => ['Blacklisted? Guaranteed loan approved today, pay an upfront insurance fee of R600 to release it.', 'block', 'scam_loan_fee'],
            'double your money' => ['Double your money in 48 hours with our forex group, guaranteed returns for every member.', 'block', 'scam_investment'],
            'deposit before viewing' => ['Lovely flat in Rosebank. Pay a holding fee before viewing so we can keep it for you.', 'block', 'scam_deposit_viewing'],
            'work from home easy money' => ['Work from home and earn R5000 every week with no experience needed.', 'hold', 'scam_work_from_home'],
            'sassa phishing' => ['SASSA R350 grant update, click the link to verify your details today.', 'hold', 'scam_grant_phishing'],
            'scam warning post' => ['Beware: scammers send fake proof of payment and then ask you to pay a courier fee. Do not release any goods.', 'hold', 'scam_fake_pop_fee'],

            // -- abuse
            'threat' => ['If you do that again I will kill you and your family.', 'block', 'threat'],
            'blocked term' => ['Order viagra online, cheap and fast delivery to any province.', 'block', 'blocked_term'],
            'hold term' => ['Sugar daddy wanted, serious replies only.', 'hold', 'blocked_term'],
            'empty' => ['   ', 'hold', 'empty'],
        ];
    }

    /** @dataProvider samples */
    #[\PHPUnit\Framework\Attributes\DataProvider('samples')]
    public function test_verdict_table(string $text, string $verdict, ?string $flag): void
    {
        $result = app(LocalRules::class)->assess($text, $this->veteran());

        $this->assertSame($verdict, $result['verdict'], 'flags: '.json_encode(array_column($result['flags'], 'code')));

        if ($flag !== null) {
            $this->assertContains($flag, array_column($result['flags'], 'code'));
        } else {
            $this->assertSame([], $result['flags']);
            $this->assertNull($result['reason']);
        }

        if ($verdict !== 'allow') {
            $this->assertNotEmpty($result['reason'], 'a held or blocked text always carries a reason the author can read');
        }
    }

    public function test_contact_details_are_fine_in_private_text(): void
    {
        $result = app(LocalRules::class)->assess('Call me on 082 555 1234 about the van.', $this->veteran(), ['public' => false]);

        $this->assertSame('allow', $result['verdict']);
    }

    public function test_a_brand_new_account_with_a_link_waits(): void
    {
        $fresh = User::factory()->create(['created_at' => now()->subMinutes(10)]);

        $result = app(LocalRules::class)->assess('Our price list is on https://example.co.za/prices, have a look.', $fresh);

        $this->assertSame('hold', $result['verdict']);
        $this->assertContains('new_account_link', array_column($result['flags'], 'code'));
        $this->assertSame('allow', app(LocalRules::class)->assess('Our price list is on https://example.co.za/prices, have a look.', $this->veteran())['verdict']);
    }

    public function test_posting_speed_holds_then_blocks(): void
    {
        $user = $this->veteran();
        $rules = app(LocalRules::class);

        $this->assertSame('allow', $rules->assess('A perfectly ordinary post about tiles.', $user, ['recent_count' => 2])['verdict']);
        $this->assertSame('hold', $rules->assess('A perfectly ordinary post about tiles.', $user, ['recent_count' => 8])['verdict']);
        $this->assertSame('block', $rules->assess('A perfectly ordinary post about tiles.', $user, ['recent_count' => 40])['verdict']);

        // Counted from the author's real posts when the caller does not say.
        PulsePost::factory()->count(9)->create(['user_id' => $user->id]);
        $this->assertContains('velocity', array_column($rules->assess('Another ordinary post about paving.', $user)['flags'], 'code'));
    }

    public function test_the_same_post_again_is_held_and_a_flood_is_blocked(): void
    {
        $user = $this->veteran();
        $rules = app(LocalRules::class);
        $body = 'Selling a second hand welding machine in Boksburg, works well, serious buyers only.';

        PulsePost::factory()->create(['user_id' => $user->id, 'body' => $body]);
        $this->assertContains('duplicate', array_column($rules->assess($body, $user)['flags'], 'code'));

        PulsePost::factory()->count(2)->create(['user_id' => $user->id, 'body' => strtoupper($body)]);
        $this->assertSame('block', $rules->assess($body, $user)['verdict']);

        // A post being re-assessed does not count itself.
        $own = PulsePost::factory()->create(['user_id' => $this->veteran()->id, 'body' => 'A unique body about paving in Midrand, thanks.']);
        $this->assertSame('allow', $rules->assess($own->body, $own->author, ['exclude_post_id' => $own->id])['verdict']);
    }

    public function test_the_caller_can_pass_recent_texts_for_other_kinds_of_content(): void
    {
        $rules = app(LocalRules::class);
        $r = $rules->assess('Hi, is the van still available for collection?', $this->veteran(), ['kind' => 'message', 'public' => false, 'recent_texts' => ['hi is the van still available for collection']]);

        $this->assertContains('duplicate', array_column($r['flags'], 'code'));
    }

    public function test_a_title_counts_as_text(): void
    {
        $r = app(LocalRules::class)->assess('Details in the post.', $this->veteran(), ['title' => 'Double your money in 48 hours']);

        $this->assertSame('block', $r['verdict']);
    }

    public function test_the_pipeline_maps_a_verdict_to_the_columns_other_modules_store(): void
    {
        $pipeline = app(ModerationPipeline::class);
        $user = $this->veteran();

        $ok = $pipeline->review('Plumbing repairs in Pretoria East, free quotes.', $user, ['kind' => 'offer']);
        $this->assertTrue($ok->allowed());
        $this->assertSame('approved', $ok->moderation());
        $this->assertSame('live', $ok->signalStatus());

        $hold = $pipeline->review('Call 082 555 1234 for plumbing.', $user, ['kind' => 'offer']);
        $this->assertTrue($hold->held());
        $this->assertSame('held', $hold->moderation());
        $this->assertSame('held', $hold->signalStatus());
        $this->assertNotEmpty($hold->reason);

        $block = $pipeline->review('Double your money in 48 hours', $user, ['kind' => 'need']);
        $this->assertTrue($block->blocked());
        $this->assertSame('removed', $block->moderation());
        $this->assertSame('removed', $block->signalStatus());
        $this->assertContains('scam_investment', $block->codes());

        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'pulse.moderation.hold', 'actor_id' => $user->id]);
        $this->assertDatabaseHas('pulse_audit_log', ['action' => 'pulse.moderation.block']);
        $this->assertDatabaseMissing('pulse_audit_log', ['action' => 'pulse.moderation.allow']);
    }

    public function test_no_rule_looks_at_who_the_author_is(): void
    {
        // The same words from a Zulu name, an Afrikaans name and an English name get the same verdict.
        $text = 'Looking for an electrician in Soweto, certificate of compliance needed.';
        $verdicts = collect(['Nomvula Khumalo', 'Pieter van der Merwe', 'Sarah Jones'])
            ->map(fn ($name) => app(LocalRules::class)->assess($text, User::factory()->create(['name' => $name, 'created_at' => now()->subYear()]))['verdict'])
            ->unique();

        $this->assertSame(['allow'], $verdicts->all());
    }
}
