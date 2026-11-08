{{--
    pulse/empty
    An honest empty state: what is missing, and the one thing to do about it. No cartoon, no fake activity.
    Props: title, text, action (button label, optional), href (action link, optional)
    Slot: extra content or a custom action (replaces the default button)
--}}
@props(['title', 'text' => null, 'action' => null, 'href' => null])
<div {{ $attributes->merge(['class' => 'pk-empty']) }}>
    <h2 class="pk-empty__title">{{ $title }}</h2>
    @if ($text)<p class="pk-empty__text">{{ $text }}</p>@endif
    @if (trim((string) $slot) !== '')
        <div class="pk-empty__action">{{ $slot }}</div>
    @elseif ($action && $href)
        <div class="pk-empty__action"><x-pulse.button variant="signal" :href="$href">{{ $action }}</x-pulse.button></div>
    @endif
</div>
