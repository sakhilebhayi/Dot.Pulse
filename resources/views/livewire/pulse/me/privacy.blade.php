@php
    $ledger = $this->ledger();
    $blocks = $this->blocks();
    $mutes = $this->mutes();
    $blockedCount = $blocks['people']->count() + $blocks['pages']->count();
@endphp
<div class="me-stack">
    @if ($problem)<p role="alert" class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $problem }}</span></p>@endif

    <section class="pk-card me-section" aria-labelledby="pv-ledger">
        <h2 id="pv-ledger">What you have said yes to</h2>
        <p class="me-section__text">Built live from your real settings, so it cannot drift from what Pulse does. One tap takes any of it back.</p>
        @if (count($ledger) === 0)
            <x-pulse.empty title="Nothing is switched on." text="Nobody can find you, nobody new can message you, and Pulse sends no summary. Pulse only relies on what you turn on." />
        @else
            <ul class="me-list">
                @foreach ($ledger as $row)
                    <li wire:key="consent-{{ $row['key'] }}">
                        <div class="pk-row">
                            <div class="pk-row__main">
                                <div class="pk-row__title me-plain">{{ $row['sentence'] }}</div>
                                @if ($row['since'] || $row['until'])
                                    <div class="pk-row__meta">
                                        @if ($row['since'])Since <time datetime="{{ $row['since']->toIso8601String() }}">{{ $row['since']->format('j M Y') }}</time>@endif
                                        @if ($row['since'] && $row['until']), @endif
                                        @if ($row['until'])until <time datetime="{{ $row['until']->toIso8601String() }}">{{ $row['until']->format('j M Y, H:i') }}</time>@endif
                                    </div>
                                @endif
                            </div>
                            <div class="pk-row__end">
                                {{-- A plain button, not <x-pulse.button>: Blade directives such as @js are not compiled inside a component tag, so the click handler reached the browser as literal "@js(...)" and threw. --}}
                                <button type="button" class="pk-btn pk-btn--quiet pk-btn--sm" wire:click="withdraw(@js($row['key']))" wire:loading.attr="disabled">{{ $row['withdraw']['label'] }}</button>
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="pk-card me-section" aria-labelledby="pv-blocks">
        <h2 id="pv-blocks">People and pages you blocked</h2>
        <p class="me-section__text">They are not told. Blocking hides you from each other everywhere and closes open lines.</p>
        @if ($blockedCount === 0)
            <p class="pk-muted">You have not blocked anyone.</p>
        @else
            <ul class="me-list">
                @foreach ($blocks['people'] as $b)
                    <li wire:key="bp-{{ $b['id'] }}"><div class="pk-row"><div class="pk-row__main"><div class="pk-row__title">{{ $b['label'] }}</div><div class="pk-row__meta">Person</div></div><div class="pk-row__end"><x-pulse.button variant="quiet" size="sm" wire:click="unblockUser({{ $b['id'] }})" type="button">Unblock</x-pulse.button></div></div></li>
                @endforeach
                @foreach ($blocks['pages'] as $b)
                    <li wire:key="be-{{ $b->entity_id }}"><div class="pk-row"><div class="pk-row__main"><div class="pk-row__title">{{ $b->entity?->name ?? 'A business page' }}</div><div class="pk-row__meta">Business page</div></div><div class="pk-row__end"><x-pulse.button variant="quiet" size="sm" wire:click="unblockEntity({{ $b->entity_id }})" type="button">Unblock</x-pulse.button></div></div></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="pk-card me-section" aria-labelledby="pv-mutes">
        <h2 id="pv-mutes">What you muted</h2>
        <p class="me-section__text">Quiet and one way. The Floor stops showing these.</p>
        @if (count($mutes) === 0)
            <p class="pk-muted">You have not muted anything.</p>
        @else
            <ul class="me-list">
                @foreach ($mutes as $m)
                    <li wire:key="mute-{{ $m['kind'] }}-{{ $m['target'] }}"><div class="pk-row"><div class="pk-row__main"><div class="pk-row__title">{{ $m['label'] }}</div><div class="pk-row__meta">{{ ['topic' => 'Topic', 'entity' => 'Business page', 'user' => 'Person'][$m['kind']] ?? $m['kind'] }}</div></div><div class="pk-row__end"><button type="button" class="pk-btn pk-btn--quiet pk-btn--sm" wire:click="unmute(@js($m['kind']), @js($m['target']))">Unmute</button></div></div></li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="pk-card me-section" aria-labelledby="pv-export">
        <h2 id="pv-export">Download your data</h2>
        <p class="me-section__text">One JSON file with your profile, settings, consent, blocks, mutes, needs, doors, pages, conversations and the log of what you did. Your own words in full. The people on the other side show by public name only, and an anonymous professional never shows at all. Their emails and private settings are not in it.</p>
        @if (session('pulse_notice'))
            <p class="pk-field__hint" role="status">{{ session('pulse_notice') }}</p>
        @endif
        @if (! $exportOpen)
            <div><x-pulse.button variant="quiet" wire:click="startExport" type="button" icon="chevron">Download my data</x-pulse.button></div>
        @else
            <form wire:submit="export" class="me-erase">
                <x-pulse.field name="confirmation" id="export-confirmation" label="Type &quot;{{ \App\Services\Pulse\Lines\StepUp::phrase('export') }}&quot; to confirm" wire:model.live="confirmation" autocomplete="off" autocapitalize="off" spellcheck="false" />
                @if ($this->codeRequired())
                    <x-pulse.field name="code" id="export-code" label="The 6 digit code from your email" hint="We email it to the address on your account. It works once, for 10 minutes." wire:model="code" inputmode="numeric" autocomplete="one-time-code" />
                    <div><x-pulse.button variant="quiet" wire:click="sendCode('export')" type="button">{{ $codeSent ? 'Send it again' : 'Email me a code' }}</x-pulse.button></div>
                @endif
                <div class="pk-cluster">
                    <x-pulse.button variant="primary" type="submit">Download</x-pulse.button>
                    <x-pulse.button variant="quiet" wire:click="cancelExport" type="button">Not now</x-pulse.button>
                </div>
            </form>
        @endif
        <p class="pk-field__hint">A few downloads every ten minutes is the limit.</p>
    </section>

    <section class="pk-card me-section me-danger" aria-labelledby="pv-erase">
        <h2 id="pv-erase">Erase what Pulse holds about you</h2>
        <p class="me-section__text">Your right under POPIA. It cannot be undone.</p>

        <div class="me-two">
            <div>
                <h3>Gone</h3>
                <ul class="me-bullets">
                    <li>Your profile text, photo link, skills and work history</li>
                    <li>Your talent card, needs and open doors</li>
                    <li>Your saves, mutes, blocks you made and notification settings</li>
                    <li>Your matches, follows of pages, first contact allowance and Pulse notifications</li>
                    <li>Every message you wrote, replaced by "This message was deleted."</li>
                    <li>Your open conversations, closed for the other side</li>
                </ul>
            </div>
            <div>
                <h3>Kept, and why</h3>
                <ul class="me-bullets">
                    <li>Confirmed results, because they are the other side's record too</li>
                    <li>Reviews you wrote, with your name taken off, because they are part of a business's record</li>
                    <li>The security log and reports, with you taken out, so abuse can still be traced</li>
                    <li>Blocks other people made against you, so a blocked person cannot come back by erasing</li>
                </ul>
            </div>
        </div>
        <p class="pk-field__hint">This clears Pulse. Your Dot account and sign-in belong to InfoDot, and you manage them under <a href="{{ pulse_route('me.account') }}">Account and security</a>. You will be signed out.</p>

        @if (! $eraseOpen)
            <div><x-pulse.button variant="danger" wire:click="startErase" type="button">Erase my Pulse data</x-pulse.button></div>
        @else
            <form wire:submit="erase" class="me-erase">
                <x-pulse.field name="confirmation" id="erase-confirmation" label="Type &quot;{{ \App\Livewire\Pulse\Me\Privacy::PHRASE }}&quot; to confirm" wire:model.live="confirmation" autocomplete="off" autocapitalize="off" spellcheck="false" />
                @if ($this->codeRequired())
                    <x-pulse.field name="code" id="erase-code" label="The 6 digit code from your email" hint="We email it to the address on your account. It works once, for 10 minutes." wire:model="code" inputmode="numeric" autocomplete="one-time-code" />
                    <div><x-pulse.button variant="quiet" wire:click="sendCode('erase')" type="button">{{ $codeSent ? 'Send it again' : 'Email me a code' }}</x-pulse.button></div>
                @endif
                <div class="pk-cluster">
                    <x-pulse.button variant="danger" type="submit" :disabled="mb_strtolower(trim($confirmation)) !== \App\Livewire\Pulse\Me\Privacy::PHRASE">Erase and sign me out</x-pulse.button>
                    <x-pulse.button variant="quiet" wire:click="cancelErase" type="button">Keep my data</x-pulse.button>
                </div>
            </form>
        @endif
    </section>
</div>
