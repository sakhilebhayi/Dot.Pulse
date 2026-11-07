<div>
    @if ($notice)
        <div class="me-note" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ $notice }}</p></div>
    @endif

    @if ($this->pending->isNotEmpty())
        <section class="pk-card me-section" aria-labelledby="inv-title">
            <h2 id="inv-title">Invitations to join a team</h2>
            <p class="me-section__text">Say yes and you join with the role shown. Say no and nothing changes for you. They expire after 14 days.</p>
            <div class="pk-stack">
                @foreach ($this->pending as $inv)
                    <x-pulse.row :title="$inv->entity->name" :meta="'As '.$inv->role.($inv->inviter ? ', invited by '.$inv->inviter->name : '').'. Expires '.$inv->expires_at->format('j M')" wire:key="inv-{{ $inv->id }}">
                        <x-slot name="end">
                            <div class="pk-cluster">
                                <x-pulse.button size="sm" variant="primary" wire:click="accept({{ $inv->id }})">Join</x-pulse.button>
                                <x-pulse.button size="sm" variant="quiet" wire:click="decline({{ $inv->id }})">No thanks</x-pulse.button>
                            </div>
                        </x-slot>
                    </x-pulse.row>
                @endforeach
            </div>
        </section>
    @endif
</div>
