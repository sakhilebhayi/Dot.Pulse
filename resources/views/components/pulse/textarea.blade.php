{{--
    pulse/textarea
    A labelled multi-line field. Same props as pulse/field (name, label, hint, error, optional); rows, maxlength, wire:model pass through.
--}}
@props(['name', 'label', 'hint' => null, 'error' => null, 'optional' => false, 'value' => null, 'id' => null])
<x-pulse.field as="textarea" :name="$name" :label="$label" :hint="$hint" :error="$error" :optional="$optional" :value="$value" :id="$id" {{ $attributes }} />
