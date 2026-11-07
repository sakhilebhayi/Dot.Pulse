{{-- The composer. Needs: $line, $limit. Links and contact details unlock once the other side has replied; the page says so.
     One compact row (photo, text, Send) so it keeps to about 64px on a phone. The rules read as the placeholder on a phone, as a hint line on a desktop. --}}
@php $links = $line['can']['links']; @endphp
<form class="ln-composer" wire:submit="send" x-data="{ n: 0 }" x-on:composer-sent.window="n = 0; $refs.body.style.height = ''" aria-label="Write a message">
    @if ($links && $image)
        <div class="ln-preview">
            <img src="{{ $image->temporaryUrl() }}" alt="Photo to send">
            <button type="button" class="ln-linkbtn" wire:click="removeImage">Remove photo</button>
        </div>
    @endif

    <div class="ln-composer__row">
        @if ($links && ! $image)
            <label class="pk-btn pk-btn--quiet ln-attach" title="Add a photo">
                <x-pulse.icon name="plus" :size="18" /><span class="sr-only">Add a photo</span>
                <input type="file" class="sr-only" accept="image/jpeg,image/png,image/webp" wire:model="image" aria-label="Add a photo">
            </label>
        @endif
        <div class="pk-field ln-composer__field{{ $errors->has('body') ? ' pk-field--invalid' : '' }}">
            <label for="ln-body">Message</label>
            <textarea id="ln-body" x-ref="body" class="pk-control ln-composer__text" wire:model="body" rows="1" maxlength="{{ $limit }}" placeholder="{{ $links ? 'Say it plainly.' : 'Say it plainly. No links or contact details yet.' }}" aria-describedby="ln-body-hint{{ $errors->has('body') ? ' ln-body-error' : '' }}" @if($errors->has('body')) aria-invalid="true" @endif
                x-on:input="n = $event.target.value.length; $event.target.style.height = 'auto'; $event.target.style.height = Math.min($event.target.scrollHeight, 160) + 'px'"
                x-on:keydown.ctrl.enter.prevent="$wire.send()" x-on:keydown.meta.enter.prevent="$wire.send()"></textarea>
        </div>
        <x-pulse.button type="submit" variant="primary" class="ln-composer__send" wire:loading.attr="disabled" wire:target="send">Send</x-pulse.button>
    </div>

    @error('body')<p class="pk-field__error" id="ln-body-error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror

    <p class="ln-composer__hint" id="ln-body-hint">
        @if ($links)
            Links and contact details are fine now.<span class="ln-composer__keys"> Ctrl or Cmd and Enter sends.</span>
        @else
            No links or contact details until they reply. Photos unlock then too.
        @endif
        <span class="pk-mono ln-composer__count" x-show="n > {{ (int) ($limit * 0.8) }}" x-cloak x-text="n + ' / {{ $limit }}'">0 / {{ $limit }}</span>
    </p>
    <span wire:loading wire:target="image" class="ln-muted">Uploading</span>
    @error('image')<p class="pk-field__error"><x-pulse.icon name="alert" :size="16" /><span>{{ $message }}</span></p>@enderror
</form>
