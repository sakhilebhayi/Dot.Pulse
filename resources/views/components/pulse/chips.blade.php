{{--
    pulse/chips
    Multi-select tags: real checkboxes under a fieldset and legend, styled as chips. The selected chip is yellow and shows a check.
    Extra attributes (wire:model="topics") go to every checkbox, so it binds to an array.

    Props: name (the array name, e.g. topics), legend, options (value => label), selected (array of values)
--}}
@props(['name', 'legend', 'options' => [], 'selected' => []])
@php $selected = array_map('strval', (array) old($name, $selected)); @endphp
<fieldset class="pk-chips">
    <legend>{{ $legend }}</legend>
    <div class="pk-chips__list">
        @foreach ($options as $value => $label)
            <label class="pk-chip">
                <input type="checkbox" name="{{ $name }}[]" value="{{ $value }}" @checked(in_array((string) $value, $selected, true)) {{ $attributes }}>
                <span><x-pulse.icon name="check" :size="14" />{{ $label }}</span>
            </label>
        @endforeach
    </div>
</fieldset>
