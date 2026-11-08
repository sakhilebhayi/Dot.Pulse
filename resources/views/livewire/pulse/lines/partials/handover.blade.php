{{--
    Handover guidance for lines about buying, selling and hiring (config pulse.safety). It is guidance, not protection.
    Dismissed per line, remembered in this browser only. Needs: $line (present()), $lineId.
--}}
@if (! empty($line['guidance']))
    <section class="ln-handover" wire:ignore x-data="{
        key: 'pulse-handover-{{ $lineId }}',
        hidden: false,
        init() { try { this.hidden = localStorage.getItem(this.key) === '1'; } catch (e) {} },
        hide() { this.hidden = true; try { localStorage.setItem(this.key, '1'); } catch (e) {} },
        show() { this.hidden = false; try { localStorage.removeItem(this.key); } catch (e) {} },
    }" aria-labelledby="handover-{{ $lineId }}">
        <div x-show="!hidden">
            <h2 id="handover-{{ $lineId }}"><x-pulse.icon name="shield" :size="18" />Before you hand anything over</h2>
            <ul>
                @foreach ($line['guidance'] as $tip)<li>{{ $tip }}</li>@endforeach
            </ul>
            <p class="ln-handover__note">This is guidance, not protection. Pulse cannot hold your money or check a handover.</p>
            <button type="button" class="ln-linkbtn" x-on:click="hide()">Got it, hide this</button>
        </div>
        <button type="button" class="ln-linkbtn" x-show="hidden" x-cloak x-on:click="show()">Show handover guidance</button>
    </section>
@endif
