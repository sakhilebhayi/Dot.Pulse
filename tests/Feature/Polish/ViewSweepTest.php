<?php

namespace Tests\Feature\Polish;

use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseNeed;
use App\Models\PulseSignal;
use App\Models\User;
use Database\Seeders\PulseDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Renders the main pages from the demo world, as the people who use them, and fails on anything that reads like an
 * internal: snake_case codes, class or command names, ISO timestamps, "null", "NaN", "0 minutes", "1 days".
 */
class ViewSweepTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, string> label => regex that must NOT match the visible text */
    private const LEAKS = [
        'snake_case word' => '/\b[a-z]{2,}(?:_[a-z0-9]+)+\b/',
        'ISO timestamp' => '/\b\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/',
        'double colon' => '/\w::\w/',
        'artisan command' => '/\bpulse:[a-z]/',
        'null word' => '/\b(null|NaN|undefined|Array|Object)\b/',
        'zero minutes' => '/\b0 (minutes|hours|days|seconds)\b/',
        'bad plural' => '/\b1 (minutes|hours|days|seconds|requests|results|messages|reasons|people|businesses|offers|roles|lines|reviews|checks)\b/',
        'template residue' => '/\{\{|\}\}|@php|@if\b|wire:/',
        'seed text' => '/demo-seed|fixture/i',
        'raw date object' => '/\bCarbon\b/',
    ];

    /** @var array<string, string> */
    private array $problems = [];

    private function visibleText(string $html): string
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        libxml_clear_errors();
        $xp = new \DOMXPath($dom);

        foreach ($xp->query('//script|//style|//svg|//template') as $node) {
            $node->parentNode?->removeChild($node);
        }

        // Attribute text people can read: titles, aria-labels, placeholders.
        $attrs = [];
        foreach ($xp->query('//*[@title or @aria-label or @placeholder or @alt]') as $node) {
            foreach (['title', 'aria-label', 'placeholder', 'alt'] as $a) {
                if ($node->hasAttribute($a)) {
                    $attrs[] = $node->getAttribute($a);
                }
            }
        }

        return preg_replace('/\s+/', ' ', $dom->textContent.' '.implode(' ', $attrs)) ?? '';
    }

    private function assertClean(string $path, User $as): void
    {
        // A component that aborts while mounting (a 404 for someone else's line) leaves Livewire's redirector bound in a
        // long-lived test process. A real request starts clean, so the sweep does too.
        $stack = &\Livewire\Features\SupportRedirects\SupportRedirects::$redirectorCacheStack;
        if ($stack !== []) {
            $this->app->instance('redirect', $stack[0]);
            $stack = [];
        }

        $response = $this->actingAs($as)->get($path);

        if (in_array($response->getStatusCode(), [301, 302, 403, 404], true)) {
            return; // not a page this person can open: the sweep reads pages, it does not test access
        }

        if ($response->getStatusCode() !== 200) {
            $why = $response->exception ? get_class($response->exception).': '.$response->exception->getMessage().' @ '.basename($response->exception->getFile()).':'.$response->exception->getLine() : '';
            $this->problems['status '.$response->getStatusCode().' '.$why] = "{$path} as ".strstr($as->email, '@', true);

            return;
        }

        $text = $this->visibleText($response->getContent());

        foreach (self::LEAKS as $label => $pattern) {
            if (preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
                $at = max(0, $m[0][1] - 60);
                $this->problems[$label.' | '.trim(substr($text, $at, 120))] = "{$path} as ".strstr($as->email, '@', true);
            }
        }
    }

    public function test_the_main_pages_never_show_internals(): void
    {
        $this->artisan('pulse:demo-seed')->assertSuccessful();

        $people = User::where('email', 'like', '%@'.PulseDemoSeeder::DOMAIN)->orderBy('id')->get();
        $this->assertGreaterThanOrEqual(12, $people->count());

        $entities = PulseEntity::query()->with(['offers', 'roles'])->get();
        $needs = PulseNeed::query()->get();
        $signals = PulseSignal::query()->where('status', 'live')->orderBy('id')->limit(40)->get();
        $lines = PulseConversation::query()->get();

        $fixed = ['/floor', '/trade', '/work', '/local', '/learn', '/search?q=plumbing', '/search?q=bookkeeping', '/saved', '/door', '/lines', '/lines?tab=open',
            '/me/profile', '/me/discoverability', '/me/settings', '/me/notifications', '/me/privacy', '/community', '/business/new', '/need/new', '/user/profile', '/me/account'];

        foreach ($people as $user) {
            foreach ($fixed as $path) {
                $this->assertClean($path, $user);
            }
        }

        // Everyone sees the public side of every business and the pages hanging off it.
        $viewer = $people->first();
        foreach ($entities as $entity) {
            $this->assertClean('/b/'.$entity->slug, $viewer);

            foreach ($entity->offers as $offer) {
                $this->assertClean('/b/'.$entity->slug.'/o/'.$offer->slug, $viewer);
            }
            foreach ($entity->roles as $role) {
                $this->assertClean('/b/'.$entity->slug.'/r/'.$role->slug, $viewer);
            }

            // Its own people see the manage tabs.
            $owner = User::find($entity->members()->where('role', 'owner')->value('user_id'));
            foreach (['', '/page', '/offers', '/roles', '/team', '/lines', '/verify'] as $tab) {
                $this->assertClean('/b/'.$entity->slug.'/manage'.$tab, $owner);
            }
        }

        foreach ($signals as $signal) {
            $this->assertClean('/s/'.$signal->id, $viewer);
        }
        foreach ($needs as $need) {
            $this->assertClean('/need/'.$need->id, User::find($need->user_id));
        }
        foreach ($lines as $line) {
            foreach ([$line->initiator_id, $line->recipient_id] as $uid) {
                if ($uid) {
                    $this->assertClean('/lines/'.$line->id, User::find($uid));
                }
            }
            if ($line->quote_group) {
                $this->assertClean('/lines/quotes/'.$line->quote_group, User::find($line->initiator_id));
            }
        }

        // The operator and the moderator desks.
        $maple = User::where('email', PulseDemoSeeder::email('maple'))->first();
        $this->assertClean('/operator', $maple);
        $this->assertClean('/moderation', $maple);

        $this->assertSame([], $this->problems, "Internals shown to people:\n".collect($this->problems)->map(fn ($where, $what) => "  {$what}  <- {$where}")->implode("\n"));
    }
}
