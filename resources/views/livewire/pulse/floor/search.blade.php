<div class="fl-search" x-data="{ open: true }" x-on:click.outside="open = false" x-on:keydown.escape="open = false" x-on:input="open = true">
    <div class="fl-draw" wire:loading.delay.shortest wire:target="q,kind,mode,place,remote,priceMin,priceMax,employment,more,submit,pick,pickPlace,clearPlace,clearFilters" aria-hidden="true"></div>

    <form class="fl-search__bar" role="search" wire:submit="submit">
        <div class="fl-search__field">
            <label for="fl-q" class="sr-only">Search the floor</label>
            <x-pulse.icon name="search" :size="18" />
            <input id="fl-q" type="search" class="pk-control" name="q" wire:model.live.debounce.300ms="q" placeholder="Search offers, needs, roles, businesses" autocomplete="off" autofocus aria-describedby="fl-q-hint">
            <p id="fl-q-hint" class="sr-only">Results update as you type.</p>
        </div>
        <x-pulse.button variant="primary" type="submit">Search</x-pulse.button>
    </form>

    @if (count($suggestions))
        <div class="fl-suggest" role="group" aria-label="Suggestions" x-show="open">
            @foreach ($suggestions as $s)
                <button type="button" wire:click="pick({{ \Illuminate\Support\Js::from($s['value']) }})" wire:key="sg-{{ $loop->index }}">
                    <span class="fl-small">{{ ['topic' => 'Topic', 'skill' => 'Skill', 'business' => 'Business', 'listing' => 'Listing'][$s['type']] ?? ucfirst($s['type']) }}</span>
                    <span>{{ $s['label'] }}</span>
                </button>
            @endforeach
        </div>
    @endif

    <details class="fl-filters pk-card" @if ($hasFilters) open @endif wire:ignore.self>
        <summary>Filters @if ($hasFilters)<span class="pk-tab__count">on</span>@endif</summary>
        <div class="fl-filters__grid">
            <x-pulse.select name="kind" label="Kind" wire:model.live="kind" placeholder="Anything" :options="\App\Livewire\Pulse\Floor\Search::KINDS" />
            <x-pulse.select name="mode" label="Mode" wire:model.live="mode" placeholder="All modes" :options="\App\Livewire\Pulse\Floor\Search::MODES" />

            <div class="fl-place">
                @if ($placeLabel)
                    <span class="pk-field__label">Place</span>
                    <div class="pk-cluster">
                        <x-pulse.pill tone="signal" icon="pin">{{ $placeLabel }}</x-pulse.pill>
                        <button type="button" class="fl-link" wire:click="clearPlace">Clear place</button>
                    </div>
                @else
                    <x-pulse.field name="placeText" label="Place" wire:model.live.debounce.300ms="placeText" placeholder="A city, province or country" autocomplete="off" />
                    @if ($placeMatches->isNotEmpty())
                        <div class="fl-suggest fl-suggest--tight" role="group" aria-label="Places">
                            @foreach ($placeMatches as $p)
                                <button type="button" wire:key="pl-{{ $p->id }}" wire:click="pickPlace({{ \Illuminate\Support\Js::from($p->slug) }})"><span>{{ $places->label($p) }}</span></button>
                            @endforeach
                        </div>
                    @endif
                @endif
            </div>

            @if ($kind === '' || $kind === 'role')
                <x-pulse.select name="employment" label="Employment" wire:model.live="employment" placeholder="Any type" :options="\App\Livewire\Pulse\Floor\Search::TYPES" />
            @endif

            @if ($kind === '' || $kind === 'offer')
                <x-pulse.field name="priceMin" label="Price from (rand)" type="number" wire:model.live.debounce.500ms="priceMin" min="0" step="1" inputmode="numeric" />
                <x-pulse.field name="priceMax" label="Price up to (rand)" type="number" wire:model.live.debounce.500ms="priceMax" min="0" step="1" inputmode="numeric" />
            @endif
        </div>
        <div class="fl-filters__foot">
            <x-pulse.toggle name="remote" label="Remote only" wire:model.live="remote" :checked="$remote" />
            @if ($hasFilters)
                <x-pulse.button variant="quiet" size="sm" wire:click="clearFilters">Clear filters</x-pulse.button>
            @endif
        </div>
    </details>

    @if (! $searched)
        <x-pulse.empty title="Say what you are looking for." text="Search offers, needs, roles and businesses. Every result says why it matched." />
    @elseif ($beats === [])
        <x-pulse.empty :title="$ran !== '' ? 'Nothing found for '.$ran.'.' : 'Nothing found.'" text="Nothing on the floor fits that yet. Say what you need and the floor will find it for you when something does.">
            <div class="pk-cluster">
                <x-pulse.button variant="signal" :href="pulse_route('needs.create')">Say what you need</x-pulse.button>
                @if ($hasFilters)
                    <x-pulse.button variant="quiet" wire:click="clearFilters">Clear filters</x-pulse.button>
                @endif
            </div>
        </x-pulse.empty>
    @else
        <p class="fl-count" role="status">{{ $total }} {{ $total === 1 ? 'result' : 'results' }}@if ($ran !== '') for <strong>{{ $ran }}</strong>@endif.</p>
        <div class="pk-enter fl-list">
            @foreach ($beats as $b)
                @if (($b['result'] ?? 'signal') === 'entity')
                    @php $e = $b['entity']; @endphp
                    <article class="pk-beat fl-beat fl-entity" wire:key="ent-{{ $e['id'] }}">
                        <div class="pk-beat__top"><x-pulse.kind-word kind="business" />@if ($e['place'])<x-pulse.place-label :name="$e['place']" />@endif</div>
                        <h3 class="pk-beat__title"><a href="{{ pulse_route('entities.show', ['entity' => $e['slug']]) }}">{{ $e['name'] }}</a></h3>
                        @if ($e['tagline'])<div class="pk-beat__body">{{ $e['tagline'] }}</div>@endif
                        <div class="pk-beat__from">
                            <x-pulse.avatar :name="$e['name']" entity />
                            <span class="pk-beat__from-text">
                                @if ($e['industry'])<span class="fl-small">{{ $e['industry'] }}</span>@endif
                                @if ($e['verification'])<span><x-pulse.pill :tone="str_starts_with($e['verification'], 'Checked') ? 'good' : 'warn'">{{ $e['verification'] }}</x-pulse.pill></span>@endif
                            </span>
                        </div>
                        @if ($b['why'])<div class="pk-beat__meter"><p class="fl-why">{{ implode(' ', $b['why']) }}</p></div>@endif
                    </article>
                @else
                    @include('livewire.pulse.floor.partials.beat', ['b' => $b, 'mode' => 'search', 'why' => $b['why'] ?? []])
                @endif
            @endforeach
        </div>
        @if ($cursor)
            <div class="fl-more"><x-pulse.button variant="primary" wire:click="more">Show more results</x-pulse.button></div>
        @endif
    @endif
</div>
