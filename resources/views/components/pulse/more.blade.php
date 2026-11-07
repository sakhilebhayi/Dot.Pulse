{{--
    pulse/more
    An overflow menu for the actions that are not the point of the screen: archive, pause, delete. Dangerous ones sit last,
    set apart, and open a confirm dialog that names the thing (x-pulse.confirm), never a bare browser prompt.
    Works without scripting (a details element); Alpine closes it on an outside click or Escape.

    Props: label (the button text, default "More")
    Slot: buttons with class "pk-more__item" (add "pk-more__item--danger" for the destructive one, with data-pk-dialog="confirm-id")
--}}
@props(['label' => 'More'])
<details {{ $attributes->merge(['class' => 'pk-more']) }} x-data x-on:click.outside="$el.removeAttribute('open')" x-on:keydown.escape="$el.removeAttribute('open')">
    <summary class="pk-btn pk-btn--quiet pk-btn--sm" aria-haspopup="true">{{ $label }}</summary>
    <div class="pk-more__panel">{{ $slot }}</div>
</details>
