{{-- The composer. Needs: $line, $limit. Links and contact details unlock once the other side has replied; the page says so. --}}
@php $links = $line['can']['links']; @endphp
<form class="ln-composer" wire:submit="send" x-data="{ n: 0 }" x-on:composer-sent.window="n = 0" aria-label="Write a message">
    <div class="pk-field{{ $errors->has('body') ? ' pk-field--invalid' : '' }}">
        <label for="ln-body">Message</label>
        <textarea id="ln-body" class="pk-control ln-composer__text" wire:model="body" rows="2" maxlength="{{ $limit }}" placeholder="Say it plainly." aria-describedby="ln-body-hint{{ $errors->has('body') ? ' ln-body-error' : '' }}" @if($errors->has('body')) aria-invalid="true" @endif
            x-on:input="n = $event.target.value.length; $event.target.style.height = 'auto'; $event.target.style.height = Math.min($event.target.scrollHeight, 200) + 'px'"
            x-on:keydown.ctrl.enter.prevent="$wire.send()" x-on:keydown.meta.enter.prevent="$wire.send()"></textarea>
        @error('body')<p class="pk-field__error" id="ln-body-error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
    </div>

    <div class="ln-composer__bar">
        @if ($links)
            @if ($image)
                <div class="ln-preview">
                    <img src="{{ $image->temporaryUrl() }}" alt="Photo to send">
                    <button type="button" class="ln-linkbtn" wire:click="removeImage">Remove photo</button>
                </div>
            @else
                <label class="pk-btn pk-btn--quiet pk-btn--sm ln-attach">
                    <x-pulse.icon name="plus" :size="16" />Photo
                    <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" wire:model="image" aria-label="Add a photo">
                </label>
            @endif
        @endif
        <p class="ln-composer__hint" id="ln-body-hint">
            @if ($links)
                Links and contact details are fine now. Ctrl or Cmd and Enter sends.
            @else
                No links or contact details until they reply. Photos unlock then too.
            @endif
        </p>
        <span class="pk-mono ln-composer__count" x-show="n > 0" x-cloak x-text="n + ' / {{ $limit }}'">0 / {{ $limit }}</span>
        <x-pulse.button type="submit" variant="primary" wire:loading.attr="disabled" wire:target="send">Send</x-pulse.button>
    </div>
    <span wire:loading wire:target="image" class="ln-muted">Uploading</span>
    @error('image')<p class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
</form>
