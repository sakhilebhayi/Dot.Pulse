@auth
    <x-app-layout :title="$entity->name" width="wide" :description="$entity->tagline">
        @include('pulse.business.partials.page', ['d' => $data, 'member' => true])
    </x-app-layout>
@else
    <x-public-layout :title="$entity->name.' on Dot.Pulse'" :description="$entity->tagline ?: 'A business on Dot.Pulse.'">
        <div class="pub-wrap" style="padding-block: 24px 64px">
            @include('pulse.business.partials.page', ['d' => $data, 'member' => false])
        </div>
    </x-public-layout>
@endauth
