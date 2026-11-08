<?php

/**
 * Acceptance suite B seed. Run by run.mjs against a throwaway SQLite file:  php tests/e2e/pulse-b/seed.php
 * Everything is built through the real services (no raw inserts for domain data), then matched and indexed
 * with the real commands. Writes out/seed.json with the ids the specs need. Local only, never shipped.
 */

use App\Models\Community;
use App\Models\PulsePlace;
use App\Models\PulsePost;
use App\Models\Team;
use App\Models\User;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\NeedService;
use App\Services\Pulse\Entities\OfferService;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\RoleService;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$root = dirname(__DIR__, 3);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// The ContentGate runs the real LocalRules. Nothing is pinned: this is production behaviour.
config(['pulse.entities.rules_class' => null]);

$place = fn (string $name) => PulsePlace::query()->where('name', $name)->where('level', 'city')->firstOrFail();
$jhb = $place('Johannesburg');
$cpt = $place('Cape Town');

/** A person InfoDot has provisioned: user + personal team, optionally a company team. */
$person = function (string $name, string $email, array $profile = [], bool $company = false, bool $withProfile = true) {
    $u = User::factory()->withPersonalTeam()->create([
        'name' => $name, 'email' => $email, 'created_at' => now()->subDays(60),
    ]);
    if ($company) {
        Team::forceCreate(['user_id' => $u->id, 'name' => $name."'s company", 'personal_team' => false, 'infodot_team_uuid' => (string) Str::uuid()]);
    }
    if ($withProfile) {
        $ps = app(ProfileService::class);
        $ps->profileFor($u);
        DB::table('pulse_profiles')->where('user_id', $u->id)->update([
            'age_confirmed_at' => now()->subDays(30), 'onboarded_at' => now()->subDays(30), 'contact_policy' => 'requests',
            'place_id' => $profile['place']?->id ?? null, 'reach' => 'region',
        ]);
        unset($profile['place']);
        if ($profile) {
            $ps->update($u->fresh(), $profile);
        }
    }

    return $u->fresh();
};

$seed = ['users' => [], 'entities' => [], 'offers' => [], 'roles' => [], 'needs' => []];

// --- people ---
$anele = $person('Anele Dlamini', 'anele@e2e.test', ['place' => $jhb, 'headline' => 'Runs a small shop', 'interests' => ['bookkeeping', 'vat']]);
$sipho = $person('Sipho Moyo', 'sipho@e2e.test', ['place' => $jhb, 'headline' => 'Bookkeeper'], company: true);
$thandi = $person('Thandi Kopano', 'thandi@e2e.test', ['place' => $jhb, 'headline' => 'Runs Kopano Logistics'], company: true);
$lerato = $person('Lerato Zwane Anonsecret', 'lerato.private@e2e.test', [
    'place' => $jhb, 'headline' => 'Logistics coordinator with 6 years in freight', 'bio' => 'I run dispatch for a freight firm and want my next move to be a good one.',
    'skills' => ['dispatch', 'logistics', 'excel'], 'experience_years' => 6, 'kind' => 'professional',
    'work_history' => [['employer' => 'Secretfreight Holdings Pty Ltd', 'title' => 'Dispatch lead', 'from_year' => 2019, 'current' => true, 'hide_from_employer' => true]],
]);
$jabu = $person('Jabu Open Talent', 'jabu@e2e.test', ['place' => $jhb, 'headline' => 'Bookkeeper looking for a team', 'skills' => ['bookkeeping', 'vat', 'excel'], 'experience_years' => 4, 'kind' => 'professional']);
$mod = $person('Mpho Moderator', 'mod@e2e.test', ['place' => $jhb, 'headline' => 'Moderator']);
DB::table('pulse_profiles')->where('user_id', $mod->id)->update(['role' => 'moderator']);
$spam = $person('Spencer Spammer', 'spam@e2e.test', ['place' => $jhb, 'headline' => 'Sells everything']);
$extra = $person('Naledi Extra', 'naledi@e2e.test', ['place' => $jhb, 'headline' => 'Neighbour']);
$bizowner = $person('Busi Newseller', 'busi@e2e.test', ['place' => $jhb, 'headline' => 'Baker']); // onboarded, no InfoDot company
// Brand new: InfoDot provisioned the account, nothing else exists. No profile row at all.
$newbie = $person('Nomsa Brandnew', 'nomsa@e2e.test', withProfile: false);
// Declared nothing: under-18 path (never confirmed their age, never onboarded).
$minor = $person('Teen Unconfirmed', 'teen@e2e.test', withProfile: false);

$ps = app(ProfileService::class);
$ps->setDiscoverability($lerato, 'anonymous');
$ps->setDiscoverability($jabu, 'open');

// --- sellers and employer ---
$es = app(EntityService::class);
$books = $es->create($sipho, [
    'name' => 'Moyo Books', 'tagline' => 'Monthly books and VAT for small shops', 'about' => 'We keep small shop books straight and file VAT on time.',
    'is_seller' => true, 'industry' => 'Accounting', 'place_id' => $jhb->id, 'contact_policy' => 'anyone',
]);
$bookOffer = app(OfferService::class)->create($sipho, $books, [
    'title' => 'Monthly bookkeeping and VAT for small shops', 'summary' => 'Books kept, VAT filed, one fixed monthly fee.',
    'description' => 'We reconcile your bank, capture your invoices and file your VAT return every cycle. You get a one page report you can read.',
    'category' => 'Bookkeeping', 'tags' => ['bookkeeping', 'vat', 'accounting'], 'price_mode' => 'from', 'amount_cents' => 250000, 'unit' => 'month',
    'place_id' => $jhb->id, 'publish' => true,
]);
$plumb = $es->create($sipho, [
    'name' => 'Moyo Plumbing', 'tagline' => 'Geysers and leaks, same week', 'is_seller' => true, 'industry' => 'Plumbing',
    'place_id' => $jhb->id, 'contact_policy' => 'context',
]);
$plumbOffer = app(OfferService::class)->create($sipho, $plumb, [
    'title' => 'Geyser repair and replacement', 'summary' => 'Same week geyser repairs across Johannesburg.',
    'category' => 'Plumbing', 'tags' => ['plumbing', 'geyser'], 'price_mode' => 'quote', 'accepts_quotes' => true, 'place_id' => $jhb->id, 'publish' => true,
]);
$kopano = $es->create($thandi, [
    'name' => 'Kopano Logistics', 'tagline' => 'Freight and last mile', 'about' => 'Road freight and last mile delivery across Gauteng.',
    'is_employer' => true, 'is_seller' => true, 'industry' => 'Logistics', 'place_id' => $jhb->id, 'contact_policy' => 'requests',
    'hiring_signal' => 'active', 'hiring_skills' => ['dispatch', 'logistics'], 'team_size' => '11-50',
]);
$dispatchRole = app(RoleService::class)->create($thandi, $kopano, [
    'title' => 'Dispatch coordinator', 'summary' => 'Run daily dispatch for our Johannesburg depot.',
    'description' => 'You schedule drivers, track loads and keep customers informed. Excel and a calm voice on the phone.',
    'skills' => ['dispatch', 'logistics', 'excel'], 'work_mode' => 'onsite', 'employment_type' => 'full_time', 'place_id' => $jhb->id,
    'pay_text' => 'R18 000 to R22 000 a month', 'publish' => true,
]);

// --- needs ---
$needs = app(NeedService::class);
$aneleNeed = $needs->create($anele, [
    'title' => 'Need a bookkeeper for my shop', 'details' => 'Monthly books and VAT for a small spaza shop in Soweto.',
    'kind' => 'need', 'tags' => ['bookkeeping', 'vat'], 'reach' => 'region', 'place_id' => $jhb->id, 'budget_text' => 'R2 000 to R3 000 a month',
]);

// --- notification prefs for the spec 9 person ---
// (none: defaults are private; the spec changes them through the UI)

// --- richer data for the crawl (spec 15/16): a community with posts, an open line with messages, a quote request ---
// Specs that count lines or notifications start from their own users, so these use people no other spec drives:
// Naledi (extra) talks to Moyo Books; Jabu asks Moyo Plumbing for a quote (a quote group needs a need or offer).
if (getenv('PULSE_E2E_RICH') === '1') {
    $community = Community::factory()->create(['created_by' => $extra->id, 'name' => 'Soweto Small Business', 'slug' => 'soweto-small-business', 'description' => 'Neighbours who run small businesses.']);
    $post = PulsePost::factory()->create(['user_id' => $extra->id, 'community_id' => $community->id, 'title' => 'Who delivers in Soweto?', 'body' => 'Looking for a reliable courier for small parcels. Any recommendations?', 'status' => 'published']);
    $lines = app(LineService::class);
    $line = $lines->knock($extra, ['entity' => $books->id], 'enquiry', ['what' => 'Monthly books for a salon', 'when_or_qty' => 'From December', 'where' => 'Soweto', 'budget_band' => 'R1 500'], $bookOffer);
    $lines->send($sipho, $line->fresh(), 'Happy to help. How many invoices a month?');
    $lines->send($extra, $line->fresh(), 'About forty.');
    $richSeed = ['community' => $community->slug, 'post' => $post->id, 'line' => $line->id];
}

// Index and match through the real commands.
Artisan::call('pulse:index');
Artisan::call('pulse:match');

$mk = fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'email' => $u->email];
foreach (compact('anele', 'sipho', 'thandi', 'lerato', 'jabu', 'mod', 'spam', 'extra', 'bizowner', 'newbie', 'minor') as $k => $u) {
    $seed['users'][$k] = $mk($u);
}
$seed['entities'] = [
    'books' => ['id' => $books->id, 'slug' => $books->slug, 'moderation' => $books->moderation],
    'plumb' => ['id' => $plumb->id, 'slug' => $plumb->slug],
    'kopano' => ['id' => $kopano->id, 'slug' => $kopano->slug],
];
$seed['offers'] = [
    'book' => ['id' => $bookOffer->id, 'slug' => $bookOffer->slug, 'moderation' => $bookOffer->moderation, 'status' => $bookOffer->status],
    'plumb' => ['id' => $plumbOffer->id, 'slug' => $plumbOffer->slug, 'moderation' => $plumbOffer->moderation],
];
$seed['roles'] = ['dispatch' => ['id' => $dispatchRole->id, 'slug' => $dispatchRole->slug, 'moderation' => $dispatchRole->moderation]];
$seed['needs'] = ['anele' => ['id' => $aneleNeed->id, 'moderation' => $aneleNeed->moderation]];
$seed['signals'] = DB::table('pulse_signals')->select('id', 'kind', 'subject_type', 'subject_id', 'owner_id', 'moderation')->get()->all();
$seed['matches'] = DB::table('pulse_matches')->count();
$seed['rich'] = $richSeed ?? null;
$seed['secrets'] = ['lerato_name' => 'Anonsecret', 'lerato_email' => 'lerato.private@e2e.test', 'lerato_employer' => 'Secretfreight'];

$outDir = getenv('PULSE_E2E_OUT') ?: __DIR__.'/out';
@mkdir($outDir, 0777, true);
file_put_contents($outDir.'/seed.json', json_encode($seed, JSON_PRETTY_PRINT));
echo 'seed ok: '.count($seed['users']).' users, '.count($seed['signals'])." signals, {$seed['matches']} matches\n";
