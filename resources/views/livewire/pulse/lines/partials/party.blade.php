{{--
    The other side of a line, as the viewer may know them. For an anonymous professional that is an alias and nothing else.
    Needs: $cp (LineService::present()['counterpart']), optional $size.
--}}
@php
    $size = $size ?? 'md';
    $partyName = $cp['kind'] === 'anonymous' ? ($cp['alias'] ?? 'Anonymous professional') : $cp['name'];
@endphp
@if ($cp['kind'] === 'entity')
    <x-pulse.avatar :name="$partyName" :src="\App\Livewire\Pulse\Lines\Support\Words::logo($cp['logo_media_id'] ?? null)" :size="$size" entity />
@elseif ($cp['kind'] === 'anonymous')
    <x-pulse.avatar name="Anonymous professional" :size="$size" class="ln-anon" />
@else
    <x-pulse.avatar :name="$partyName" :src="\App\Livewire\Pulse\Lines\Support\Words::avatarSrc($cp['avatar_url'] ?? null)" :size="$size" />
@endif
