<div class="bz">
    @if ($this->atQuota)
        <div class="bz-notice bz-notice--warn" role="status">
            <x-pulse.icon name="alert" :size="18" />
            <p>You have {{ (int) config('pulse.entities.max_open_needs', 10) }} open needs, the most at one time. Close or fulfil one to post another.</p>
        </div>
    @endif

    <x-pulse.error-banner :inline="['title', 'details', 'kind', 'tags', 'budget_text', 'needed_by', 'place_query', 'reach', 'visibility', 'entity_id']" />

    <form class="bz-form" wire:submit="save" novalidate>
        <section class="bz-block" aria-labelledby="nd-what">
            <h2 id="nd-what">What you are looking for</h2>
            <x-pulse.select name="kind" id="need-kind" label="It is" wire:model="kind" :options="\App\Livewire\Pulse\Need\NeedForm::kinds()" />
            <x-pulse.field name="title" id="need-title" label="Say it in a line" wire:model="title" maxlength="140" required placeholder="A bookkeeper for a small shop in Soweto" />
            <x-pulse.textarea name="details" id="need-details" label="Details" wire:model="details" rows="6" maxlength="4000" optional hint="What, how much, how soon. No phone numbers or links: people reach you through Pulse, and you decide who gets your details." />
        </section>

        <section class="bz-block" aria-labelledby="nd-tags">
            <h2 id="nd-tags">What it is about</h2>
            <p class="pk-muted">Tags decide who it fits. Pick from the list or add your own.</p>
            @if ($tags)
                <ul class="bz-tags" aria-label="Your tags">
                    @foreach ($tags as $i => $tag)
                        <li wire:key="tag-{{ $i }}-{{ $tag }}"><x-pulse.pill>{{ $tag }}</x-pulse.pill><button type="button" class="bz-tagx" wire:click="removeTag({{ $i }})" aria-label="Remove tag {{ $tag }}"><x-pulse.icon name="x" :size="14" /></button></li>
                    @endforeach
                </ul>
            @endif
            <div class="bz-combo">
                <x-pulse.field name="tag_query" id="need-tag" label="Add a tag" wire:model.live.debounce.250ms="tag_query" wire:keydown.enter.prevent="addTag" autocomplete="off" optional />
                @if ($this->tagResults)
                    <ul class="bz-suggest" role="listbox" aria-label="Tag suggestions">
                        @foreach ($this->tagResults as $hit)
                            <li role="presentation"><button type="button" role="option" wire:click="addTag({{ \Illuminate\Support\Js::from($hit['label']) }})">{{ $hit['label'] }}<span>{{ $hit['group'] }}</span></button></li>
                        @endforeach
                    </ul>
                @endif
                @error('tags')<p class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
            </div>
        </section>

        <section class="bz-block" aria-labelledby="nd-terms">
            <h2 id="nd-terms">Budget and timing</h2>
            <div class="bz-pair">
                <x-pulse.field name="budget_text" id="need-budget" label="Budget" wire:model="budget_text" maxlength="100" optional placeholder="Up to R3 000 a month" />
                <x-pulse.field name="needed_by" id="need-by" type="date" label="Needed by" wire:model="needed_by" optional />
            </div>
        </section>

        <section class="bz-block" aria-labelledby="nd-where">
            <h2 id="nd-where">Who should see it</h2>
            <x-pulse.select name="reach" id="need-reach" label="How far" wire:model.live="reach" :options="['local' => 'Near me, same city', 'region' => 'My region', 'national' => 'Anywhere in my country', 'global' => 'Anywhere, online is fine']" />
            @if (in_array($reach, ['local', 'region'], true))
                <div class="bz-combo">
                    <x-pulse.field name="place_query" id="need-place" label="Where" wire:model.live.debounce.250ms="place_query" autocomplete="off" hint="A city or region. Pulse keeps the place, never a pin on a map." />
                    @if ($place_id)<p class="bz-chosen"><x-pulse.icon name="pin" :size="14" />{{ $place_query }} <button type="button" wire:click="clearPlace">Clear</button></p>@endif
                    @if ($this->placeResults)
                        <ul class="bz-suggest" role="listbox" aria-label="Places">
                            @foreach ($this->placeResults as $hit)
                                <li role="presentation"><button type="button" role="option" wire:click="choosePlace({{ $hit['id'] }})">{{ $hit['label'] }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
            <fieldset class="me-choices">
                <legend class="pk-field__label">Who can open it</legend>
                @include('livewire.pulse.me.partials.choice', ['name' => 'visibility', 'value' => 'open', 'model' => 'visibility', 'title' => 'Open', 'text' => 'Any signed-in person can read it, and it also goes to people it fits.'])
                @include('livewire.pulse.me.partials.choice', ['name' => 'visibility', 'value' => 'matched', 'model' => 'visibility', 'title' => 'Matched only', 'text' => 'Only sellers and people it fits are shown it. It is not open to everyone.'])
            </fieldset>
            @if ($this->pages)
                <x-pulse.select name="entity_id" id="need-page" label="Post it for one of your pages" wire:model="entity_id" placeholder="No, post it as me" :options="$this->pages" optional />
            @endif
        </section>

        <div class="bz-actions">
            <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" :disabled="$this->atQuota">{{ $editing ? 'Save changes' : 'Post it' }}</x-pulse.button>
            <p class="pk-muted">A need lasts {{ (int) config('pulse.entities.need_ttl_days', 30) }} days. Close it any time, or open it again.</p>
        </div>
    </form>
</div>
