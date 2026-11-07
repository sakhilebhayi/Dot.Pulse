<form wire:submit="save" class="me-stack">

    <section class="pk-card me-section" aria-labelledby="me-photo">
        <h2 id="me-photo">Your photo</h2>
        <p class="me-section__text">People see it only when employers can find you by name, and on your own page. In anonymous mode it is never shown to anyone else.</p>
        <div class="me-photo">
            <x-pulse.avatar :name="auth()->user()->name" :src="$this->avatarUrl()" size="xl" />
            <div class="me-photo__actions">
                <label class="pk-btn pk-btn--quiet" for="me-avatar">{{ $this->profile->avatar_media_id ? 'Replace photo' : 'Add a photo' }}</label>
                <input id="me-avatar" class="sr-only me-file" type="file" wire:model="avatar" accept="image/jpeg,image/png,image/webp">
                @if ($this->profile->avatar_media_id)
                    <x-pulse.button variant="ghost" wire:click="removeAvatar" type="button">Remove</x-pulse.button>
                @endif
                <span wire:loading wire:target="avatar" class="pk-field__hint">Uploading</span>
            </div>
            <p class="pk-field__hint">JPEG, PNG or WebP, up to 5 MB. Pulse re-saves it and strips location data.</p>
            @if ($avatarError)<p role="alert" class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $avatarError }}</span></p>@endif
            @error('avatar')<p role="alert" class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
        </div>
    </section>

    <section class="pk-card me-section" aria-labelledby="me-about">
        <h2 id="me-about">About you</h2>
        <fieldset class="me-choices me-choices--row">
            <legend class="pk-field__label">What you do</legend>
            @include('livewire.pulse.me.partials.choice', ['name' => 'kind', 'value' => 'individual', 'model' => 'kind', 'title' => 'Individual', 'text' => 'Here for myself.'])
            @include('livewire.pulse.me.partials.choice', ['name' => 'kind', 'value' => 'professional', 'model' => 'kind', 'title' => 'Professional', 'text' => 'I work in a trade or profession.'])
        </fieldset>
        <x-pulse.field name="headline" label="Headline" hint="One line that says what you do. No phone numbers, emails or links." wire:model="headline" maxlength="120" placeholder="Bookkeeper and payroll specialist" />
        <x-pulse.textarea name="bio" label="About" hint="Up to 1,500 characters. Plain text." wire:model="bio" maxlength="1500" rows="5" optional />
        <x-pulse.field name="experienceYears" type="number" label="Years of experience" hint="Employers see a band (for example 5 to 10 years), never the exact number." wire:model="experienceYears" min="0" max="60" inputmode="numeric" optional style="max-width: 200px" />
    </section>

    <section class="pk-card me-section" aria-labelledby="me-skills">
        <h2 id="me-skills">Skills, interests and languages</h2>
        <p class="me-section__text">Pulse matches on these, and tells people why.</p>
        @include('livewire.pulse.me.partials.tag-input', ['field' => 'skills', 'label' => 'Skills', 'hint' => 'Up to 12.', 'placeholder' => 'Bookkeeping, plumbing, design', 'items' => $skills, 'suggestions' => $this->tagSuggestions('skills')])
        @include('livewire.pulse.me.partials.tag-input', ['field' => 'interests', 'label' => 'Interests', 'hint' => 'Up to 15.', 'placeholder' => 'Cycling, solar, local food', 'items' => $interests, 'suggestions' => $this->tagSuggestions('interests')])
        @include('livewire.pulse.me.partials.tag-input', ['field' => 'languages', 'label' => 'Languages', 'hint' => 'Up to 10.', 'placeholder' => 'English, isiZulu', 'items' => $languages, 'suggestions' => $this->tagSuggestions('languages')])
    </section>

    <section class="pk-card me-section" aria-labelledby="me-ach">
        <h2 id="me-ach">Achievements</h2>
        <p class="me-section__text">Things you did that you would want an employer to know. One line each, up to 10.</p>
        @foreach ($achievements as $i => $line)
            <div class="me-line" wire:key="ach-{{ $i }}">
                <x-pulse.field :name="'achievements.'.$i" :id="'ach-'.$i" label="Achievement {{ $i + 1 }}" wire:model="achievements.{{ $i }}" maxlength="160" />
                <x-pulse.button variant="ghost" size="sm" wire:click="removeAchievement({{ $i }})" type="button" :aria-label="'Remove achievement '.($i + 1)">Remove</x-pulse.button>
            </div>
        @endforeach
        @if (count($achievements) < 10)
            <div><x-pulse.button variant="quiet" size="sm" icon="plus" wire:click="addAchievement" type="button">Add an achievement</x-pulse.button></div>
        @endif
    </section>

    <section class="pk-card me-section" aria-labelledby="me-work">
        <h2 id="me-work">Work history</h2>
        <p class="me-section__text">Up to 8 jobs. Shown only on your own page, and to employers when you are found by name.</p>
        <div class="me-note">
            <x-pulse.icon name="lock" :size="18" />
            <p><strong>Hide me from this employer.</strong> Turn it on for a job and an employer page with that same name can never find you, even when you are discoverable. Spell the employer the way they do, because Pulse compares names.</p>
        </div>
        @foreach ($jobs as $i => $job)
            <fieldset class="me-job" wire:key="job-{{ $i }}">
                <legend>Job {{ $i + 1 }}</legend>
                <div class="me-job__grid">
                    <x-pulse.field :name="'jobs.'.$i.'.title'" :id="'job-title-'.$i" label="Title" wire:model="jobs.{{ $i }}.title" maxlength="80" />
                    <x-pulse.field :name="'jobs.'.$i.'.org'" :id="'job-org-'.$i" label="Employer" wire:model="jobs.{{ $i }}.org" maxlength="80" optional />
                    <x-pulse.field :name="'jobs.'.$i.'.from_year'" :id="'job-from-'.$i" type="number" label="From year" wire:model="jobs.{{ $i }}.from_year" min="1950" max="{{ now()->year }}" inputmode="numeric" optional />
                    @unless ($job['current'])
                        <x-pulse.field :name="'jobs.'.$i.'.to_year'" :id="'job-to-'.$i" type="number" label="To year" wire:model="jobs.{{ $i }}.to_year" min="1950" max="{{ now()->year }}" inputmode="numeric" optional />
                    @endunless
                </div>
                <div class="me-job__flags">
                    <x-pulse.toggle :name="'jobs.'.$i.'.current'" :id="'job-current-'.$i" label="I still work here" :checked="$job['current']" wire:model.live="jobs.{{ $i }}.current" />
                    <x-pulse.toggle :name="'jobs.'.$i.'.hide'" :id="'job-hide-'.$i" label="Hide me from this employer" :hint="filled($job['org']) ? 'Employer pages named “'.$job['org'].'” will never find you.' : 'Add the employer name first.'" :checked="$job['hide']" wire:model="jobs.{{ $i }}.hide" />
                </div>
                <x-pulse.button variant="ghost" size="sm" wire:click="removeJob({{ $i }})" type="button" :aria-label="'Remove job '.($i + 1)">Remove this job</x-pulse.button>
            </fieldset>
        @endforeach
        @if (count($jobs) < 8)
            <div><x-pulse.button variant="quiet" size="sm" icon="plus" wire:click="addJob" type="button">Add a job</x-pulse.button></div>
        @endif
    </section>

    <div class="me-savebar">
        <x-pulse.button variant="signal" type="submit" wire:loading.attr="disabled" wire:target="save">Save profile</x-pulse.button>
        @if ($errors->any())<p role="alert" class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>Fix the {{ $errors->count() === 1 ? 'line' : 'lines' }} marked above, then save again.</span></p>@endif
        <x-pulse.button variant="ghost" :href="pulse_route('users.show', ['profileUser' => auth()->id()])">See my page</x-pulse.button>
    </div>
</form>
