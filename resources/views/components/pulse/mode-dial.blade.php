{{--
    pulse/mode-dial
    The segmented control that changes what the Floor asks for: All, Trade, Work, Local, Learn. The selected segment is yellow.
    Server navigation by default (each segment is a link to its named route, falling back to '#' when the route is not built).
    Pass livewire="setMode" to render buttons that call wire:click="setMode('trade')" instead, so the dial changes the query,
    not the page.
    Props: selected (all|trade|work|local|learn), livewire (method name or null), modes (value => label), label (aria-label)
--}}
@props([
    'selected' => 'all',
    'livewire' => null,
    'modes' => ['all' => 'All', 'trade' => 'Trade', 'work' => 'Work', 'local' => 'Local', 'learn' => 'Learn'],
    'label' => 'Floor mode',
    'page' => false,
])
@php $routes = ['all' => 'floor', 'trade' => 'trade', 'work' => 'work', 'local' => 'local', 'learn' => 'learn']; @endphp
<div {{ $attributes->merge(['class' => 'pk-dial'.($page ? ' pk-dial--page' : '')]) }} role="group" aria-label="{{ $label }}">
    @foreach ($modes as $value => $text)
        @if ($livewire)
            <button type="button" class="pk-dial__seg" wire:click="{{ $livewire }}('{{ $value }}')" aria-pressed="{{ $selected === $value ? 'true' : 'false' }}">{{ $text }}</button>
        @else
            <a class="pk-dial__seg" href="{{ pulse_route($routes[$value] ?? 'floor') }}" @if ($selected === $value) aria-current="page" @endif>{{ $text }}</a>
        @endif
    @endforeach
</div>
