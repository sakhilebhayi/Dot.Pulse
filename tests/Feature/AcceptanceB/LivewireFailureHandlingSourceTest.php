<?php

namespace Tests\Feature\AcceptanceB;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Criterion 19 (suite B). The behaviour is proven in a real browser by tests/e2e/pulse-b/specs/s19-errors.mjs (offline,
 * 419, a server exception inside a component, a locked form after a failure). A PHP test cannot run the browser, so this
 * guards the source: the shell must keep a handler for each failure class and must never fall back to the library's
 * confirm box or raw error overlay.
 */
class LivewireFailureHandlingSourceTest extends TestCase
{
    public function test_the_shell_handles_every_livewire_failure_class_in_the_floors_voice(): void
    {
        $js = File::get(resource_path('js/pulse/shell.js'));

        foreach (['status === 419', 'status === 429', 'status === 401', 'status === 503', 'status >= 500', 'preventDefault()', 'clearStuckLoading', 'No connection', 'session timed out'] as $needle) {
            $this->assertStringContainsString($needle, $js, "shell.js lost its handling of: {$needle}");
        }

        $this->assertStringNotContainsString('confirm(', $js, 'a native confirm box is not an error message');
    }

    public function test_realtime_being_unconfigured_is_not_a_console_error(): void
    {
        $js = File::get(resource_path('js/pulse-realtime.js'));

        $this->assertStringNotContainsString("console.error('Laravel Echo not found", $js);
    }

    public function test_error_pages_exist_for_every_status_the_brief_names_and_each_has_a_way_home(): void
    {
        foreach ([401, 403, 404, 419, 429, 500, 503] as $code) {
            $view = File::get(resource_path("views/errors/{$code}.blade.php"));
            $this->assertStringContainsString("@section('code', '{$code}')", $view);
        }

        $layout = File::get(resource_path('views/errors/layout.blade.php'));
        $this->assertStringContainsString('Back to Pulse', $layout);
        $this->assertStringContainsString('href="/"', $layout);
    }
}
