<?php

namespace App\Livewire\Pulse\Business\Support;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseOffer;
use App\Models\PulseRole;
use App\Models\User;
use App\Services\Pulse\Discovery\Places\PlaceRepository;
use App\Services\Pulse\Entities\EntityService;
use App\Services\Pulse\Entities\MediaService;
use App\Services\Pulse\Entities\VerificationService;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\ResponseStats;
use Illuminate\Support\Carbon;

/**
 * Everything a business page shows, gathered once, from real rows. Used by the member page and by the guest card, so
 * the two can never disagree about a fact. Nothing here is a made-up number: a figure that has no data is null and the
 * view hides it.
 */
class EntityView
{
    /** @return array<string, mixed> */
    public static function data(PulseEntity $entity, ?User $viewer = null): array
    {
        $media = app(MediaService::class);
        $places = app(PlaceRepository::class);
        $verification = app(VerificationService::class);

        $offers = $entity->offers()->live()->latest('published_at')->limit(24)->get();
        $roles = $entity->roles()->live()->latest('published_at')->limit(24)->get();

        $response = ResponseStats::for($entity);

        return [
            'entity' => $entity,
            'logo' => $entity->logo_media_id ? $media->url($entity->logo_media_id, 'medium') : null,
            'cover' => $entity->cover_media_id ? $media->url($entity->cover_media_id, 'large') : null,
            'place' => $entity->place ? $places->label($entity->place) : null,
            'offers' => $offers,
            'roles' => $roles,
            'ladder' => self::ladder($entity),
            'status' => $verification->summary($entity),
            'response' => $response['response_rate'] === null ? null : $response,
            'stats' => app(EntityService::class)->stats($entity),
            'isMember' => $viewer !== null && $entity->memberRole($viewer) !== null,
        ];
    }

    /**
     * The verification ladder with dates and sources in words a person reads ("7 Nov 2026", "InfoDot sign-in") instead of
     * the machine values the service stores.
     *
     * @return list<array<string, mixed>>
     */
    public static function ladder(PulseEntity $entity): array
    {
        $sources = [
            'infodot_redeem_teams' => 'InfoDot sign-in',
            'infodot_redeem_verification' => 'InfoDot',
            'pulse_account' => 'your Pulse account',
            'business_claim' => 'what the business told Pulse',
            'pulse_conversations' => 'lines on Pulse',
            'pulse_records' => 'Pulse records',
        ];

        return array_map(function (array $r) use ($sources) {
            $r['at'] = ! empty($r['at']) ? Carbon::parse($r['at'])->format('j M Y') : null;
            $r['source'] = $sources[$r['source'] ?? ''] ?? ($r['source'] ?? null);

            return $r;
        }, app(VerificationService::class)->ladder($entity));
    }

    /** "usually within 3 hours", from a median in seconds. */
    public static function duration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        return match (true) {
            $seconds < 3600 => 'under an hour',
            $seconds < 86400 => 'about '.max(1, (int) round($seconds / 3600)).' '.(round($seconds / 3600) < 1.5 ? 'hour' : 'hours'),
            default => 'about '.max(1, (int) round($seconds / 86400)).' '.(round($seconds / 86400) < 1.5 ? 'day' : 'days'),
        };
    }

    /** "R2 500" style for the price block is done by <x-pulse.price>; this turns cents into the rand the component wants. */
    public static function rand(?int $cents): ?float
    {
        return $cents === null ? null : $cents / 100;
    }

    public static function priceNote(PulseOffer $offer): ?string
    {
        return match ($offer->price_mode) {
            'from' => 'From this price',
            'quote' => 'Price on a quote',
            'free' => 'Free',
            'contact' => 'Ask for the price',
            default => null,
        };
    }

    /** "Closes on 30 Nov 2026", or null when the role has no end date. */
    public static function closing(PulseRole $role): ?string
    {
        return $role->closes_at ? 'Closes on '.Carbon::parse($role->closes_at)->format('j M Y') : null;
    }

    /** @return array<string, string> */
    public static function employment(): array
    {
        return ['full_time' => 'Full time', 'part_time' => 'Part time', 'contract' => 'Contract', 'internship' => 'Internship', 'freelance' => 'Freelance'];
    }

    /** @return array<string, string> */
    public static function workModes(): array
    {
        return ['onsite' => 'On site', 'hybrid' => 'Hybrid', 'remote' => 'Remote'];
    }

    /**
     * What to do next on this page, from real state only. Each item: text, href. An empty list means nothing is waiting.
     *
     * @return list<array{text: string, href: string}>
     */
    public static function todos(PulseEntity $e, ?User $viewer = null): array
    {
        $to = fn (string $route) => route($route, ['entity' => $e->slug]);
        $todos = [];

        if ($e->status === 'suspended') {
            $todos[] = ['text' => 'A moderator suspended this page. Contact support.', 'href' => $to('entities.manage')];
        } elseif ($e->status === 'draft') {
            $todos[] = ['text' => 'Publish the page. It is a draft, so only your team can see it.', 'href' => $to('entities.manage.page')];
        } elseif ($e->status !== 'live') {
            $todos[] = ['text' => 'Put the page on the floor. Right now only your team can see it.', 'href' => $to('entities.manage.page')];
        } elseif ($e->moderation !== 'approved') {
            $todos[] = ['text' => 'The page is waiting for a human. Until a person has looked, the public cannot see it.', 'href' => $to('entities.manage.page')];
        }

        if ($viewer !== null && Access::canRespondFor($viewer, $e)) {
            $waiting = PulseConversation::query()->where('entity_id', $e->id)->where('status', PulseConversation::REQUESTED)->whereNull('ignored_at')->count();

            if ($waiting > 0) {
                $todos[] = ['text' => $waiting === 1 ? '1 request is waiting for an answer.' : $waiting.' requests are waiting for an answer.', 'href' => $to('entities.manage.lines')];
            }
        }

        $held = $e->offers()->where('status', 'live')->where('moderation', '!=', 'approved')->count()
            + $e->roles()->where('status', 'live')->where('moderation', '!=', 'approved')->count();

        if ($held > 0) {
            $todos[] = ['text' => $held === 1 ? '1 listing is waiting for a human.' : $held.' listings are waiting for a human.', 'href' => $to($e->offers()->where('status', 'live')->where('moderation', '!=', 'approved')->exists() ? 'entities.manage.offers' : 'entities.manage.roles')];
        }

        if ($e->is_seller) {
            $drafts = $e->offers()->where('status', 'draft')->count();

            if ($e->offers()->where('status', '!=', 'archived')->count() === 0) {
                $todos[] = ['text' => 'Write your first offer. People find you by what you sell.', 'href' => $to('entities.manage.offers')];
            } elseif ($drafts > 0) {
                $todos[] = ['text' => $drafts === 1 ? '1 draft offer is not published.' : $drafts.' draft offers are not published.', 'href' => $to('entities.manage.offers')];
            }
        }

        if ($e->is_employer && $e->roles()->whereIn('status', ['draft', 'live', 'paused'])->count() === 0 && $e->hiring_signal === 'none') {
            $todos[] = ['text' => 'Add a role, or turn on the hiring signal to meet people before you advertise.', 'href' => $to('entities.manage.roles')];
        }

        if (blank($e->about)) {
            $todos[] = ['text' => 'Write an about. It is the first thing people read.', 'href' => $to('entities.manage.page')];
        }

        if ($e->logo_media_id === null) {
            $todos[] = ['text' => 'Add a logo so people recognise you.', 'href' => $to('entities.manage.page')];
        }

        if (blank($e->infodot_business_ref)) {
            $todos[] = ['text' => 'Link your InfoDot company. It adds a checked line to your ladder.', 'href' => $to('entities.manage.verify')];
        }

        return $todos;
    }
}
