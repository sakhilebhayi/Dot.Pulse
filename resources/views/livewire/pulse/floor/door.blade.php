<div class="fl-door">
    <section class="pk-card pk-stack" aria-labelledby="door-what">
        <h2 id="door-what">What a door is</h2>
        <p>A door is a public note that says what you are open to, for a day or a week. Strangers can knock once with a short card. You decide who comes in, and contact details unlock only when you say yes.</p>
        <p class="pk-muted">Keep links and contact details out of the note. When time is up the door closes by itself.</p>
    </section>

    @foreach ($doors as $door)
        <section class="pk-card pk-stack fl-door__open" aria-label="An open door">
            <div class="fl-door__head">
                <x-pulse.pill tone="good" icon="door">Open until {{ $until($door) }}</x-pulse.pill>
                <span class="pk-muted fl-small">{{ $door->entity_id ? ($names[$door->entity_id] ?? 'Your page') : 'Your door' }}</span>
            </div>
            <p class="fl-door__purpose">{{ $door->purpose }}</p>
            <p class="pk-muted">
                {{ $door->knocks_count === 0 ? 'No one has knocked yet.' : ($door->knocks_count === 1 ? '1 knock so far.' : $door->knocks_count.' knocks so far.') }}
                Up to {{ $door->knock_cap }} can come in.
                @if ($door->knocks_count > 0)
                    Answer them in <a href="{{ pulse_route('lines') }}">Lines</a>.
                @endif
            </p>
            <div class="pk-cluster">
                <x-pulse.button variant="quiet" size="sm" data-pk-dialog="close-door-{{ $door->id }}">Close the door</x-pulse.button>
            </div>
            <x-pulse.confirm :id="'close-door-'.$door->id" title="Close this door?" text="No new knocks. Knocks already in Lines stay there." label="Close the door" tone="primary" wire:click="close({{ $door->id }})" />
        </section>
    @endforeach

    @if ($doors->isEmpty() || $pages->isNotEmpty())
        <section class="pk-card" aria-labelledby="door-open">
            <h2 id="door-open">Open a door</h2>
            <form wire:submit="open" class="pk-stack" novalidate>
                @if ($failure)
                    <p class="fl-notice fl-notice--bad" role="alert"><x-pulse.icon name="alert" :size="18" /><span>{{ $failure }}</span></p>
                @endif

                <x-pulse.field name="purpose" label="What are you open to?" wire:model="purpose" maxlength="160" hint="One line. For example: looking for a baker for Saturday." autocomplete="off" />

                <fieldset class="fl-radios">
                    <legend>For how long</legend>
                    <label class="fl-radio"><input type="radio" wire:model="length" value="day"><span>24 hours</span></label>
                    <label class="fl-radio"><input type="radio" wire:model="length" value="week"><span>A week</span></label>
                </fieldset>

                @if ($pages->isNotEmpty())
                    <x-pulse.select name="as" label="Open it as" wire:model="as" :options="[0 => 'Me'] + $pages->pluck('name', 'id')->all()" />
                @endif

                <div class="pk-cluster">
                    <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" wire:target="open">Open the door</x-pulse.button>
                </div>
            </form>
        </section>
    @endif
</div>
