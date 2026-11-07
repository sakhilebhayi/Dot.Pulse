<div class="bz">
    @if ($this->atQuota)
        <div class="bz-notice bz-notice--warn" role="status">
            <x-pulse.icon name="alert" :size="18" />
            <div>
                <strong>You run {{ count($this->ownPages) }} business pages, the most one person can.</strong>
                <p>Take one off the floor or ask another owner to take it over, then come back. Your pages:</p>
                <ul class="bz-plain">
                    @foreach ($this->ownPages as $own)
                        <li><a href="{{ route('entities.manage', ['entity' => $own->slug]) }}">{{ $own->name }}</a></li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    @if ($notice)
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ $notice }}</p></div>
    @endif

    @if ($errors->any())
        <div class="bz-notice bz-notice--bad" role="alert">
            <x-pulse.icon name="alert" :size="18" />
            <p>{{ $errors->first() }}</p>
        </div>
    @endif

    @if ($editing && $this->entity)
        <div class="bz-state">
            <p>
                @if ($this->entity->status === 'live')
                    <x-pulse.pill tone="good">On the floor</x-pulse.pill>
                @elseif ($this->entity->status === 'suspended')
                    <x-pulse.pill tone="bad">Suspended</x-pulse.pill>
                @elseif ($this->entity->status === 'draft')
                    <x-pulse.pill tone="neutral">Draft</x-pulse.pill>
                @else
                    <x-pulse.pill tone="warn">Taken off the floor</x-pulse.pill>
                @endif
                @if ($this->entity->moderation !== 'approved')
                    <x-pulse.pill tone="warn">Waiting for a human</x-pulse.pill>
                @endif
            </p>
            @can('archive', $this->entity)
                @if ($this->entity->status === 'live')
                    <x-pulse.button variant="quiet" size="sm" wire:click="takeOff" wire:confirm="Take this page off the floor? Its offers and roles stop being shown too.">Take off the floor</x-pulse.button>
                @elseif ($this->entity->status !== 'suspended')
                    <x-pulse.button variant="signal" size="sm" wire:click="putOnFloor">{{ $this->entity->status === 'draft' ? 'Publish the page' : 'Put back on the floor' }}</x-pulse.button>
                @endif
            @endcan
        </div>
        @if ($this->entity->moderation !== 'approved')
            @include('livewire.pulse.business.partials.held', ['state' => $this->entity->moderation, 'what' => 'page', 'reason' => $this->entity->moderation_reason])
        @endif
    @endif

    <form wire:submit="save" class="bz-form" novalidate>
        <section class="bz-block" aria-labelledby="bz-basics">
            <h2 id="bz-basics">The basics</h2>
            <x-pulse.field name="name" label="Business name" wire:model="name" maxlength="140" required autocomplete="organization" />
            <x-pulse.field name="tagline" label="Tagline" wire:model="tagline" maxlength="160" optional hint="One line on what you do. It shows on every card." />
            <x-pulse.textarea name="about" label="About" wire:model="about" rows="6" maxlength="5000" optional hint="Plain words. No phone numbers or email in here: contact details unlock after someone knocks." />
        </section>

        <section class="bz-block" aria-labelledby="bz-does">
            <h2 id="bz-does">What this page does</h2>
            <p class="pk-muted">A page sells, hires, or both. Switch off what does not apply.</p>
            <x-pulse.toggle name="is_seller" label="We sell products or services" hint="Turns on offers and quote requests." :checked="$is_seller" wire:model.live="is_seller" />
            <x-pulse.toggle name="is_employer" label="We hire" hint="Turns on roles, culture and the hiring signal." :checked="$is_employer" wire:model.live="is_employer" />
            @error('is_seller')<p class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror

            <div class="bz-combo">
                <x-pulse.field name="industry" label="Industry" wire:model.live.debounce.250ms="industry" maxlength="80" optional autocomplete="off" hint="Start typing and pick from the list, or write your own." />
                @if ($this->industryResults)
                    <ul class="bz-suggest" role="listbox" aria-label="Industry suggestions">
                        @foreach ($this->industryResults as $hit)
                            <li role="presentation"><button type="button" role="option" wire:click="chooseIndustry({{ \Illuminate\Support\Js::from($hit['label']) }})">{{ $hit['label'] }}<span>{{ $hit['group'] }}</span></button></li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>

        <section class="bz-block" aria-labelledby="bz-where">
            <h2 id="bz-where">Where you are</h2>
            <div class="bz-combo">
                <x-pulse.field name="place_query" label="Base" wire:model.live.debounce.250ms="place_query" optional autocomplete="off" hint="A city, region or country. Pulse stores the place, never a pin on a map." />
                @if ($place_id)
                    <p class="bz-chosen"><x-pulse.icon name="pin" :size="14" />{{ $place_query }} <button type="button" wire:click="clearPlace">Clear</button></p>
                @endif
                @if ($this->placeResults)
                    <ul class="bz-suggest" role="listbox" aria-label="Places">
                        @foreach ($this->placeResults as $hit)
                            <li role="presentation"><button type="button" role="option" wire:click="choosePlace({{ $hit['id'] }})">{{ $hit['label'] }}</button></li>
                        @endforeach
                    </ul>
                @endif
                @error('place_id')<p class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
            </div>
            <x-pulse.field name="service_area_text" label="Where you serve" wire:model="service_area_text" optional hint="Places separated by commas, for example Gauteng, Limpopo, remote." />
        </section>

        <section class="bz-block" aria-labelledby="bz-reach">
            <h2 id="bz-reach">How people reach you</h2>
            <x-pulse.select name="contact_policy" label="Who can knock" wire:model="contact_policy" :options="[
                'anyone' => 'Anyone signed in. A line opens straight away.',
                'context' => 'Only through one of your offers or roles.',
                'requests' => 'Anyone, but you accept first.',
                'nobody' => 'Nobody for now.',
            ]" hint="You can change this any time." />
            <div class="bz-pair">
                <x-pulse.field name="public_email" type="email" label="Public email" wire:model="public_email" optional hint="Shown to signed-in people as claimed." />
                <x-pulse.field name="public_phone" label="Public phone" wire:model="public_phone" optional hint="Shown as claimed. Pulse does not call it." />
            </div>
            <x-pulse.field name="website" type="url" label="Website" wire:model="website" optional placeholder="https://" hint="Shown as claimed. Pulse does not check that the site is yours." />
        </section>

        <section class="bz-block" aria-labelledby="bz-team">
            <h2 id="bz-team">Team and culture</h2>
            <div class="bz-pair">
                <x-pulse.select name="team_size" label="Team size" wire:model="team_size" placeholder="Not shown" :options="['1' => 'Just me', '2-10' => '2 to 10', '11-50' => '11 to 50', '51-200' => '51 to 200', '201-500' => '201 to 500', '501+' => 'More than 500']" />
                <x-pulse.field name="founded_year" type="number" label="Founded" wire:model="founded_year" optional min="1800" :max="now()->year" />
            </div>
            @if ($is_employer)
                <x-pulse.select name="culture_work_mode" label="How you work" wire:model="culture_work_mode" placeholder="Not shown" :options="['onsite' => 'On site', 'hybrid' => 'Hybrid', 'remote' => 'Remote']" />
                <x-pulse.field name="culture_values" label="What you value" wire:model="culture_values" optional hint="A few short words, separated by commas." />
                <x-pulse.field name="culture_perks" label="What you offer people" wire:model="culture_perks" optional hint="Real perks only, separated by commas." />
            @endif
        </section>

        <section class="bz-block" aria-labelledby="bz-pics">
            <h2 id="bz-pics">Pictures</h2>
            <p class="pk-muted">JPEG, PNG or WebP, up to 5 MB. Pulse re-saves every picture and removes hidden data. The previews show the crop people will see.</p>
            <div class="bz-pics">
                <div class="bz-pic">
                    <x-pulse.field name="logo" label="Logo" optional>
                        <input id="logo" name="logo" type="file" class="pk-control" accept="image/jpeg,image/png,image/webp" wire:model="logo" aria-describedby="logo-error">
                    </x-pulse.field>
                    <div class="bz-frame bz-frame--logo" aria-label="Logo preview">
                        @if ($logoPreview)<img src="{{ $logoPreview }}" alt="Logo preview">
                        @elseif ($editing && $this->entity?->logo && ! $remove_logo)<img src="{{ app(\App\Services\Pulse\Entities\MediaService::class)->url($this->entity->logo_media_id, 'medium') }}" alt="Current logo">
                        @else<span>No logo yet</span>@endif
                    </div>
                    <div wire:loading wire:target="logo" class="pk-muted">Uploading</div>
                    @if ($editing && $this->entity?->logo_media_id)
                        <x-pulse.toggle name="remove_logo" label="Remove the current logo" :checked="$remove_logo" wire:model.live="remove_logo" />
                    @endif
                </div>
                <div class="bz-pic bz-pic--cover">
                    <x-pulse.field name="cover" label="Cover" optional hint="Wide pictures work best. The centre is kept.">
                        <input id="cover" name="cover" type="file" class="pk-control" accept="image/jpeg,image/png,image/webp" wire:model="cover" aria-describedby="cover-hint cover-error">
                    </x-pulse.field>
                    <div class="bz-frame bz-frame--cover" aria-label="Cover preview">
                        @if ($coverPreview)<img src="{{ $coverPreview }}" alt="Cover preview">
                        @elseif ($editing && $this->entity?->cover && ! $remove_cover)<img src="{{ app(\App\Services\Pulse\Entities\MediaService::class)->url($this->entity->cover_media_id, 'large') }}" alt="Current cover">
                        @else<span>No cover yet</span>@endif
                    </div>
                    <div wire:loading wire:target="cover" class="pk-muted">Uploading</div>
                    @if ($editing && $this->entity?->cover_media_id)
                        <x-pulse.toggle name="remove_cover" label="Remove the current cover" :checked="$remove_cover" wire:model.live="remove_cover" />
                    @endif
                </div>
            </div>
        </section>

        <section class="bz-block" aria-labelledby="bz-cred">
            <h2 id="bz-cred">Credibility</h2>
            <p class="pk-muted">Pulse shows what you say as a claim and what it can see as a check. It never calls a business verified.</p>
            <x-pulse.field name="registration_number" label="Registration number" wire:model="registration_number" optional hint="Shown as claimed. Pulse has not checked it against a registry." />
            @if (! $editing)
                @if ($this->companies)
                    <x-pulse.select name="infodot_team" label="Link an InfoDot company" wire:model="infodot_team" placeholder="Not now" :options="collect($this->companies)->pluck('name', 'id')->all()" hint="Adds a checked line to your ladder: you belong to that company in InfoDot. It does not mean InfoDot verified the business." />
                @else
                    <p class="pk-muted">No InfoDot company to link. Companies you own or administer in InfoDot show up here after you sign in through InfoDot.</p>
                @endif
            @else
                <p class="pk-muted">Linking an InfoDot company is on the <a href="{{ route('entities.manage.verify', ['entity' => $this->entity->slug]) }}">Verify tab</a>.</p>
            @endif
        </section>

        <div class="bz-actions">
            @if ($editing)
                <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" wire:target="save,logo,cover">Save changes</x-pulse.button>
            @else
                <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" wire:target="save,logo,cover" :disabled="$this->atQuota">Publish page</x-pulse.button>
                <x-pulse.button variant="quiet" wire:click="saveDraft" wire:loading.attr="disabled" wire:target="saveDraft,logo,cover" :disabled="$this->atQuota">Save as draft</x-pulse.button>
                <p class="pk-muted">A draft stays off the floor until you put it on.</p>
            @endif
        </div>
    </form>
</div>
