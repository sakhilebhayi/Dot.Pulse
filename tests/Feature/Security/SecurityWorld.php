<?php

namespace Tests\Feature\Security;

use App\Models\PulseProfile;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\LinesFixtures;

/**
 * One small world for the security tests: people in every relationship to a seller page, an employer page, an
 * anonymous professional and the lines between them. Names and emails of the anonymous professional are distinctive
 * so a leak can be found by searching for them.
 */
trait SecurityWorld
{
    use LinesFixtures;

    public const SECRET_NAME = 'Thandiwe Zulu-Private';

    public const SECRET_FIRST = 'Thandiwe';

    public const SECRET_EMPLOYER = 'Mkhize Holdings Pty';

    public const SECRET_SUBURB = 'Rosebank Extension Seven';

    /** @var array<string, mixed> */
    protected array $w = [];

    protected function buildWorld(): void
    {
        $w = [];

        $w['stranger'] = $this->person();
        $w['moderator'] = $this->person();
        PulseProfile::query()->where('user_id', $w['moderator']->id)->update(['role' => 'moderator']);
        $w['moderator'] = $w['moderator']->fresh();

        // Seller page: owner, admin, editor, responder.
        $w['sellerOwner'] = $this->person();
        $w['seller'] = $this->page($w['sellerOwner'], ['name' => 'Moyo Plumbing Works', 'contact_policy' => 'anyone']);
        $w['seller']->forceFill(['moderation' => 'approved'])->save();
        $w['sellerAdmin'] = $this->member($w['seller'], null, 'admin');
        $w['sellerEditor'] = $this->member($w['seller'], null, 'editor');
        $w['sellerResponder'] = $this->member($w['seller'], null, 'responder');
        $w['offer'] = $this->offer($w['seller'], ['title' => 'Geyser repair live offer']);
        $w['heldOffer'] = $this->offer($w['seller'], ['title' => 'Held offer text', 'moderation' => 'pending']);
        $w['draftOffer'] = $this->offer($w['seller'], ['title' => 'Draft offer text', 'status' => 'draft']);
        $w['role'] = $this->role($w['seller'], ['title' => 'Live bookkeeper role']);
        $w['heldRole'] = $this->role($w['seller'], ['title' => 'Held bookkeeper role', 'moderation' => 'pending']);

        // Employer page.
        $w['employerOwner'] = $this->person([], ['name' => 'Zanele Mokoena']);
        $w['employer'] = $this->page($w['employerOwner'], ['name' => 'Acme Works Pty', 'is_employer' => true, 'is_seller' => false, 'contact_policy' => 'anyone']);
        $w['employer']->forceFill(['moderation' => 'approved'])->save();

        // The anonymous professional.
        [$pro, $signal] = $this->talent('anonymous');
        $w['pro'] = $pro;
        $w['talentSignal'] = $signal;

        // Needs.
        $w['needOwner'] = $this->person();
        $w['need'] = $this->need($w['needOwner']);
        $w['heldNeed'] = $this->need($w['needOwner'], ['moderation' => 'pending', 'title' => 'Held need text']);

        // Blocked and minor.
        $w['blocked'] = $this->person();
        DB::table('pulse_blocks')->insert(['blocker_id' => $w['sellerOwner']->id, 'blocked_id' => $w['blocked']->id, 'created_at' => now(), 'updated_at' => now()]);
        $w['minor'] = $this->minor();

        // A line between the stranger and the seller page, and one employer to anonymous pro.
        $w['line'] = $this->openLine($w['stranger'], $w['seller']);
        $w['invite'] = app(LineService::class)->knock($w['employerOwner'], ['signal' => $signal->id, 'as_entity' => $w['employer']->id], 'invite', ['what' => 'We have a bookkeeping role open', 'where' => 'Johannesburg']);

        $this->w = $w;
    }

    protected function w(string $key): mixed
    {
        return $this->w[$key];
    }

    protected function uniq(string $prefix = 'x'): string
    {
        return $prefix.'-'.Str::lower(Str::random(6));
    }
}
