{{--
    pulse/when
    A moment in words: relative within a week ("3 h ago", "Yesterday"), "12 Nov" after, the exact time in the title.
    Props: at (Carbon, DateTime or ISO string), fallback (text when there is no moment; empty by default)
--}}
@props(['at' => null, 'fallback' => ''])
@php $c = \App\Support\Moment::carbon($at); @endphp
@if ($c)
    <time {{ $attributes }} datetime="{{ $c->toIso8601String() }}" title="{{ \App\Support\Moment::full($c) }}">{{ \App\Support\Moment::when($c) }}</time>
@elseif ($fallback !== '')
    <span {{ $attributes }}>{{ $fallback }}</span>
@endif
