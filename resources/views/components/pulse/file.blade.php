{{--
    pulse/file
    A picture picker that looks like the rest of the kit: a drop zone that shows what people will see (the preview, or a plain
    "none yet"), one button to choose or replace, and Remove (with Undo) when there is a current picture. The native input is
    still the control (visually hidden, keyboard focusable); a click or a drop on the zone opens it.

    Props: name, label, id (defaults to name), accept, hint, optional (bool), shape (logo|cover), preview (url of what is
           shown now, or null), empty (the words when there is none), removed (bool), removeModel (Livewire property the Remove button flips; omit for no Remove)
    Extra attributes (wire:model) go to the input.
--}}
@props(['name', 'label', 'id' => null, 'accept' => 'image/jpeg,image/png,image/webp', 'hint' => null, 'optional' => false, 'shape' => 'logo', 'preview' => null, 'empty' => 'None yet', 'removed' => false, 'removeModel' => null])
@php
    $id = $id ?: $name;
    $err = isset($errors) && $errors->has($name) ? $errors->first($name) : null;
    $shown = $removed ? null : $preview;
@endphp
<div class="pk-file pk-file--{{ $shape }}{{ $err ? ' pk-file--invalid' : '' }}" x-data="{ over: false }">
    <label class="pk-file__label" for="{{ $id }}">{{ $label }}@if ($optional) <span class="pk-field__opt">optional</span>@endif</label>
    @if ($hint)<p class="pk-field__hint" id="{{ $id }}-hint">{{ $hint }}</p>@endif
    <input type="file" id="{{ $id }}" name="{{ $name }}" class="pk-file__input" accept="{{ $accept }}" aria-describedby="{{ trim(($hint ? $id.'-hint ' : '').($err ? $id.'-error' : '')) }}" {{ $attributes }}>
    <label class="pk-file__zone" for="{{ $id }}" x-bind:class="{ 'is-over': over }" x-on:dragover.prevent="over = true" x-on:dragleave="over = false" x-on:drop="over = false">
        <span class="pk-file__frame">
            @if ($shown)<img src="{{ $shown }}" alt="">@else<span class="pk-file__empty">{{ $removed ? 'Will be removed when you save' : $empty }}</span>@endif
        </span>
        <span class="pk-file__cta"><x-pulse.icon name="plus" :size="16" />{{ $shown ? 'Replace' : 'Choose a picture' }}<span class="pk-file__or"> or drop one here</span></span>
    </label>
    <p class="pk-file__status" wire:loading wire:target="{{ $name }}">Uploading</p>
    @if ($err)<p class="pk-field__error" id="{{ $id }}-error"><x-pulse.icon name="alert" :size="16" /><span>{{ $err }}</span></p>@endif
    @if ($removeModel && ($preview || $removed))
        <button type="button" class="pk-file__remove" wire:click="$set('{{ $removeModel }}', {{ $removed ? 'false' : 'true' }})">{{ $removed ? 'Undo remove' : 'Remove' }}</button>
    @endif
</div>
