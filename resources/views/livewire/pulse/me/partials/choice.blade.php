{{-- One option in a radio group: a real radio input in a labelled card. Variables: $name, $value, $title, $text, $model (wire:model attribute value), $live (bool) --}}
<label class="me-choice">
    <input type="radio" name="{{ $name }}" value="{{ $value }}" {{ ($live ?? false) ? 'wire:model.live='.$model : 'wire:model='.$model }}>
    <span class="me-choice__mark" aria-hidden="true"><x-pulse.icon name="check" :size="14" /></span>
    <span class="me-choice__body">
        <span class="me-choice__title">{{ $title }}</span>
        <span class="me-choice__text">{{ $text }}</span>
    </span>
</label>
