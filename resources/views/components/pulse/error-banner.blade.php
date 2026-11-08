{{--
    pulse/error-banner
    One banner for the refusals that have no field of their own ("This was removed by a moderator", "You can have 10 open
    needs"). An error that belongs to a field the form shows is said under that field only, never here as well: pass the
    names of those fields in `inline`. Nothing renders when every error already has its place.
    Props: inline (array of error keys the form shows next to a control)
--}}
@props(['inline' => []])
@php
    $shown = collect($errors->getMessages())
        ->reject(fn ($messages, $key) => in_array($key, $inline, true) || in_array(preg_replace('/\.\d+(\.|$)/', '.*$1', $key), $inline, true))
        ->map(fn ($messages) => $messages[0] ?? null)
        ->filter()
        ->unique()
        ->values();
@endphp
@if ($shown->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'bz-notice bz-notice--bad']) }} role="alert">
        <x-pulse.icon name="alert" :size="18" />
        <p>{{ $shown->first() }}</p>
    </div>
@endif
