<?php

namespace Database\Seeders;

use App\Models\PulseConversation;
use App\Models\PulseDoor;
use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseOffer;
use App\Models\PulseProfile;
use App\Models\PulseReview;
use App\Models\PulseRole;
use App\Models\Team;
use App\Models\User;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\NeedService;
use App\Services\Pulse\Entities\OfferService;
use App\Services\Pulse\Entities\ProfileService;
use App\Services\Pulse\Entities\RoleService;
use App\Services\Pulse\Entities\VerificationService;
use App\Services\Pulse\Lines\Doors;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\OutcomeService;
use App\Services\Pulse\Lines\QuoteService;
use App\Services\Pulse\Lines\ReviewService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A believable, clearly fictional South African demo world for Dot.Pulse, built through the real services (the same
 * code the product uses), so what you see is what the rules allow. Local, staging and test use only: it REFUSES to
 * run when APP_ENV=production.
 *
 * Everything is invented: people, businesses, phone numbers (the 555 range) and websites (.example). Every business
 * says so on its page. Idempotent: running it again finds what is there and adds only what is missing.
 *
 * Sign in as anyone with GET /dev/login/{id or email} (registered only under local and testing). The demo accounts
 * have a random password nobody knows; the dev route is the way in.
 *
 *   php artisan pulse:demo-seed
 */
class PulseDemoSeeder extends Seeder
{
    public const DOMAIN = 'demo.pulse.test';

    /** @var array<string, User> key => user */
    private array $users = [];

    /** @var array<string, PulseEntity> */
    private array $pages = [];

    /** @var array<string, PulseOffer> */
    private array $offers = [];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('The Pulse demo world is never created in production. APP_ENV=production refused.');
        }

        $this->say('Loading the gazetteer');
        (new PulsePlaceSeeder)->run();

        $this->people();
        $this->pages();
        $this->listings();
        $this->needs();
        $this->doors();
        $this->lines();
        $this->quotes();
        $this->outcome();

        $this->say('Indexing and matching');
        Artisan::call('pulse:index');
        Artisan::call('pulse:match', ['--all' => true, '--limit' => 2000]);

        $this->say('Demo world ready: '.User::where('email', 'like', '%@'.self::DOMAIN)->count().' people, '.PulseEntity::count().' pages.');
    }

    /** Email of a demo person, by key. */
    public static function email(string $key): string
    {
        return $key.'@'.self::DOMAIN;
    }

    /** @return array<string, array{name: string, role: string}> key => who they are, for the login table. */
    public static function cast(): array
    {
        return collect(self::PEOPLE)->map(fn ($p) => ['name' => $p['name'], 'role' => $p['role']])->all();
    }

    private const PEOPLE = [
        'naledi' => ['name' => 'Naledi Fenwick', 'role' => 'Owner, Zephyr Hollow Plumbing (Johannesburg)', 'kind' => 'professional', 'place' => 'johannesburg-za', 'policy' => 'anyone',
            'headline' => 'Plumber and owner of Zephyr Hollow Plumbing', 'skills' => ['plumbing', 'geyser repair', 'leak detection'], 'years' => 12, 'disc' => 'off', 'open_to' => []],
        'gideon' => ['name' => 'Gideon Marsh', 'role' => 'Owner, Granite & Gull Builders (Johannesburg)', 'kind' => 'professional', 'place' => 'sandton-johannesburg', 'policy' => 'anyone',
            'headline' => 'Builder and plumber, Granite & Gull', 'skills' => ['plumbing', 'building', 'renovations'], 'years' => 15, 'disc' => 'off', 'open_to' => []],
        'thuli' => ['name' => 'Thuli Ravenscroft', 'role' => 'Owner, Copper Kettle Plumbing (Johannesburg)', 'kind' => 'professional', 'place' => 'johannesburg-za', 'policy' => 'anyone',
            'headline' => 'Plumber, Copper Kettle Plumbing', 'skills' => ['plumbing', 'drain cleaning'], 'years' => 7, 'disc' => 'off', 'open_to' => []],
        'kagiso' => ['name' => 'Kagiso Alder', 'role' => 'Owner, Marmalade Moon Bakery (Cape Town)', 'kind' => 'professional', 'place' => 'cape-town-za', 'policy' => 'context',
            'headline' => 'Baker and owner of Marmalade Moon Bakery', 'skills' => ['baking', 'catering'], 'years' => 9, 'disc' => 'off', 'open_to' => ['suppliers']],
        'rudo' => ['name' => 'Rudo Pennyworth', 'role' => 'Owner, Tally Fox Bookkeeping (Pretoria)', 'kind' => 'professional', 'place' => 'pretoria-za', 'policy' => 'requests',
            'headline' => 'Bookkeeper and owner of Tally Fox Bookkeeping', 'skills' => ['bookkeeping', 'vat returns', 'payroll'], 'years' => 11, 'disc' => 'off', 'open_to' => ['mentoring']],
        'mandla' => ['name' => 'Mandla Thorncastle', 'role' => 'Owner, Oyster Road Logistics (Durban)', 'kind' => 'professional', 'place' => 'durban-za', 'policy' => 'anyone',
            'headline' => 'Freight and logistics operator, Oyster Road', 'skills' => ['logistics', 'freight forwarding', 'route planning'], 'years' => 14, 'disc' => 'off', 'open_to' => []],
        'ayesha' => ['name' => 'Ayesha Brightwater', 'role' => 'Employer building a team, Wildfig Talent Works (Johannesburg)', 'kind' => 'professional', 'place' => 'johannesburg-za', 'policy' => 'context',
            'headline' => 'Head of people, Wildfig Talent Works', 'skills' => ['recruitment', 'people operations'], 'years' => 10, 'disc' => 'off', 'open_to' => []],
        'lwazi' => ['name' => 'Lwazi Marchetti', 'role' => 'Designer, Paper Kite Studio (Cape Town)', 'kind' => 'professional', 'place' => 'cape-town-za', 'policy' => 'anyone',
            'headline' => 'Brand and graphic designer, Paper Kite Studio', 'skills' => ['graphic design', 'branding', 'illustration'], 'years' => 8, 'disc' => 'off', 'open_to' => ['freelance', 'collab']],
        'tendai' => ['name' => 'Tendai Ashcombe', 'role' => 'Open professional (bookkeeper, Johannesburg)', 'kind' => 'professional', 'place' => 'johannesburg-za', 'policy' => 'context',
            'headline' => 'Bookkeeper and payroll practitioner', 'skills' => ['bookkeeping', 'payroll', 'excel'], 'years' => 6, 'disc' => 'open', 'open_to' => ['work', 'freelance']],
        'palesa' => ['name' => 'Palesa Whitlock', 'role' => 'Anonymous professional (dispatch planner, Durban)', 'kind' => 'professional', 'place' => 'durban-za', 'policy' => 'context',
            'headline' => 'Dispatch and route planner for last-mile freight', 'skills' => ['logistics', 'route planning', 'excel'], 'years' => 4, 'disc' => 'anonymous', 'open_to' => ['work']],
        'ibrahim' => ['name' => 'Ibrahim Tidewater', 'role' => 'Learner, over 18 (Cape Town)', 'kind' => 'individual', 'place' => 'cape-town-za', 'policy' => 'context',
            'headline' => null, 'skills' => [], 'years' => null, 'disc' => 'off', 'open_to' => ['learning', 'mentoring'], 'interests' => ['bookkeeping', 'accounting', 'entrepreneurship']],
        'sandile' => ['name' => 'Sandile Rookwood', 'role' => 'Consumer with needs (Johannesburg)', 'kind' => 'individual', 'place' => 'johannesburg-za', 'policy' => 'context',
            'headline' => null, 'skills' => [], 'years' => null, 'disc' => 'off', 'open_to' => [], 'interests' => ['home improvement', 'small business']],
        'una' => ['name' => 'Una Featherstone', 'role' => 'Unrelated person, nothing in common with the trades (Cape Town)', 'kind' => 'individual', 'place' => 'cape-town-za', 'policy' => 'context',
            'headline' => null, 'skills' => [], 'years' => null, 'disc' => 'off', 'open_to' => [], 'interests' => ['drumming', 'music lessons']],
        'maple' => ['name' => 'Maple Moderator', 'role' => 'Platform moderator', 'kind' => 'individual', 'place' => 'johannesburg-za', 'policy' => 'context',
            'headline' => null, 'skills' => [], 'years' => null, 'disc' => 'off', 'open_to' => [], 'interests' => []],
    ];

    // ---------------------------------------------------------------------------------------------- people

    private function people(): void
    {
        $this->say('People');

        foreach (self::PEOPLE as $key => $p) {
            $this->users[$key] = $this->makePerson($key, $p);
        }

        PulseProfile::query()->where('user_id', $this->users['maple']->id)->update(['role' => 'moderator']);
    }

    /**
     * One demo person with a finished profile, made through ProfileService (also used by the end-to-end runner to add
     * extra professionals). Idempotent on the email.
     *
     * @param  array<string, mixed>  $p  name, kind, place (gazetteer slug), policy, headline, skills, years, disc, open_to, interests
     */
    public function makePerson(string $key, array $p): User
    {
        $profiles = app(ProfileService::class);
        $user = User::query()->where('email', self::email($key))->first();

        if ($user === null) {
            $user = new User(['name' => $p['name'], 'email' => self::email($key), 'password' => Hash::make(Str::random(40))]);
            $user->forceFill(['email_verified_at' => now()])->save();

            $user->ownedTeams()->save(Team::forceCreate([
                'user_id' => $user->id,
                'name' => explode(' ', $user->name, 2)[0]."'s Team",
                'personal_team' => true,
            ]));
        }

        $kind = $p['kind'] ?? 'professional';
        $data = array_filter([
            'kind' => $kind,
            'place_id' => $this->place($p['place']),
            'reach' => $kind === 'professional' ? 'region' : 'city',
            'show_place' => true,
            'headline' => $p['headline'] ?? null,
            'bio' => $kind === 'professional' ? 'Fictional demo person.' : 'Fictional demo person who uses Pulse to find what they need.',
            'skills' => ($p['skills'] ?? []) ?: null,
            'experience_years' => $p['years'] ?? null,
            'languages' => ['English'],
            'interests' => $p['interests'] ?? null,
        ], fn ($v) => $v !== null);

        $profiles->update($user, $data);
        $profiles->setContactPolicy($user, $p['policy'] ?? 'context');
        $profiles->setOpenTo($user, $p['open_to'] ?? []);
        $profiles->completeOnboarding($user, ageConfirmed: true);

        if (($p['disc'] ?? 'off') !== 'off') {
            $profiles->setDiscoverability($user, $p['disc']);
        }

        return $user;
    }

    // ---------------------------------------------------------------------------------------------- pages

    private function pages(): void
    {
        $this->say('Business pages');
        $entities = app(EntityService::class);

        $defs = [
            'zephyr' => ['owner' => 'naledi', 'name' => 'Zephyr Hollow Plumbing', 'tagline' => 'Geysers, leaks and blocked drains, sorted the same week.', 'seller' => true, 'employer' => false,
                'industry' => 'Plumbing', 'place' => 'johannesburg-za', 'area' => ['Johannesburg', 'Sandton', 'Randburg'], 'policy' => 'anyone', 'founded' => 2014, 'size' => '2-10',
                'site' => 'https://zephyrhollow.example', 'phone' => '011 555 0101',
                'about' => 'We fix and fit geysers, find leaks and clear drains across Johannesburg. A fictional demo business.'],
            'granite' => ['owner' => 'gideon', 'name' => 'Granite & Gull Builders', 'tagline' => 'Renovations and plumbing for homes and small shops.', 'seller' => true, 'employer' => true,
                'industry' => 'Building and renovation', 'place' => 'sandton-johannesburg', 'area' => ['Sandton', 'Johannesburg'], 'policy' => 'anyone', 'founded' => 2011, 'size' => '11-50',
                'site' => 'https://graniteandgull.example', 'phone' => '011 555 0102', 'hiring' => 'building', 'hiring_skills' => ['building', 'plumbing'],
                'about' => 'Bathrooms, extensions and plumbing for homes and small shops. A fictional demo business.', 'claim' => '2011/555123/07'],
            'copper' => ['owner' => 'thuli', 'name' => 'Copper Kettle Plumbing', 'tagline' => 'Honest plumbing with a written quote before we start.', 'seller' => true, 'employer' => false,
                'industry' => 'Plumbing', 'place' => 'johannesburg-za', 'area' => ['Johannesburg'], 'policy' => 'anyone', 'founded' => 2019, 'size' => '2-10',
                'about' => 'Plumbing repairs and installs. A fictional demo business.'],
            'marmalade' => ['owner' => 'kagiso', 'name' => 'Marmalade Moon Bakery', 'tagline' => 'Sourdough, celebration cakes and rusks, baked at dawn.', 'seller' => true, 'employer' => false,
                'industry' => 'Bakery', 'place' => 'cape-town-za', 'area' => ['Cape Town'], 'policy' => 'anyone', 'founded' => 2017, 'size' => '2-10',
                'site' => 'https://marmalademoon.example', 'about' => 'A neighbourhood bakery with a wedding cake habit. A fictional demo business.'],
            'tally' => ['owner' => 'rudo', 'name' => 'Tally Fox Bookkeeping', 'tagline' => 'Monthly books, VAT and payroll for small businesses.', 'seller' => true, 'employer' => false,
                'industry' => 'Bookkeeping', 'place' => 'pretoria-za', 'area' => ['Pretoria', 'Johannesburg'], 'policy' => 'requests', 'founded' => 2016, 'size' => '2-10',
                'site' => 'https://tallyfox.example', 'phone' => '012 555 0104', 'about' => 'We keep small-business books tidy and on time. A fictional demo business.', 'claim' => '2016/555321/07', 'infodot' => true],
            'oyster' => ['owner' => 'mandla', 'name' => 'Oyster Road Logistics', 'tagline' => 'Parcels and pallets between the coast and Gauteng.', 'seller' => true, 'employer' => true,
                'industry' => 'Freight and logistics', 'place' => 'durban-za', 'area' => ['Durban', 'Johannesburg', 'Cape Town'], 'policy' => 'anyone', 'founded' => 2013, 'size' => '11-50',
                'hiring' => 'building', 'hiring_skills' => ['logistics', 'route planning'],
                'about' => 'Reliable freight between Durban, Johannesburg and Cape Town. A fictional demo business.'],
            'wildfig' => ['owner' => 'ayesha', 'name' => 'Wildfig Talent Works', 'tagline' => 'A small finance team that lets you leave on time.', 'seller' => false, 'employer' => true,
                'industry' => 'Professional services', 'place' => 'johannesburg-za', 'area' => ['Johannesburg'], 'policy' => 'context', 'founded' => 2020, 'size' => '11-50',
                'hiring' => 'active', 'hiring_skills' => ['bookkeeping', 'payroll'], 'culture' => ['values' => ['Calm', 'Curious'], 'perks' => ['Hybrid work', 'Study leave'], 'work_mode' => 'hybrid'],
                'about' => 'We build finance teams for small firms and look after the people in them. A fictional demo business.'],
            'kite' => ['owner' => 'lwazi', 'name' => 'Paper Kite Studio', 'tagline' => 'Logos and brand kits that survive the first year.', 'seller' => true, 'employer' => false,
                'industry' => 'Graphic design', 'place' => 'cape-town-za', 'area' => ['Cape Town'], 'policy' => 'anyone', 'founded' => 2018, 'size' => '1',
                'site' => 'https://paperkite.example', 'about' => 'A one-person design studio. A fictional demo business.'],
        ];

        foreach ($defs as $key => $d) {
            $owner = $this->users[$d['owner']];
            $entity = PulseEntity::query()->where('created_by', $owner->id)->where('name', $d['name'])->first();

            if ($entity === null) {
                $entity = $entities->create($owner, array_filter([
                    'name' => $d['name'],
                    'tagline' => $d['tagline'],
                    'about' => $d['about'],
                    'is_seller' => $d['seller'],
                    'is_employer' => $d['employer'],
                    'industry' => $d['industry'],
                    'place_id' => $this->place($d['place']),
                    'service_area' => $d['area'],
                    'website' => $d['site'] ?? null,
                    'public_phone' => $d['phone'] ?? null,
                    'team_size' => $d['size'],
                    'founded_year' => $d['founded'],
                    'contact_policy' => $d['policy'],
                    'hiring_signal' => $d['hiring'] ?? null,
                    'hiring_skills' => $d['hiring_skills'] ?? null,
                    'culture' => $d['culture'] ?? null,
                ], fn ($v) => $v !== null));
            }

            if (isset($d['claim']) && blank($entity->registration_number)) {
                app(VerificationService::class)->claimRegistration($owner, $entity, $d['claim']);
            }

            $this->pages[$key] = $entity->refresh();
        }

        // Zephyr Hollow and Tally Fox are linked to an InfoDot company their owner belongs to: the only rung that
        // is read from InfoDot. It shows membership, never "verified".
        $this->linkInfoDot('zephyr', 'naledi', 'ba7f1c2e-0d41-4e4c-9b8a-5d3e9c1a0001', 'Zephyr Hollow (Pty) Ltd');
        $this->linkInfoDot('tally', 'rudo', 'ba7f1c2e-0d41-4e4c-9b8a-5d3e9c1a0002', 'Tally Fox (Pty) Ltd');
    }

    private function linkInfoDot(string $page, string $owner, string $uuid, string $companyName): void
    {
        $user = $this->users[$owner];
        $entity = $this->pages[$page];

        if (filled($entity->infodot_business_ref)) {
            return;
        }

        $team = Team::query()->where('infodot_team_uuid', $uuid)->first();

        if ($team === null) {
            $team = Team::forceCreate(['user_id' => $user->id, 'name' => $companyName, 'personal_team' => false]);
            $team->forceFill(['infodot_team_uuid' => $uuid])->save();
        }

        $user->unsetRelation('ownedTeams')->unsetRelation('teams');
        app(VerificationService::class)->linkInfoDotCompany($user, $entity, $team);
        $this->pages[$page] = $entity->refresh();
    }

    // ---------------------------------------------------------------------------------------------- offers and roles

    private function listings(): void
    {
        $this->say('Offers and roles');
        $offers = app(OfferService::class);
        $roles = app(RoleService::class);

        $defs = [
            ['page' => 'zephyr', 'key' => 'geyser', 'title' => 'Geyser repair and replacement', 'kind' => 'service', 'summary' => 'Burst, leaking or cold geyser? Quoted in writing before work starts.', 'tags' => ['plumbing', 'geyser repair'], 'mode' => 'from', 'amount' => 180000, 'quotes' => true],
            ['page' => 'zephyr', 'key' => 'drains', 'title' => 'Blocked drain clearing', 'kind' => 'service', 'summary' => 'Drains cleared the same day in most of Johannesburg.', 'tags' => ['plumbing', 'blocked drain'], 'mode' => 'fixed', 'amount' => 65000, 'quotes' => false],
            ['page' => 'granite', 'key' => 'bathroom', 'title' => 'Bathroom renovation', 'kind' => 'service', 'summary' => 'Full bathroom refits with plumbing and tiling included.', 'tags' => ['building', 'plumbing', 'renovations'], 'mode' => 'quote', 'quotes' => true],
            ['page' => 'granite', 'key' => 'plumbinggg', 'title' => 'Plumbing call-outs', 'kind' => 'service', 'summary' => 'Leaks, geysers and fittings for homes and small shops.', 'tags' => ['plumbing', 'geyser repair'], 'mode' => 'from', 'amount' => 150000, 'quotes' => true],
            ['page' => 'copper', 'key' => 'copperplumb', 'title' => 'Plumbing repairs and installs', 'kind' => 'service', 'summary' => 'A written quote before we start, and we tidy up after.', 'tags' => ['plumbing', 'geyser repair'], 'mode' => 'from', 'amount' => 120000, 'quotes' => true],
            ['page' => 'marmalade', 'key' => 'cakes', 'title' => 'Celebration and wedding cakes', 'kind' => 'product', 'summary' => 'Custom cakes for 20 to 150 guests, ordered two weeks ahead.', 'tags' => ['baking', 'wedding cakes'], 'mode' => 'from', 'amount' => 95000, 'quotes' => false],
            ['page' => 'marmalade', 'key' => 'sourdough', 'title' => 'Weekly sourdough box', 'kind' => 'product', 'summary' => 'Three loaves a week, collected or delivered in Cape Town.', 'tags' => ['baking', 'bread'], 'mode' => 'fixed', 'amount' => 24000, 'quotes' => false],
            ['page' => 'tally', 'key' => 'books', 'title' => 'Monthly bookkeeping for small businesses', 'kind' => 'service', 'summary' => 'Bank reconciliation, VAT and a one-page summary every month.', 'tags' => ['bookkeeping', 'vat returns'], 'mode' => 'from', 'amount' => 150000, 'quotes' => true],
            ['page' => 'oyster', 'key' => 'freight', 'title' => 'Pallet and parcel freight between the coast and Gauteng', 'kind' => 'service', 'summary' => 'Weekly collections from Durban and Cape Town with tracking.', 'tags' => ['logistics', 'freight forwarding'], 'mode' => 'quote', 'quotes' => true],
            ['page' => 'kite', 'key' => 'logo', 'title' => 'Logo and brand kit', 'kind' => 'service', 'summary' => 'A logo, colours and type for a small brand in three weeks.', 'tags' => ['graphic design', 'branding'], 'mode' => 'from', 'amount' => 450000, 'quotes' => true],
        ];

        foreach ($defs as $d) {
            $page = $this->pages[$d['page']];
            $owner = $this->owner($d['page']);
            $offer = PulseOffer::query()->where('entity_id', $page->id)->where('title', $d['title'])->first();

            if ($offer === null) {
                $offer = $offers->create($owner, $page, array_filter([
                    'title' => $d['title'],
                    'kind' => $d['kind'],
                    'summary' => $d['summary'],
                    'description' => $d['summary'].' Fictional demo listing.',
                    'tags' => $d['tags'],
                    'price_mode' => $d['mode'],
                    'amount_cents' => $d['amount'] ?? null,
                    'place_id' => $page->place_id,
                    'service_area' => $page->service_area,
                    'accepts_quotes' => $d['quotes'],
                    'publish' => true,
                ], fn ($v) => $v !== null));
            }

            $this->offers[$d['key']] = $offer;
        }

        $roleDefs = [
            ['page' => 'wildfig', 'title' => 'Junior bookkeeper', 'summary' => 'Capture, reconcile and learn on a calm team that finishes on time.', 'skills' => ['bookkeeping', 'excel'], 'seniority' => 'junior', 'mode' => 'hybrid'],
            ['page' => 'wildfig', 'title' => 'Payroll administrator', 'summary' => 'Run monthly payroll for several small clients and answer their questions.', 'skills' => ['payroll', 'bookkeeping'], 'seniority' => 'mid', 'mode' => 'hybrid'],
            ['page' => 'oyster', 'title' => 'Dispatch planner', 'summary' => 'Plan daily routes and keep drivers and customers informed.', 'skills' => ['logistics', 'route planning'], 'seniority' => 'mid', 'mode' => 'onsite'],
        ];

        foreach ($roleDefs as $d) {
            $page = $this->pages[$d['page']];

            if (PulseRole::query()->where('entity_id', $page->id)->where('title', $d['title'])->exists()) {
                continue;
            }

            $roles->create($this->owner($d['page']), $page, [
                'title' => $d['title'],
                'summary' => $d['summary'],
                'description' => $d['summary'].' Fictional demo role.',
                'employment_type' => 'full_time',
                'work_mode' => $d['mode'],
                'seniority' => $d['seniority'],
                'place_id' => $page->place_id,
                'skills' => $d['skills'],
                'pay_text' => 'Market related',
                'apply_mode' => 'on_pulse',
                'publish' => true,
            ]);
        }
    }

    // ---------------------------------------------------------------------------------------------- needs, doors

    private function needs(): void
    {
        $this->say('Needs');
        $needs = app(NeedService::class);

        $defs = [
            ['by' => 'sandile', 'title' => 'Burst geyser needs replacing this week', 'kind' => 'need', 'details' => 'Our geyser leaks through the ceiling. We need it assessed and replaced in Johannesburg.', 'tags' => ['plumbing', 'geyser repair'], 'place' => 'johannesburg-za', 'budget' => 'R2,000 to R6,000'],
            ['by' => 'sandile', 'title' => 'Bookkeeper for a small shop', 'kind' => 'need', 'details' => 'Monthly books and VAT for a corner shop with one till.', 'tags' => ['bookkeeping', 'vat returns'], 'place' => 'johannesburg-za', 'budget' => 'About R1,500 a month'],
            ['by' => 'ibrahim', 'title' => 'Looking for a bookkeeping mentor', 'kind' => 'mentor', 'details' => 'I am studying accounting and want to learn from someone who runs real books.', 'tags' => ['bookkeeping', 'accounting'], 'place' => 'cape-town-za', 'budget' => null],
            ['by' => 'kagiso', 'title' => 'Delivery partner from Cape Town to Durban', 'kind' => 'supplier', 'details' => 'A monthly pallet of rusks and dry goods needs a reliable carrier.', 'tags' => ['logistics', 'freight forwarding'], 'place' => 'cape-town-za', 'budget' => null],
            ['by' => 'rudo', 'title' => 'Designer for our brand refresh', 'kind' => 'need', 'details' => 'A fresh logo and colour palette for a small bookkeeping practice.', 'tags' => ['graphic design', 'branding'], 'place' => 'pretoria-za', 'budget' => 'R5,000 to R9,000'],
            ['by' => 'una', 'title' => 'Drum teacher for a beginner', 'kind' => 'need', 'details' => 'Weekly lessons for an adult who has never played.', 'tags' => ['music lessons', 'drumming'], 'place' => 'cape-town-za', 'budget' => null],
        ];

        foreach ($defs as $d) {
            $user = $this->users[$d['by']];

            if (PulseNeed::query()->where('user_id', $user->id)->where('title', $d['title'])->exists()) {
                continue;
            }

            $needs->create($user, array_filter([
                'title' => $d['title'],
                'kind' => $d['kind'],
                'details' => $d['details'],
                'tags' => $d['tags'],
                'place_id' => $this->place($d['place']),
                'reach' => 'city',
                'visibility' => 'matched',
                'budget_text' => $d['budget'],
            ], fn ($v) => $v !== null));
        }
    }

    private function doors(): void
    {
        $this->say('Doors');
        $doors = app(Doors::class);

        if (! PulseDoor::query()->open()->where('entity_id', $this->pages['zephyr']->id)->exists()) {
            $doors->open($this->pages['zephyr'], 'Open for geyser and burst pipe call-outs this week.', 'week', $this->users['naledi']);
        }

        if (! PulseDoor::query()->open()->where('owner_id', $this->users['tendai']->id)->exists()) {
            $doors->open($this->users['tendai'], 'Happy to talk bookkeeping gigs this week.', 'week');
        }
    }

    // ---------------------------------------------------------------------------------------------- lines

    private function lines(): void
    {
        $this->say('Conversations');
        $lines = app(LineService::class);
        $tally = $this->pages['tally'];

        // A request nobody has answered yet: a learner asks the bookkeeping practice (which takes requests).
        if (! PulseConversation::query()->where('initiator_id', $this->users['ibrahim']->id)->where('entity_id', $tally->id)->exists()) {
            $lines->knock($this->users['ibrahim'], ['entity' => $tally->id], 'enquiry', [
                'what' => 'Can a student shadow your month-end for a morning?',
                'when_or_qty' => 'Any weekday in March',
                'where' => 'Cape Town or online',
            ], $this->offers['books']);
        }

        // An open conversation: Sandile asked, Rudo accepted and they have talked.
        $sandile = $this->users['sandile'];
        $open = PulseConversation::query()->where('initiator_id', $sandile->id)->where('entity_id', $tally->id)->first();

        if ($open === null) {
            $open = $lines->knock($sandile, ['entity' => $tally->id], 'enquiry', [
                'what' => 'Monthly books and VAT for a one-till corner shop',
                'when_or_qty' => 'Starting next month',
                'where' => 'Johannesburg',
                'budget_band' => 'R1,000 to R2,000',
            ], $this->offers['books']);
            $lines->accept($this->users['rudo'], $open);
            $lines->send($this->users['rudo'], $open, 'Thanks for the details. We handle one-till shops all the time. Do you already have a VAT number?');
            $lines->send($sandile, $open, 'Yes, registered last year. I can send the last three bank statements once we agree on a price.');
        }
    }

    private function quotes(): void
    {
        $this->say('Quote comparison');
        $sandile = $this->users['sandile'];
        $need = PulseNeed::query()->where('user_id', $sandile->id)->where('title', 'Burst geyser needs replacing this week')->firstOrFail();

        if (PulseConversation::query()->where('initiator_id', $sandile->id)->where('kind', 'quote')->where('context_id', $need->id)->exists()) {
            return;
        }

        $quotes = app(QuoteService::class);
        $made = $quotes->request($sandile, ['need' => $need->id], [$this->pages['zephyr']->id, $this->pages['granite']->id, $this->pages['copper']->id], [
            'what' => 'Replace a 150 litre geyser that is leaking through the ceiling',
            'when_or_qty' => 'This week',
            'where' => 'Johannesburg',
            'budget_band' => 'R2,000 to R6,000',
        ]);

        $slips = [
            'zephyr' => ['amount_cents' => 485000, 'amount_max_cents' => 520000, 'included' => 'New 150 litre geyser, valve, labour and disposal of the old unit.', 'excluded' => 'Ceiling repair.', 'days' => 2],
            'granite' => ['amount_cents' => 560000, 'included' => 'Geyser, drip tray, labour and a certificate of compliance.', 'excluded' => 'Ceiling repair and painting.', 'days' => 4],
            'copper' => ['amount_cents' => 430000, 'amount_max_cents' => 470000, 'included' => 'Geyser and labour. Old unit left for you to dispose of.', 'excluded' => 'Certificate of compliance, ceiling repair.', 'days' => 1],
        ];

        foreach ($made as $c) {
            $key = collect($this->pages)->search(fn (PulseEntity $e) => $e->id === $c->entity_id);

            if (! isset($slips[$key])) {
                continue;
            }

            $s = $slips[$key];
            $quotes->respond($this->owner($key), $c, array_filter([
                'amount_cents' => $s['amount_cents'],
                'amount_max_cents' => $s['amount_max_cents'] ?? null,
                'currency' => 'ZAR',
                'earliest_date' => now()->addDays($s['days'])->toDateString(),
                'included' => $s['included'],
                'excluded' => $s['excluded'],
                'valid_until' => now()->addDays(14)->toDateString(),
            ], fn ($v) => $v !== null));
        }
    }

    private function outcome(): void
    {
        $this->say('Confirmed result with a revealed review');
        $kagiso = $this->users['kagiso'];
        $mandla = $this->users['mandla'];
        $oyster = $this->pages['oyster'];
        $lines = app(LineService::class);

        $c = PulseConversation::query()->where('initiator_id', $kagiso->id)->where('entity_id', $oyster->id)->first();

        if ($c === null) {
            $c = $lines->knock($kagiso, ['entity' => $oyster->id], 'enquiry', [
                'what' => 'A monthly pallet of rusks from Cape Town to Durban',
                'when_or_qty' => 'One pallet, first week of the month',
                'where' => 'Cape Town to Durban',
            ], $this->offers['freight']);
            $lines->send($mandla, $c, 'We can collect on the Monday and deliver by Thursday. I will send a quote on the pallet size.');
            $lines->send($kagiso, $c, 'Perfect, one standard pallet. Let us start next week.');
        }

        $outcomes = app(OutcomeService::class);
        $c->refresh();

        if ($c->outcome === 'none') {
            $outcomes->propose($kagiso, $c, 'won');
            $outcomes->confirm($mandla, $c->refresh());
        }

        $reviews = app(ReviewService::class);

        if (! PulseReview::query()->where('conversation_id', $c->id)->exists()) {
            $reviews->submit($kagiso, $c->refresh(), ['rating' => 5, 'body' => 'Collected on the day they said and delivered a day early.']);
            $reviews->submit($mandla, $c->refresh(), ['rating' => 5, 'body' => 'Clear brief, paid on time, easy to work with.']);
        }
    }

    // ---------------------------------------------------------------------------------------------- helpers

    private function owner(string $page): User
    {
        $owner = [
            'zephyr' => 'naledi', 'granite' => 'gideon', 'copper' => 'thuli', 'marmalade' => 'kagiso',
            'tally' => 'rudo', 'oyster' => 'mandla', 'wildfig' => 'ayesha', 'kite' => 'lwazi',
        ][$page];

        return $this->users[$owner];
    }

    public function place(string $slug): ?int
    {
        return DB::table('pulse_places')->where('slug', $slug)->value('id');
    }

    private function say(string $line): void
    {
        $this->command?->info($line);
    }
}
