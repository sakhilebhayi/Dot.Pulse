<?php

namespace Tests\Feature\Security;

use App\Livewire\Pulse\Lines\QuoteRequest as QuoteRequestSheet;
use App\Models\PulseDoor;
use App\Models\PulseMedia;
use App\Models\PulseSignal;
use App\Models\User;
use App\Notifications\PulseNotice;
use App\Services\Pulse\Discovery\SearchService;
use App\Services\Pulse\Entities\MediaService;
use App\Services\Pulse\Lines\LineService;
use App\Services\Pulse\Lines\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Output encoding, SQL, uploads, redirects, CSRF scope and what goes into email. */
class InjectionAndOutputTest extends TestCase
{
    use RefreshDatabase;
    use SecurityWorld;

    private const XSS = '<script>alert("xss1")</script><img src=x onerror=alert("xss2")><svg onload=alert("xss3")>"\'`';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['pulse.entities.rules_class' => 'Tests\\Absent\\Rules']);
        $this->buildWorld();
    }

    private function assertNoLiveMarkup(string $html, string $where): void
    {
        foreach (['<script>alert("xss1")', '<img src=x', '<svg onload'] as $needle) {
            // The Livewire snapshot attribute holds the payload entity-encoded; only an unencoded tag is live.
            $this->assertStringNotContainsString($needle, $html, "{$where} renders raw markup: {$needle}");
        }
    }

    public function test_user_text_is_escaped_on_every_page_that_shows_it(): void
    {
        $x = self::XSS;
        $seller = $this->w('seller');
        $seller->forceFill(['tagline' => 'T'.$x, 'about' => 'A'.$x, 'industry' => 'I'.$x])->save();
        $this->w('offer')->forceFill(['title' => 'O'.$x, 'summary' => 'S'.$x, 'description' => 'D'.$x])->save();
        $this->w('role')->forceFill(['title' => 'R'.$x, 'description' => 'RD'.$x, 'pay_text' => 'P'.$x])->save();
        $this->w('need')->forceFill(['title' => 'N'.$x, 'details' => 'ND'.$x, 'budget_text' => 'B'.$x])->save();
        $line = $this->w('line');
        DB::table('pulse_conversations')->where('id', $line->id)->update(['card' => json_encode(['what' => 'C'.$x, 'where' => 'W'.$x]), 'context_title' => 'CT'.$x]);
        app(LineService::class)->send($this->w('stranger'), $line->fresh(), 'M'.$x);
        PulseDoor::query()->create(['owner_id' => $this->w('needOwner')->id, 'purpose' => 'DOOR'.$x, 'length' => 'day', 'status' => 'open', 'opens_at' => now(), 'closes_at' => now()->addDay(), 'knock_cap' => 5]);
        $seller->update(['name' => 'Name'.$x]);

        $pages = [
            'guest' => ['/b/'.$seller->slug],
            'stranger' => ['/b/'.$seller->slug, '/b/'.$seller->slug.'/o/'.$this->w('offer')->slug, '/b/'.$seller->slug.'/r/'.$this->w('role')->slug, '/need/'.$this->w('need')->id, '/lines/'.$line->id, '/lines', '/floor', '/trade', '/search?q=Name', '/me/notifications', '/community'],
            'sellerOwner' => ['/b/'.$seller->slug.'/manage', '/b/'.$seller->slug.'/manage/lines', '/b/'.$seller->slug.'/manage/offers', '/b/'.$seller->slug.'/manage/roles', '/b/'.$seller->slug.'/manage/team', '/lines/'.$line->id, '/lines'],
            'needOwner' => ['/need/'.$this->w('need')->id, '/need/'.$this->w('need')->id.'/edit'],
            'moderator' => ['/moderation', '/operator'],
        ];

        foreach ($pages as $who => $urls) {
            foreach ($urls as $url) {
                $this->app['auth']->forgetGuards();
                $response = $who === 'guest' ? $this->get($url) : $this->actingAs($this->w($who)->fresh())->get($url);
                $this->assertContains($response->getStatusCode(), [200, 302, 404], "{$who} {$url} -> ".$response->getStatusCode());
                $this->assertNoLiveMarkup($response->getContent(), "{$who} GET {$url}");
            }
        }
    }

    public function test_a_person_cannot_put_markup_in_a_profile_or_a_page_form_and_get_it_back_live(): void
    {
        $x = self::XSS;
        $user = $this->w('stranger');

        $component = Livewire::actingAs($user)->test(\App\Livewire\Pulse\Me\ProfileEditor::class)->set('headline', 'H'.$x)->set('bio', 'B'.$x)->call('save');
        $this->assertNoLiveMarkup($component->html(), 'profile editor');
        $this->assertNoLiveMarkup($this->actingAs($user)->get('/u/'.$user->id)->getContent(), 'public profile');
        $this->assertNoLiveMarkup($this->actingAs($this->w('needOwner'))->get('/u/'.$user->id)->getContent(), 'public profile seen by another');
    }

    public function test_mail_built_from_what_strangers_wrote_cannot_carry_a_link_or_a_second_header(): void
    {
        $recipient = $this->w('sellerOwner');
        $payload = [
            'type' => 'line_request',
            'category' => 'lines',
            "title" => "Thandi sent an enquiry\r\nBcc: attacker@example.test",
            'body' => 'About: [Verify your account](//evil.example/login) and ![x](//evil.example/p.png) <a href="//evil.example">click</a>',
            'url' => 'http://pulse.infodot.app/lines/1',
        ];

        $mail = (new PulseNotice($payload, ['mail']))->toMail($recipient);
        $rendered = (string) $mail->render();

        $this->assertStringNotContainsString("\r", $mail->subject);
        $this->assertStringNotContainsString("\n", $mail->subject);
        $this->assertDoesNotMatchRegularExpression('/<img\b[^>]*evil/', $rendered);
        preg_match_all('/<a [^>]*href="([^"]*)"/', $rendered, $links);
        foreach ($links[1] as $href) {
            $this->assertStringStartsWith('http://pulse.infodot.app', $href, 'a link that is not the platform\'s own: '.$href);
        }

        // The message as actually built has one subject header and no injected Bcc.
        $email = (new \Symfony\Component\Mime\Email)->subject($mail->subject)->from('a@example.test')->to('b@example.test')->text('x');
        $this->assertFalse($email->getHeaders()->has('Bcc'));
    }

    public function test_notification_links_cannot_leave_the_site(): void
    {
        $list = new class
        {
            use \App\Livewire\Pulse\Me\Concerns\PresentsNotifications;

            public function check(?string $url): ?string
            {
                return $this->safeUrl($url);
            }
        };

        $this->assertSame('/lines/3', $list->check('/lines/3'));
        foreach (['//evil.example', '/\\evil.example', "/\t/evil.example", 'https://evil.example/x', 'javascript:alert(1)', "/\r\nLocation: //evil.example", '\\\\evil.example'] as $bad) {
            $this->assertNull($list->check($bad), 'followed: '.json_encode($bad));
        }
    }

    public function test_the_sign_in_redirect_only_ever_names_a_path_on_this_site(): void
    {
        $post = fn (string $redirect) => $this->withHeaders(['Origin' => 'https://infodot.co.za'])->post('/auth/ecosystem', ['token' => 'nope', 'redirect' => $redirect]);

        // A refused sign in lands on the plain login page whatever the redirect says.
        foreach (['//evil.example', 'https://evil.example', '/\\evil.example', '/%2f%2fevil.example', '/../../evil'] as $redirect) {
            $post($redirect)->assertRedirect('/login');
        }
    }

    public function test_sign_in_post_is_the_only_unsafe_web_route_outside_csrf_and_the_unsubscribe_link_is_signed(): void
    {
        $exempt = [];

        foreach (Route::getRoutes() as $route) {
            if (array_intersect($route->methods(), ['POST', 'PUT', 'PATCH', 'DELETE']) === []) {
                continue;
            }

            $excluded = array_map('strval', $route->excludedMiddleware());
            if ($excluded !== []) {
                $exempt[] = $route->uri();
                if ($route->uri() === 'pulse/digest/unsubscribe/{user}') {
                    $this->assertContains('signed', $route->gatherMiddleware());
                }
            }
        }

        sort($exempt);
        // broadcasting/auth is the framework's own channel authorisation route (it checks the session user per channel).
        $this->assertSame(['auth/ecosystem', 'broadcasting/auth', 'pulse/digest/unsubscribe/{user}'], $exempt);
    }

    public function test_a_search_for_wildcards_finds_the_literal_text_only(): void
    {
        $this->offer($this->w('seller'), ['title' => 'Plain geyser fix']);
        $this->offer($this->w('seller'), ['title' => 'Fifty% off geysers']);
        $me = $this->w('stranger');
        $search = app(SearchService::class);

        foreach (['%', '_', '\\', '%_%'] as $q) {
            $titles = collect($search->search($me, $q)['results'])->map(fn ($r) => $r['beat']->title ?? ($r['entity']['name'] ?? ''))->all();
            $this->assertLessThanOrEqual(1, count($titles), "[{$q}] matched more than the literal text");
            foreach ($titles as $t) {
                $this->assertStringContainsString(trim($q), $t, "[{$q}] matched [{$t}]");
            }
        }

        // Quotes and SQL fragments are only words.
        foreach (["' OR 1=1 --", '"; DROP TABLE users; --', "x') UNION SELECT password FROM users --"] as $q) {
            $search->search($me, $q);
        }

        $this->assertSame(1, DB::table('users')->where('id', $me->id)->count());
    }

    public function test_the_quote_provider_picker_treats_percent_and_underscore_literally(): void
    {
        $need = $this->w('need');
        $user = $this->w('needOwner');
        $a = $this->page($this->person(), ['name' => 'Alpha Plumbing', 'is_seller' => true]);
        $b = $this->page($this->person(), ['name' => 'Beta Sparks', 'is_seller' => true]);
        foreach ([$a, $b] as $e) {
            $e->forceFill(['moderation' => 'approved'])->save();
            $this->offer($e, ['accepts_quotes' => true]);
        }

        $html = Livewire::actingAs($user)->test(QuoteRequestSheet::class, ['needId' => $need->id])->call('openSheet')->set('search', '%')->html();

        $this->assertStringNotContainsString('Alpha Plumbing', $html);
        $this->assertStringNotContainsString('Beta Sparks', $html);
    }

    // ------------------------------------------------------------------ uploads

    private function png(int $w = 64, int $h = 64): string
    {
        $im = imagecreatetruecolor($w, $h);
        ob_start();
        imagepng($im);
        imagedestroy($im);

        return (string) ob_get_clean();
    }

    private function upload(string $bytes, string $name = 'a.png', string $mime = 'image/png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }

    public function test_only_real_raster_images_are_accepted_whatever_they_are_called(): void
    {
        $user = $this->w('stranger');
        $media = app(MediaService::class);

        $bad = [
            'svg named png' => $this->upload('<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"><script>alert(1)</script></svg>', 'a.png', 'image/png'),
            'svg' => $this->upload('<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"/>', 'a.svg', 'image/svg+xml'),
            'html as jpg' => $this->upload('<html><script>alert(1)</script></html>', 'a.jpg', 'image/jpeg'),
            'php as png' => $this->upload('<?php system($_GET["c"]); ?>', 'shell.php.png', 'image/png'),
            'gif' => $this->upload("GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;", 'a.gif', 'image/gif'),
            'empty' => $this->upload('', 'a.png'),
            'truncated png' => $this->upload(substr($this->png(), 0, 40), 'a.png'),
        ];

        foreach ($bad as $label => $file) {
            try {
                $media->store($user, $file, 'avatar');
                $this->fail("{$label} was accepted");
            } catch (ValidationException) {
                $this->assertTrue(true);
            }
        }

        $this->assertSame(0, PulseMedia::query()->count());
    }

    public function test_a_script_hidden_after_a_valid_image_is_gone_after_re_encoding_and_metadata_is_stripped(): void
    {
        $user = $this->w('stranger');
        $marker = '<?php echo "pwned-marker-9f3a"; ?><script>alert("pwned-marker-9f3a")</script>';
        $comment = $this->png().$marker;

        $id = app(MediaService::class)->store($user, $this->upload($comment), 'avatar');

        foreach (PulseMedia::query()->find($id)->variants as $variant) {
            $stored = Storage::disk('local')->get($variant['path']);
            $this->assertStringNotContainsString('pwned-marker-9f3a', $stored);
            $this->assertStringNotContainsString('Exif', substr($stored, 0, 4096));
        }

        // The route serves it as an image the browser cannot run: nosniff and a sandboxing policy.
        $response = $this->actingAs($user)->get(app(MediaService::class)->url($id, 'medium'));
        $response->assertOk();
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringStartsWith('image/', (string) $response->headers->get('Content-Type'));
    }

    public function test_an_image_claiming_enormous_dimensions_is_refused_before_it_is_decoded(): void
    {
        // A valid PNG header declaring 40000 x 40000 pixels (a decompression bomb): refused on the header alone.
        $bomb = $this->png();
        $bomb = substr($bomb, 0, 16).pack('N', 40000).pack('N', 40000).substr($bomb, 24);

        $before = memory_get_peak_usage(true);
        try {
            app(MediaService::class)->store($this->w('stranger'), $this->upload($bomb), 'avatar');
            $this->fail('the bomb was accepted');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->assertLessThan(64 * 1024 * 1024, memory_get_peak_usage(true) - $before, 'refusing it must not allocate the image');
    }

    public function test_a_stored_path_cannot_be_traversed_through_the_route(): void
    {
        $this->actingAs($this->w('stranger'));
        $this->get('/media/1/..%2f..%2f.env')->assertNotFound();
        $this->get('/media/1/large/../../x')->assertNotFound();
        $this->get('/media/abc')->assertNotFound();
        $this->get('/media/1.php')->assertNotFound();
        $this->get('/storage/pulse/media/anything.webp')->assertStatus(403);
    }

    public function test_a_removed_member_can_no_longer_read_or_add_photos_on_the_pages_lines(): void
    {
        $line = $this->w('line');
        $responder = $this->w('sellerResponder');

        $id = app(MediaService::class)->store($this->w('stranger'), $this->upload($this->png()), 'message', null, $line->id);
        app(\App\Models\PulseMessage::class)->newQuery()->where('pulse_conversation_id', $line->id)->first()?->update(['media_id' => $id]);
        $this->actingAs($responder)->get(app(MediaService::class)->url($id))->assertOk();

        DB::table('pulse_entity_members')->where('user_id', $responder->id)->delete();

        $this->actingAs($responder->fresh())->get(app(MediaService::class)->url($id))->assertNotFound();
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(MediaService::class)->store($responder->fresh(), $this->upload($this->png()), 'message', null, $line->id);
    }
}
