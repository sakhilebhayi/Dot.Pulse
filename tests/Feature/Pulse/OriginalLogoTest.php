<?php

namespace Tests\Feature\Pulse;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Facades\Blade;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Owner rule: an original logo is never redrawn, recoloured, restyled, replaced or generated; only presentation may
 * change. public/images/logo.png is the original Dot.Pulse artwork. These tests pin its bytes, forbid the invented
 * drawings that used to stand in for it, and check that every layout shows the real file with alt text.
 */
class OriginalLogoTest extends TestCase
{
    use RefreshDatabase;

    /** git hash-object public/images/logo.png, identical at its first commit (8822cfc) and today. */
    private const LOGO_BLOB = 'db2532cb1f647f42c7338bbd66105c9f2bdb711e';

    private function logoImg(string $html): void
    {
        $this->assertMatchesRegularExpression('#<img[^>]+src="[^"]*/images/logo\.png"[^>]+alt="Dot\.Pulse"[^>]*>#', $html);
        $this->assertMatchesRegularExpression('#<img[^>]+logo\.png[^>]+width="\d+"[^>]+height="\d+"#', $html);
    }

    public function test_the_original_logo_file_is_unchanged(): void
    {
        $bytes = file_get_contents(public_path('images/logo.png'));
        $this->assertSame(self::LOGO_BLOB, sha1('blob '.strlen($bytes)."\0".$bytes));
    }

    public function test_the_invented_drawings_are_gone(): void
    {
        foreach (['public/favicon.svg', 'public/images/logo.svg', 'docs/logo.svg', 'resources/views/components/pulse/mark.blade.php'] as $gone) {
            $this->assertFileDoesNotExist(base_path($gone));
        }
        foreach (['favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png'] as $icon) {
            $this->assertFileExists(public_path($icon));
        }
    }

    public function test_no_view_script_or_style_carries_the_old_mark_or_the_purple_badge(): void
    {
        $files = Finder::create()->files()->in([resource_path('views'), resource_path('css'), resource_path('js')])->name(['*.php', '*.css', '*.js']);
        foreach ($files as $file) {
            $text = $file->getContents();
            $this->assertStringNotContainsString('pulse.mark', $text, $file->getRelativePathname());
            $this->assertStringNotContainsString('pulse/mark', $text, $file->getRelativePathname());
            $this->assertStringNotContainsString('Community Social Platform', $text, $file->getRelativePathname());
            $this->assertStringNotContainsString('logo.svg', $text, $file->getRelativePathname());
            $this->assertStringNotContainsString('favicon.svg', $text, $file->getRelativePathname());
            $this->assertStringNotContainsString('--c-ink-fixed)" stroke-width="3"', $text, $file->getRelativePathname());
        }
    }

    public function test_the_public_layout_shows_the_real_logo(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->logoImg($html);
        $this->assertStringContainsString('favicon-32x32.png', $html);
        $this->assertStringContainsString('apple-touch-icon.png', $html);
        $this->assertStringNotContainsString('favicon.svg', $html);
    }

    public function test_the_guest_layout_shows_the_real_logo(): void
    {
        $this->logoImg($this->get('/login')->assertOk()->getContent());
    }

    public function test_the_error_layout_shows_the_real_logo(): void
    {
        $this->logoImg($this->get('/no-such-page-here')->assertNotFound()->getContent());
    }

    public function test_the_app_layout_shows_the_real_logo(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $html = $this->actingAs($user)->followingRedirects()->get('/floor')->assertOk()->getContent();
        $this->logoImg($html);
    }

    public function test_the_jetstream_logo_components_render_the_real_file(): void
    {
        foreach (['<x-application-logo />', '<x-application-mark class="block h-9 w-auto" />', '<x-authentication-card-logo />', '<x-pulse.logo />'] as $blade) {
            $this->logoImg(Blade::render($blade));
        }
    }

    public function test_the_mail_layout_and_the_digest_show_the_real_logo(): void
    {
        $dir = sys_get_temp_dir().'/pulse-logo-probe-'.bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir.'/probe.blade.php', "@component('mail::message')\nHello\n@endcomponent\n");
        view()->addLocation($dir);
        $html = (string) app(Markdown::class)->render('probe');
        unlink($dir.'/probe.blade.php');
        rmdir($dir);
        $this->logoImg($html);
        $this->assertStringNotContainsString('class="node"', $html);
        $this->assertStringContainsString('logo.png', file_get_contents(resource_path('views/mail/pulse/digest.blade.php')));
    }
}
