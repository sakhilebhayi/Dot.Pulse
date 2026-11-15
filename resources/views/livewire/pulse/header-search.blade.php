<div class="sh-hsearch">
    <x-dot.search wire="query" id="mast-q" name="q" label="Search the floor" placeholder="Search the floor" :action="route('search')"
        results-nav escape-clears autocomplete="off" x-on:focus.once="$wire.touch()" :root-attributes="['wire:ignore.self' => '']">
        <x-slot:results>
            @if ($touched && $typed)
                <div class="dsr-quick">
                    @if ($limited)
                        <p class="dsr-foot" role="status">Too many searches for a moment. Wait a little, then try again.</p>
                    @else
                        @forelse ($rows as $row)
                            <x-dot.search-row :href="route('search', ['q' => $row['value']])" :label="$row['label']" :tag="$row['type']" wire:key="hs-{{ $loop->index }}-{{ md5($row['label'].$row['type']) }}" />
                        @empty
                            <p class="dsr-foot" role="status">No suggestion for &ldquo;{{ $safe }}&rdquo;. Press Enter to search the floor for it.</p>
                        @endforelse
                    @endif
                    <x-dot.search-row :href="route('search', ['q' => trim($query)])" :label="'Search the floor for “'.$safe.'”'" :action="true" wire:key="hs-all" />
                </div>
            @endif
        </x-slot:results>
    </x-dot.search>
</div>
