@php
    use App\Livewire\Pulse\Business\Support\EntityView;
    $statusWords = ['draft' => ['warn', 'Draft'], 'live' => ['good', 'Open'], 'paused' => ['warn', 'Paused'], 'closed' => ['neutral', 'Closed'], 'filled' => ['good', 'Filled'], 'removed' => ['bad', 'Removed']];
@endphp
<div class="bz">
    @if ($notice)
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ $notice }}</p></div>
    @endif

    @if (! $this->entity->is_employer)
        <x-pulse.empty title="This page does not hire yet" text="Turn on hiring in the Page tab to add roles and a hiring signal." action="Open the Page tab" :href="route('entities.manage.page', ['entity' => $this->entity->slug])" />
    @elseif ($mode === 'list')
        <section class="pk-card bz-signal" aria-labelledby="hs-title">
            <h2 id="hs-title">Hiring signal</h2>
            <p>Meet people before you advertise. With a signal on, professionals whose skills fit can find your page, even with no role open. They stay anonymous to you until they choose to say who they are. You only see how many match once at least 5 do, so nobody can be picked out of a small group.</p>
            @if ($this->cohort)
                <p><strong>{{ $this->cohort }} professionals match.</strong> <span class="pk-muted">A range, never an exact count, and nobody is named.</span></p>
            @endif
            <form class="pk-stack" wire:submit="saveSignal" novalidate>
                <x-pulse.select name="hiring_signal" id="hs-signal" label="Signal" wire:model="hiring_signal" :options="['none' => 'Off', 'building' => 'Building a team, no vacancy yet', 'active' => 'Hiring now']" :disabled="! $this->canSignal" />
                <x-pulse.field name="hiring_skills_text" id="hs-skills" label="Skills you want to meet" wire:model="hiring_skills_text" optional hint="Separated by commas. Up to 15." :disabled="! $this->canSignal" />
                @if (! $this->canSignal)<p class="pk-muted">Only an owner or an admin can change the signal.</p>@endif
                <div class="pk-cluster">
                    <x-pulse.button type="submit" variant="primary" size="sm" :disabled="! $this->canSignal">Save signal</x-pulse.button>
                    @if ($this->entity->hiring_signal !== 'none' && $this->entity->hiring_signal_until)
                        <x-pulse.button type="button" variant="quiet" size="sm" wire:click="renewSignal" :disabled="! $this->canSignal">Renew</x-pulse.button>
                    @endif
                </div>
                @if ($this->entity->hiring_signal !== 'none' && $this->entity->hiring_signal_until)
                    <p class="pk-muted">@if ($this->entity->hiring_signal_until->isPast()) The signal has lapsed. Renew it to meet people again. @else The signal lapses on {{ $this->entity->hiring_signal_until->format('j M Y') }} unless you renew it. @endif</p>
                @endif
            </form>
        </section>

        <div class="bz-toolbar">
            <h2>Roles</h2>
            <x-pulse.button variant="signal" icon="plus" wire:click="newRole">New role</x-pulse.button>
        </div>

        @if ($this->roles->isEmpty())
            <x-pulse.empty title="No roles yet" text="A role says what you need done, where, and how people apply. Matching decides who sees it. A person always decides who gets it.">
                <x-pulse.button variant="signal" wire:click="newRole">Write the first one</x-pulse.button>
            </x-pulse.empty>
        @else
            <div class="bz-list">
                @foreach ($this->roles as $role)
                    @php [$tone, $word] = $statusWords[$role->status] ?? ['neutral', ucfirst($role->status)]; @endphp
                    <x-pulse.row :title="$role->title" :href="route('roles.show', ['entity' => $this->entity->slug, 'role' => $role->slug])" wire:key="role-{{ $role->id }}">
                        <div class="pk-cluster" style="margin-top: 6px">
                            <x-pulse.pill :tone="$tone">{{ $word }}</x-pulse.pill>
                            @if (! in_array($role->status, ['draft', 'removed'], true) && $role->moderation !== 'approved' && $role->status === 'live')
                                <x-pulse.pill tone="warn">Waiting for a human</x-pulse.pill>
                            @endif
                            @if ($role->closes_at)<span class="pk-muted">{{ $role->closes_at->isPast() ? 'Closed on' : 'Closes on' }} {{ $role->closes_at->format('j M Y') }}</span>@endif
                        </div>
                        @if ($role->status === 'live' && in_array($role->moderation, ['pending', 'held'], true))
                            <p class="pk-muted" style="margin-top: 6px">Waiting for a human: {{ $role->moderation_reason ?: ($role->moderation === 'held' ? 'The automatic check flagged something in the text.' : 'Pulse needs a person to look at this before it shows. Linking an InfoDot company helps next time.') }} {{ pulse_review_note() }}</p>
                        @endif
                        <x-slot name="end">
                            <div class="pk-cluster">
                                @if ($role->moderation !== 'removed')
                                    @unless (in_array($role->status, ['closed', 'filled'], true))
                                        <x-pulse.button size="sm" variant="quiet" wire:click="edit({{ $role->id }})">Edit</x-pulse.button>
                                    @endunless
                                    @if ($role->status === 'live')
                                        <x-pulse.button size="sm" variant="quiet" wire:click="pause({{ $role->id }})">Pause</x-pulse.button>
                                    @elseif (in_array($role->status, ['draft', 'paused'], true))
                                        <x-pulse.button size="sm" variant="primary" wire:click="publish({{ $role->id }})">Publish</x-pulse.button>
                                    @endif
                                @endif
                                <x-pulse.more>
                                    @if ($role->moderation !== 'removed' && in_array($role->status, ['live', 'paused'], true))
                                        <button type="button" class="pk-more__item" wire:click="markFilled({{ $role->id }})">Mark as filled</button>
                                        <button type="button" class="pk-more__item" wire:click="close({{ $role->id }})">Close this role</button>
                                    @endif
                                    <button type="button" class="pk-more__item pk-more__item--danger" data-pk-dialog="del-role-{{ $role->id }}">Delete this role</button>
                                </x-pulse.more>
                            </div>
                            <x-pulse.confirm id="del-role-{{ $role->id }}" :title="'Delete '.$role->title.'?'" text="This cannot be undone. Lines that started from it keep their own copy." label="Delete role" wire:click="delete({{ $role->id }})" />
                        </x-slot>
                    </x-pulse.row>
                @endforeach
            </div>
        @endif
    @else
        <form class="bz-form" wire:submit="save" novalidate>
            <div class="bz-toolbar">
                <h2>{{ $roleId ? 'Edit role' : 'New role' }}</h2>
                <x-pulse.button type="button" variant="quiet" wire:click="cancel">Back to roles</x-pulse.button>
            </div>

            @if ($errors->any())
                <div class="bz-notice bz-notice--bad" role="alert"><x-pulse.icon name="alert" :size="18" /><p>{{ $errors->first() }}</p></div>
            @endif

            <section class="bz-block" aria-labelledby="ro-what">
                <h3 id="ro-what">The role</h3>
                <x-pulse.field name="title" id="role-title" label="Title" wire:model="title" maxlength="140" required />
                <x-pulse.field name="summary" id="role-summary" label="One line" wire:model="summary" maxlength="240" hint="Shows on the card. Needed before you can publish." />
                <x-pulse.textarea name="description" id="role-description" label="Details" wire:model="description" rows="7" maxlength="6000" optional />
                <div class="bz-pair">
                    <x-pulse.select name="employment_type" id="role-type" label="Type" wire:model="employment_type" :options="EntityView::employment()" />
                    <x-pulse.select name="work_mode" id="role-mode" label="Where you work" wire:model="work_mode" :options="EntityView::workModes()" />
                </div>
                <x-pulse.select name="seniority" id="role-seniority" label="Level" wire:model="seniority" placeholder="Not stated" :options="['intern' => 'Intern', 'junior' => 'Junior', 'mid' => 'Mid', 'senior' => 'Senior', 'lead' => 'Lead', 'executive' => 'Executive']" />
            </section>

            <section class="bz-block" aria-labelledby="ro-skills">
                <h3 id="ro-skills">Skills and pay</h3>
                <div class="bz-combo">
                    <x-pulse.field name="skills_text" id="role-skills" label="Skills" wire:model.live.debounce.250ms="skills_text" optional autocomplete="off" hint="Separated by commas. These decide who sees the role." />
                    @if ($this->skillResults)
                        <ul class="bz-suggest" role="listbox" aria-label="Skill suggestions">
                            @foreach ($this->skillResults as $hit)
                                <li role="presentation"><button type="button" role="option" wire:click="addSkill({{ \Illuminate\Support\Js::from($hit['label']) }})">{{ $hit['label'] }}<span>{{ $hit['group'] }}</span></button></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <x-pulse.field name="pay_text" id="role-pay" label="Pay" wire:model="pay_text" maxlength="120" optional hint="In your own words, for example R25 000 to R35 000 a month. Pulse does not compare it." />
            </section>

            <section class="bz-block" aria-labelledby="ro-where">
                <h3 id="ro-where">Where and how to apply</h3>
                <div class="bz-combo">
                    <x-pulse.field name="place_query" id="role-place" label="Place" wire:model.live.debounce.250ms="place_query" optional autocomplete="off" />
                    @if ($place_id)<p class="bz-chosen"><x-pulse.icon name="pin" :size="14" />{{ $place_query }}</p>@endif
                    @if ($this->placeResults)
                        <ul class="bz-suggest" role="listbox" aria-label="Places">
                            @foreach ($this->placeResults as $hit)
                                <li role="presentation"><button type="button" role="option" wire:click="choosePlace({{ $hit['id'] }})">{{ $hit['label'] }}</button></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
                <x-pulse.select name="apply_mode" id="role-apply" label="People apply" wire:model.live="apply_mode" :options="['on_pulse' => 'On Pulse, with a short card', 'external_url' => 'On your own site']" />
                @if ($apply_mode === 'external_url')
                    <x-pulse.field name="apply_url" id="role-url" type="url" label="Application address" wire:model="apply_url" placeholder="https://" hint="People leave Pulse to apply, so Pulse cannot show what happens next." />
                @endif
                <x-pulse.field name="closes_at" id="role-closes" type="date" label="Closes on" wire:model="closes_at" optional hint="A role that closes says so on its page. Leave empty to close it yourself." />
            </section>

            <div class="bz-actions">
                <x-pulse.button variant="signal" type="button" wire:click="savePublish" wire:loading.attr="disabled">Save and publish</x-pulse.button>
                <x-pulse.button variant="quiet" type="submit" wire:loading.attr="disabled">{{ $roleId ? 'Save changes' : 'Save as draft' }}</x-pulse.button>
            </div>
        </form>
    @endif
</div>
