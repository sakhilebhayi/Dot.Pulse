<div class="bz">
    @if ($notice)
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ $notice }}</p></div>
    @endif

    <section class="pk-stack" aria-labelledby="tm-people">
        <h2 id="tm-people">People on this page</h2>
        <p class="pk-muted">Owners can do everything. Admins run the page, offers, roles and editors. Editors write offers and roles. Responders answer lines. Only your team sees this list.</p>
        <div class="bz-list">
            @foreach ($this->members as $m)
                <x-pulse.row :title="$m->user?->name ?? 'Former member'" :meta="$m->user?->email" wire:key="member-{{ $m->id }}">
                    <x-slot name="lead"><x-pulse.avatar :name="$m->user?->name ?? '?'" /></x-slot>
                    <x-slot name="end">
                        <div class="pk-cluster">
                            @if ($m->user_id === auth()->id())
                                <x-pulse.pill>{{ ucfirst($m->role) }}, you</x-pulse.pill>
                            @elseif ($this->myRole === 'owner' || in_array($m->role, ['editor', 'responder'], true))
                                <label class="sr-only" for="role-{{ $m->id }}">Role for {{ $m->user?->name }}</label>
                                <select id="role-{{ $m->id }}" class="pk-control bz-select-sm" wire:change="changeRole({{ $m->user_id }}, $event.target.value)">
                                    @foreach ($this->grantable as $value => $label)
                                        <option value="{{ $value }}" @selected($m->role === $value)>{{ $label }}</option>
                                    @endforeach
                                    @unless (array_key_exists($m->role, $this->grantable))<option value="{{ $m->role }}" selected>{{ ucfirst($m->role) }}</option>@endunless
                                </select>
                            @else
                                <x-pulse.pill>{{ ucfirst($m->role) }}</x-pulse.pill>
                            @endif
                            @if ($m->user_id === auth()->id())
                                <x-pulse.button size="sm" variant="quiet" wire:click="remove({{ $m->user_id }})" wire:confirm="Leave this page? You lose access to its lines and listings.">Leave</x-pulse.button>
                            @elseif ($this->myRole === 'owner' || in_array($m->role, ['editor', 'responder'], true))
                                <x-pulse.button size="sm" variant="danger" wire:click="remove({{ $m->user_id }})" wire:confirm="Remove {{ $m->user?->name }} from this page?">Remove</x-pulse.button>
                            @endif
                        </div>
                    </x-slot>
                </x-pulse.row>
            @endforeach
        </div>
    </section>

    <section class="pk-card pk-stack" aria-labelledby="tm-add">
        <h2 id="tm-add">Add someone</h2>
        <p class="pk-muted">They need to have signed in to Pulse through InfoDot once. Use the email on their InfoDot account.</p>
        <form class="bz-pair bz-pair--end" wire:submit="add" novalidate>
            <x-pulse.field name="email" id="team-email" type="email" label="Email" wire:model="email" autocomplete="off" required />
            <x-pulse.select name="role" id="team-role" label="Role" wire:model="role" :options="$this->grantable" />
            <div><x-pulse.button type="submit" variant="primary">Add to the team</x-pulse.button></div>
        </form>
    </section>
</div>
