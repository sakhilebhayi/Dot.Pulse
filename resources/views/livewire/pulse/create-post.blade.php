<div class="pk-card lg-compose" id="share">
    <button type="button" wire:click="$toggle('showForm')" class="lg-compose__open" aria-expanded="{{ $showForm ? 'true' : 'false' }}" aria-controls="compose-form">
        Share something with the community
    </button>

    @if($showForm)
        <form id="compose-form" wire:submit="publish" class="pk-stack">
            <div class="lg-grid-2">
                <x-pulse.field as="select" id="livewire-pulse-create-post-type" name="type" label="Type" wire:model="type"
                    :options="collect(\App\Models\PulsePost::$types)->mapWithKeys(fn ($t) => [$t => ucfirst(str_replace('_', ' ', $t))])->all()" />
                <x-pulse.field as="select" id="livewire-pulse-create-post-communityid" name="communityId" label="Community" optional wire:model="communityId"
                    placeholder="No community"
                    :options="$this->communities->mapWithKeys(fn ($c) => [$c->id => $c->name.($c->isPrivate() ? ' (private)' : '')])->all()" />
            </div>

            <x-pulse.field id="livewire-pulse-create-post-title" name="title" label="Title" optional placeholder="Give your post a title" wire:model="title" />
            <x-pulse.field as="textarea" id="livewire-pulse-create-post-body" name="body" label="Content" placeholder="What do you want to share?" rows="4" wire:model="body" />

            <div class="pk-cluster">
                <x-pulse.button type="submit" wire:loading.attr="disabled">
                    <span wire:loading.remove>Publish</span>
                    <span wire:loading>Posting</span>
                </x-pulse.button>
                <x-pulse.button variant="quiet" wire:click="$set('showForm', false)">Cancel</x-pulse.button>
                <p class="pk-muted lg-compose__note">Posts are checked automatically. Anything uncertain waits for a person.</p>
            </div>
        </form>
    @endif
</div>
