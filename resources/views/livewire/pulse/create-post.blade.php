<div class="dot-card" style="padding:1.25rem 1.5rem;">
    <button
        wire:click="$toggle('showForm')"
        class="dot-input"
        style="text-align:left;cursor:pointer;color:#71717a;"
    >
        Share something with the community...
    </button>

    @if($showForm)
        <form wire:submit="publish" style="margin-top:1rem;display:flex;flex-direction:column;gap:0.9rem;">
            <div class="form-grid-2">
                <div>
                    <label for="livewire-pulse-create-post-type" style="display:block;font-size:12px;font-weight:600;color:#a1a1aa;margin-bottom:6px;">Type</label>
                    <select id="livewire-pulse-create-post-type" wire:model="type" class="dot-input">
                        @foreach(\App\Models\PulsePost::$types as $t)
                            <option value="{{ $t }}">{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
                        @endforeach
                    </select>
                    @error('type') <p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label for="livewire-pulse-create-post-communityid" style="display:block;font-size:12px;font-weight:600;color:#a1a1aa;margin-bottom:6px;">Community (optional)</label>
                    <select id="livewire-pulse-create-post-communityid" wire:model="communityId" class="dot-input">
                        <option value="">No community</option>
                        @foreach($this->communities as $community)
                            <option value="{{ $community->id }}">{{ $community->name }}{{ $community->isPrivate() ? ' (private)' : '' }}</option>
                        @endforeach
                    </select>
                    @error('communityId') <p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label for="livewire-pulse-create-post-title" style="display:block;font-size:12px;font-weight:600;color:#a1a1aa;margin-bottom:6px;">Title (optional)</label>
                <input id="livewire-pulse-create-post-title" wire:model="title" type="text" placeholder="Give your post a title..." class="dot-input" />
                @error('title') <p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p> @enderror
            </div>

            <div>
                <label for="livewire-pulse-create-post-body" style="display:block;font-size:12px;font-weight:600;color:#a1a1aa;margin-bottom:6px;">Content</label>
                <textarea id="livewire-pulse-create-post-body" wire:model="body" rows="4" placeholder="What do you want to share?" class="dot-input"></textarea>
                @error('body') <p style="color:#f87171;font-size:11px;margin:4px 0 0;">{{ $message }}</p> @enderror
            </div>

            <div class="inline-form-row" style="gap:10px 14px;padding-top:2px;">
                <button type="submit" class="dot-btn dot-btn-primary" wire:loading.attr="disabled" style="opacity:1;">
                    <span wire:loading.remove>Publish</span>
                    <span wire:loading>Posting…</span>
                </button>
                <button type="button" wire:click="$set('showForm', false)" class="tap" style="background:none;border:none;font-size:13px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Cancel</button>
                <span style="font-size:11px;color:#52525b;margin-left:auto;flex:1 1 14rem;">Posts are checked automatically. Anything uncertain waits for a person.</span>
            </div>
        </form>
    @endif
</div>
