<div class="ln-qr">
    <x-pulse.button :variant="$open ? 'quiet' : 'signal'" wire:click="openSheet" type="button" aria-expanded="{{ $open ? 'true' : 'false' }}">Ask for quotes</x-pulse.button>

    @if ($open)
        <section class="pk-card ln-sheet" role="group" aria-labelledby="qr-title-{{ $this->getId() }}">
            @if ($sent)
                <h2 id="qr-title-{{ $this->getId() }}" class="ln-subhead">Sent to {{ count($lineIds) === 1 ? 'one business' : count($lineIds).' businesses' }}.</h2>
                <p class="ln-muted">Each answers on the same quote slip. You hear as they reply, and every request ends: answered, declined or closed after 14 days.</p>
                <div class="pk-cluster">
                    @if (count($lineIds) > 1)
                        <x-pulse.button variant="primary" :href="route('lines.quotes', $group)">Compare the quotes</x-pulse.button>
                    @else
                        <x-pulse.button variant="primary" :href="route('lines.show', $lineIds[0])">Open the line</x-pulse.button>
                    @endif
                    <x-pulse.button variant="quiet" wire:click="close" type="button">Done</x-pulse.button>
                </div>
            @else
                <h2 id="qr-title-{{ $this->getId() }}" class="ln-subhead">Ask up to {{ $cap }} businesses</h2>
                <p class="ln-muted">Say what you need once. Each business replies on the same slip: price, earliest date, what is included and what is not. They pay nothing to see it.</p>
                <p class="ln-note"><x-pulse.icon name="info" :size="16" />Three is the cap. More than three turns a request into a race to the bottom, and the businesses stop answering well.</p>

                @if ($failure)<p class="ln-alert" role="alert"><x-pulse.icon name="alert" :size="16" />{{ $failure }}</p>@endif

                <form wire:submit="send" class="ln-stack">
                    <div>
                        <x-pulse.field name="card.what" id="qr-what" label="What do you need?" wire:model="card.what" maxlength="200" aria-required="true" />
                        <div class="ln-two">
                            <x-pulse.field name="card.when_or_qty" id="qr-when" label="When, or how many" wire:model="card.when_or_qty" maxlength="80" optional />
                            <x-pulse.field name="card.where" id="qr-where" label="Where" wire:model="card.where" maxlength="80" optional />
                        </div>
                        <x-pulse.field name="card.budget_band" id="qr-budget" label="Budget" wire:model="card.budget_band" maxlength="40" optional hint="A band is fine, like R1 000 to R3 000." />
                        <p class="ln-muted">No links or contact details in the request. They unlock once a business replies.</p>
                    </div>

                    <fieldset class="ln-pick">
                        <legend>Who to ask <span class="pk-mono">{{ count($selected) }} of {{ $cap }}</span></legend>

                        @if ($fixed)
                            <label class="ln-pick__row is-fixed">
                                <input type="checkbox" checked disabled>
                                <span><strong>{{ $fixed->name }}</strong><small>Always asked, because you started from their offer.</small></span>
                            </label>
                        @endif

                        <x-pulse.field name="search" id="qr-search" label="Find a business by name" type="search" wire:model.live.debounce.400ms="search" optional />

                        @forelse ($candidates as $row)
                            @php $isOn = in_array($row['entity']->id, $selected, true); @endphp
                            <label class="ln-pick__row{{ $isOn ? ' is-on' : '' }}" wire:key="cand-{{ $row['entity']->id }}">
                                <input type="checkbox" value="{{ $row['entity']->id }}" wire:model.live="selected" @disabled(! $isOn && count($selected) >= $cap)>
                                <span>
                                    <strong>{{ $row['entity']->name }}</strong>
                                    @if ($row['fit'])<small>Quotes on: {{ $row['fit'] }}</small>@else<small>Takes quote requests</small>@endif
                                </span>
                            </label>
                        @empty
                            <p class="ln-muted">No other businesses take quote requests for this yet.@if ($search !== '') Try another name.@endif</p>
                        @endforelse

                        @if (count($selected) >= $cap)
                            <p class="ln-muted">You have picked {{ $cap }}. Untick one to change who you ask.</p>
                        @endif
                    </fieldset>

                    <p class="ln-muted">This costs one knock for the whole request, not one each. You have {{ $left }} left this week.</p>

                    <div class="pk-cluster">
                        <x-pulse.button type="submit" variant="signal" wire:loading.attr="disabled" wire:target="send">{{ count($selected) === 0 ? 'Pick who to ask' : (count($selected) === 1 ? 'Ask 1 business' : 'Ask '.count($selected).' businesses') }}</x-pulse.button>
                        <x-pulse.button variant="quiet" wire:click="close" type="button">Not now</x-pulse.button>
                    </div>
                </form>
            @endif
        </section>
    @endif
</div>
