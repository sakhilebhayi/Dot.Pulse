<?php

namespace Tests\Feature\Pulse;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPulseFixtures;
use Tests\TestCase;

class ShellHeadTest extends TestCase
{
    use BuildsPulseFixtures;
    use RefreshDatabase;

    public function test_the_head_loads_no_icon_font_from_a_third_party(): void
    {
        $html = $this->actingAs($this->pulseUser())->get('/floor')->assertOk()->getContent();

        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('material-symbols', strtolower($html));
        $this->assertStringNotContainsString('Material Symbols', $html);
    }

    public function test_no_stylesheet_carries_the_icon_font_class(): void
    {
        foreach (['legacy.css', 'tokens.css'] as $file) {
            $this->assertStringNotContainsString('material-symbols', strtolower((string) file_get_contents(resource_path('css/pulse/'.$file))), $file);
            $this->assertStringNotContainsString('Material Symbols', (string) file_get_contents(resource_path('css/pulse/'.$file)), $file);
        }
    }
}
