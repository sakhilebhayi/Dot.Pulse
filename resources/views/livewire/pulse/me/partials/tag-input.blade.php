{{-- Typed-ahead chips. Variables: $field (skills|interests|languages), $label, $hint, $placeholder, $items (array), $suggestions (array) --}}
@php $id = 'tag-'.$field; @endphp
<div class="me-tags" wire:key="tags-{{ $field }}">
    <div class="pk-field">
        <label for="{{ $id }}">{{ $label }}</label>
        @if (! empty($hint))<p class="pk-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
        <div class="me-tags__add">
            <input id="{{ $id }}" class="pk-control" type="text" autocomplete="off" maxlength="40" placeholder="{{ $placeholder ?? 'Start typing' }}"
                   wire:model.live.debounce.150ms="tagQuery.{{ $field }}"
                   wire:keydown.enter.prevent="addTag('{{ $field }}')"
                   @if (! empty($hint)) aria-describedby="{{ $id }}-hint" @endif>
            <x-pulse.button variant="quiet" wire:click="addTag('{{ $field }}')" type="button">Add</x-pulse.button>
        </div>
    </div>
    @if (count($suggestions))
        <ul class="me-tags__suggest" aria-label="Suggestions for {{ strtolower($label) }}">
            @foreach ($suggestions as $s)
                <li><button type="button" class="me-chipbtn" wire:click="addTag('{{ $field }}', @js($s))"><x-pulse.icon name="plus" :size="14" />{{ $s }}</button></li>
            @endforeach
        </ul>
    @endif
    @if (count($items))
        <ul class="me-tags__list" aria-label="{{ $label }} you picked">
            @foreach ($items as $i => $item)
                <li wire:key="{{ $field }}-{{ $i }}-{{ $item }}"><span class="me-chip">{{ $item }}<button type="button" wire:click="removeTag('{{ $field }}', {{ $i }})" aria-label="Remove {{ $item }}"><x-pulse.icon name="x" :size="14" /></button></span></li>
            @endforeach
        </ul>
    @endif
</div>
