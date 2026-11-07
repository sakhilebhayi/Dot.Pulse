{{--
    pulse/select
    A labelled select. Props as pulse/field plus options (value => label) and placeholder (an empty first option).
--}}
@props(['name', 'label', 'options' => [], 'placeholder' => null, 'hint' => null, 'error' => null, 'optional' => false, 'value' => null, 'id' => null])
<x-pulse.field as="select" :name="$name" :label="$label" :options="$options" :placeholder="$placeholder" :hint="$hint" :error="$error" :optional="$optional" :value="$value" :id="$id" {{ $attributes }} />
