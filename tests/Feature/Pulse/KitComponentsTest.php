<?php

namespace Tests\Feature\Pulse;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

/**
 * The Floor's component kit (resources/views/components/pulse). Every component renders with sample props, and the
 * rules that make the kit honest are asserted: the sync meter draws only from real reasons, the ladder says each state in
 * words, a price with no amount says so, controls are wired for assistive technology.
 */
class KitComponentsTest extends TestCase
{
    private function render(string $blade): string
    {
        return Blade::render($blade);
    }

    private function reasons(): string
    {
        return "[['code' => 'need_offer', 'text' => 'You need bookkeeping, they offer bookkeeping', 'weight' => 40], ['code' => 'place_region', 'text' => 'Both in Gauteng', 'weight' => 20]]";
    }

    public function test_every_kit_component_renders_with_sample_props(): void
    {
        $r = $this->reasons();
        $samples = [
            'button' => '<x-pulse.button>Knock</x-pulse.button>',
            'button link' => '<x-pulse.button variant="signal" href="/x">Go</x-pulse.button>',
            'mode-dial' => '<x-pulse.mode-dial selected="work" />',
            'mode-dial livewire' => '<x-pulse.mode-dial selected="work" livewire="setMode" />',
            'beat' => '<x-pulse.beat kind="offer" title="A heading">Body<x-slot name="actions"><x-pulse.button size="sm">Knock</x-pulse.button></x-slot></x-pulse.beat>',
            'sync-meter' => "<x-pulse.sync-meter :reasons=\"$r\" />",
            'ladder' => '<x-pulse.ladder :rungs="[[\'label\' => \'InfoDot account\', \'state\' => \'checked\'], [\'label\' => \'Website\', \'state\' => \'claimed\']]" summary />',
            'avatar' => '<x-pulse.avatar name="Thandi Mokoena" />',
            'kind-word' => '<x-pulse.kind-word kind="need" />',
            'field' => '<x-pulse.field name="what" label="What" hint="One sentence" />',
            'textarea' => '<x-pulse.textarea name="d" label="Details" />',
            'select' => '<x-pulse.select name="b" label="Band" :options="[\'a\' => \'A\']" />',
            'chips' => '<x-pulse.chips name="t" legend="Topics" :options="[\'a\' => \'A\', \'b\' => \'B\']" :selected="[\'a\']" />',
            'toggle' => '<x-pulse.toggle name="digest" label="Send a digest" />',
            'empty' => '<x-pulse.empty title="Nothing yet." text="Say what you need." action="Say" href="/n" />',
            'stat' => '<x-pulse.stat label="Replies" value="12" />',
            'toasts' => '<x-pulse.toasts />',
            'drawer' => '<x-pulse.drawer id="d1" title="Filters">Body</x-pulse.drawer>',
            'tabs' => '<x-pulse.tabs :tabs="[\'a\' => \'A\', \'b\' => \'B\']" active="a"><div data-pk-panel="a">A</div></x-pulse.tabs>',
            'tabs links' => '<x-pulse.tabs :tabs="[\'a\' => [\'label\' => \'A\', \'href\' => \'/a\']]" active="a" />',
            'pill' => '<x-pulse.pill tone="good">Checked</x-pulse.pill>',
            'price' => '<x-pulse.price :amount="1200" unit="per month" />',
            'place-label' => '<x-pulse.place-label name="Soweto, Gauteng" />',
            'confirm' => '<x-pulse.confirm id="c1" title="Sure?" />',
            'row' => '<x-pulse.row title="A line" meta="2 h ago" />',
            'icon' => '<x-pulse.icon name="floor" />',
            'mark' => '<x-pulse.mark />',
            'brand' => '<x-pulse.brand href="/" />',
        ];

        foreach ($samples as $label => $blade) {
            $html = $this->render($blade);
            $this->assertNotSame('', trim($html), "$label rendered nothing");
        }
    }

    public function test_the_sync_meter_draws_one_node_and_one_sentence_per_reason(): void
    {
        $html = $this->render('<x-pulse.sync-meter :reasons="'.$this->reasons().'" />');

        $this->assertSame(2, substr_count($html, 'class="pk-meter__node"'));
        $this->assertStringContainsString('You need bookkeeping, they offer bookkeeping', $html);
        $this->assertStringContainsString('Both in Gauteng', $html);
        $this->assertStringContainsString('data-pk-meter', $html);
        $this->assertStringContainsString('role="img"', $html);
        $this->assertStringContainsString('meet at 2 reasons', $html);
    }

    public function test_the_sync_meter_renders_nothing_without_reasons(): void
    {
        $this->assertSame('', trim($this->render('<x-pulse.sync-meter :reasons="[]" />')));
        // A reason with no sentence is not a reason.
        $this->assertSame('', trim($this->render('<x-pulse.sync-meter :reasons="[[\'code\' => \'topic\', \'text\' => \'  \']]" />')));
    }

    public function test_the_sync_meter_offers_a_remove_button_only_when_asked(): void
    {
        $plain = $this->render('<x-pulse.sync-meter :reasons="'.$this->reasons().'" />');
        $this->assertStringNotContainsString('wire:click', $plain);

        $wired = $this->render('<x-pulse.sync-meter remove="removeReason" :reasons="'.$this->reasons().'" />');
        $this->assertStringContainsString("wire:click=\"removeReason('need_offer')\"", $wired);
    }

    public function test_the_ladder_says_every_state_in_words_and_renders_nothing_when_empty(): void
    {
        $html = $this->render('<x-pulse.ladder :rungs="[[\'label\' => \'A\', \'state\' => \'checked\', \'source\' => \'InfoDot\'], [\'label\' => \'B\', \'state\' => \'claimed\'], [\'label\' => \'C\', \'state\' => \'missing\']]" summary />');

        $this->assertStringContainsString('Checked', $html);
        $this->assertStringContainsString('Claimed by them, not checked', $html);
        $this->assertStringContainsString('Not shown', $html);
        $this->assertStringContainsString('Source: InfoDot', $html);
        $this->assertStringContainsString('1 of 3 checked', $html);

        $this->assertSame('', trim($this->render('<x-pulse.ladder :rungs="[]" />')));
    }

    public function test_a_price_without_an_amount_says_on_request_and_ranges_render(): void
    {
        $this->assertStringContainsString('On request', $this->render('<x-pulse.price :amount="null" />'));
        $this->assertStringContainsString('R350 to R600', $this->render('<x-pulse.price :amount="350" :to="600" />'));
        $this->assertStringContainsString('R1 200', $this->render('<x-pulse.price :amount="1200" />'));
    }

    public function test_fields_are_wired_for_assistive_technology(): void
    {
        $html = $this->render('<x-pulse.field name="what" label="What do you need" hint="One sentence" error="Too short" />');

        $this->assertStringContainsString('<label for="what">', $html);
        $this->assertStringContainsString('id="what"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="what-hint what-error"', $html);
        $this->assertStringContainsString('id="what-error"', $html);
    }

    public function test_the_toggle_is_a_real_switch_and_chips_are_real_checkboxes(): void
    {
        $toggle = $this->render('<x-pulse.toggle name="digest" label="Send a digest" :checked="true" />');
        $this->assertStringContainsString('role="switch"', $toggle);
        $this->assertStringContainsString('checked', $toggle);

        $chips = $this->render('<x-pulse.chips name="topics" legend="Topics" :options="[\'a\' => \'A\']" />');
        $this->assertStringContainsString('<fieldset', $chips);
        $this->assertStringContainsString('<legend>Topics</legend>', $chips);
        $this->assertStringContainsString('type="checkbox" name="topics[]"', $chips);
    }

    public function test_the_mode_dial_marks_the_selected_mode_and_can_drive_livewire(): void
    {
        $links = $this->render('<x-pulse.mode-dial selected="work" />');
        $this->assertSame(1, substr_count($links, 'aria-current="page"'));

        $buttons = $this->render('<x-pulse.mode-dial selected="local" livewire="setMode" />');
        $this->assertStringContainsString("wire:click=\"setMode('trade')\"", $buttons);
        $this->assertStringContainsString('aria-pressed="true"', $buttons);
    }

    public function test_dialogs_are_native_and_labelled(): void
    {
        $drawer = $this->render('<x-pulse.drawer id="d1" title="Filters">Body</x-pulse.drawer>');
        $this->assertStringContainsString('<dialog id="d1"', $drawer);
        $this->assertStringContainsString('aria-labelledby="d1-title"', $drawer);

        $confirm = $this->render('<x-pulse.confirm id="c1" title="Sure?" text="This cannot be undone." label="Delete" />');
        $this->assertStringContainsString('role="alertdialog"', $confirm);
        $this->assertStringContainsString('Delete', $confirm);
    }

    public function test_the_public_pages_render_and_use_no_invented_numbers(): void
    {
        $landing = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('Say what you want.', $landing);
        $this->assertStringContainsString('Example only', $landing);
        $this->assertStringContainsString('Sign in with InfoDot', $landing);

        $this->get('/login')->assertOk()->assertSee('Continue with InfoDot');
    }

    public function test_error_pages_speak_in_the_floor_voice_without_a_session(): void
    {
        $this->get('/this-address-does-not-exist')->assertNotFound()->assertSee('This line does not meet.');

        foreach ([401, 403, 419, 429, 500, 503] as $code) {
            $html = view('errors.'.$code)->render();
            $this->assertStringContainsString('Error '.$code, $html);
            $this->assertStringNotContainsString('—', $html);
        }
    }

    public function test_the_kit_gallery_exists_under_testing_and_renders_every_section(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $html = $this->get('/dev/kit')->assertOk()->getContent();
        foreach (['Buttons', 'Mode dial', 'Beat', 'Sync meter', 'Verification ladder', 'Tabs'] as $heading) {
            $this->assertStringContainsString($heading, $html);
        }
    }

    public function test_the_floor_sample_page_renders_the_dial_the_beats_the_meter_and_the_end_of_the_round(): void
    {
        $html = $this->get('/dev/floor')->assertOk()->getContent();

        $this->assertStringContainsString('class="pk-dial"', $html);
        $this->assertStringContainsString('pk-meter', $html);
        $this->assertStringContainsString('id="sh-context"', $html);
        $this->assertStringContainsString('You are caught up.', $html);
    }

    public function test_visible_copy_has_no_em_or_en_dashes_or_exclamation_marks(): void
    {
        foreach (['/', '/login', '/dev/kit'] as $path) {
            $text = strip_tags(preg_replace('#<(script|style)\b.*?</\1>#s', '', $this->get($path)->getContent()));
            $this->assertStringNotContainsString('—', $text, "$path em dash");
            $this->assertStringNotContainsString('–', $text, "$path en dash");
            $this->assertStringNotContainsString('!', $text, "$path exclamation mark");
        }
    }
}
