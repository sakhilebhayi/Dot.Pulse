{{--
    pulse/field
    A labelled form control with a hint and an error, wired for assistive tech (label for, aria-describedby, aria-invalid).
    By default it renders an input of the given type; as="textarea" or as="select" renders those. Or pass your own control in
    the slot and give it the same id. Extra attributes (wire:model, required, autocomplete, rows, maxlength) go to the control.

    Props: name, label, id (defaults to name), type (text), as (input|textarea|select), value, hint, error (string; when omitted the
           validation error bag for `name` is used), optional (bool, adds "optional"), options (for select: value => label), placeholder
--}}
@props(['name', 'label', 'id' => null, 'type' => 'text', 'as' => 'input', 'value' => null, 'hint' => null, 'error' => null, 'optional' => false, 'options' => [], 'placeholder' => null])
@php
    $id = $id ?: $name;
    $err = $error ?? (isset($errors) && $errors->has($name) ? $errors->first($name) : null);
    $described = trim(($hint ? $id.'-hint ' : '').($err ? $id.'-error' : ''));
    $controlAttrs = [
        'id' => $id,
        'name' => $name,
        'class' => 'pk-control',
        'aria-invalid' => $err ? 'true' : null,
        'aria-describedby' => $described ?: null,
    ];
@endphp
<div class="pk-field{{ $err ? ' pk-field--invalid' : '' }}">
    <label for="{{ $id }}">{{ $label }}@if ($optional) <span class="pk-field__opt">optional</span>@endif</label>
    @if ($hint)<p class="pk-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    @if (trim((string) $slot) !== '')
        {{ $slot }}
    @elseif ($as === 'textarea')
        <textarea {{ $attributes->merge($controlAttrs) }}>{{ old($name, $value) }}</textarea>
    @elseif ($as === 'select')
        <select {{ $attributes->merge($controlAttrs) }}>
            @if ($placeholder)<option value="">{{ $placeholder }}</option>@endif
            @foreach ($options as $optValue => $optLabel)
                <option value="{{ $optValue }}" @selected((string) old($name, $value) === (string) $optValue)>{{ $optLabel }}</option>
            @endforeach
        </select>
    @else
        <input type="{{ $type }}" value="{{ old($name, $value) }}" @if($placeholder) placeholder="{{ $placeholder }}" @endif {{ $attributes->merge($controlAttrs) }}>
    @endif
    @if ($err)<p class="pk-field__error" id="{{ $id }}-error"><x-pulse.icon name="alert" :size="16" /><span>{{ $err }}</span></p>@endif
</div>
