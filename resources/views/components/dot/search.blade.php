{{--
  <x-dot.search>: the Dot header search pill. Presentation only: no search logic, no routes, no required JS.
  Works as a plain GET form. Copied from InfoDot's header search (.ix-search), see docs/SEARCH.md.

  Props
    name         input name (default "search")
    placeholder  placeholder text
    value        current value
    action       form action URL (omit for a Livewire/JS-only host)
    method       form method (default get)
    label        visually hidden label and submit-button name (default "Search")
    wire         optional Livewire property: renders wire:model.live.debounce.400ms="<wire>" on the input
    hint         optional shortcut hint shown inside the pill, e.g. "Ctrl K" (default: none). With no hint the root gets
                 .dsr--nohint, which reserves only the circle's width on the right instead of 104px
    id           optional input id. Default: "dsr-<name>", the same on every render (snapshots, Livewire morphs). Pass
                 an id when two searches with the same name share a page.
    form-attributes  optional attributes for the <form> (array or attribute bag): wire:submit.prevent, data-*, id, class...
                 Merged over role="search", method and action; the host's values win.
  Slot
    results      the host's dropdown markup, rendered inside .dsr-panel
  Other attributes are merged onto the <input>.
--}}
@props([
    'name' => 'search',
    'placeholder' => 'Search',
    'value' => '',
    'action' => null,
    'method' => 'get',
    'label' => 'Search',
    'wire' => null,
    'hint' => null,
    'id' => null,
    'formAttributes' => [],
])
@php
    $inputId = $id ?? $attributes->get('id') ?? 'dsr-'.(trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-') ?: 'search');
    $formBag = $formAttributes instanceof \Illuminate\View\ComponentAttributeBag ? $formAttributes : new \Illuminate\View\ComponentAttributeBag((array) $formAttributes);
    $formDefaults = ['role' => 'search', 'method' => $method] + ($action ? ['action' => $action] : []);
    $listId = $inputId.'-list';
    $hasResults = isset($results) && ! $results->isEmpty();
    $inputAttrs = [
        'id' => $inputId,
        'type' => 'search',
        'name' => $name,
        'value' => $value,
        'placeholder' => $placeholder,
        'autocomplete' => 'off',
    ];
    if ($hasResults) {
        $inputAttrs += ['role' => 'combobox', 'aria-autocomplete' => 'list', 'aria-controls' => $listId, 'aria-expanded' => 'false'];
    }
@endphp
<div @class(['dsr', 'dsr--nohint' => ! $hint]) data-dsr @if($hint) data-dsr-shortcut @endif>
    <label for="{{ $inputId }}" class="dsr-sr">{{ $label }}</label>
    <form {{ $formBag->merge($formDefaults) }}>
        <input
            {{ $attributes->except(['id'])->merge($inputAttrs)->class(['dsr-input']) }}
            @if($wire) wire:model.live.debounce.400ms="{{ $wire }}" @endif
        />
        @if($hint)<kbd class="dsr-kbd" aria-hidden="true">{{ $hint }}</kbd>@endif
        <button type="submit" class="dsr-go" aria-label="{{ $label }}"><span class="dsr-arrow" aria-hidden="true"></span></button>
    </form>
    @if($hasResults)
        <div id="{{ $listId }}" class="dsr-panel" role="listbox" aria-label="Suggestions" tabindex="-1">{{ $results }}</div>
    @endif
</div>
