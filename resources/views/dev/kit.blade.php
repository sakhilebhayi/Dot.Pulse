@php
    $reasons3 = [
        ['code' => 'need_offer', 'text' => 'You need bookkeeping, they offer bookkeeping', 'weight' => 40],
        ['code' => 'place_region', 'text' => 'Both in Gauteng', 'weight' => 20],
        ['code' => 'responsive', 'text' => 'They usually answer within a day', 'weight' => 10],
    ];
    $rungs = [
        ['label' => 'InfoDot account', 'state' => 'checked', 'at' => now()->subDays(40), 'source' => 'InfoDot'],
        ['label' => 'Business registration number', 'state' => 'claimed', 'detail' => 'Entered by the business'],
        ['label' => 'Website', 'state' => 'claimed', 'detail' => 'Entered by the business'],
        ['label' => 'Trade licence', 'state' => 'missing'],
    ];
    $split = request()->boolean('split');
@endphp
<x-app-layout title="Kit" width="wide">
    <x-slot name="header">
        <div>
            <h1 style="font-size: clamp(2rem, 1.4rem + 2vw, 3rem)">The kit</h1>
            <p>Every component on the Floor, with sample text. Nothing here is real data.</p>
        </div>
        <div class="pk-cluster">
            <x-pulse.button variant="quiet" size="sm" href="{{ url('/dev/kit') }}?theme=light">Light</x-pulse.button>
            <x-pulse.button variant="quiet" size="sm" href="{{ url('/dev/kit') }}?theme=dark">Dark</x-pulse.button>
            <x-pulse.button variant="quiet" size="sm" href="{{ url('/dev/kit') }}?split=1">Side by side</x-pulse.button>
        </div>
    </x-slot>

    @if ($split)
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px">
            <iframe title="Kit, light" src="{{ url('/dev/kit') }}?theme=light" style="width: 100%; height: 80vh; border: 1px solid var(--c-line); border-radius: 18px"></iframe>
            <iframe title="Kit, dark" src="{{ url('/dev/kit') }}?theme=dark" style="width: 100%; height: 80vh; border: 1px solid var(--c-line); border-radius: 18px"></iframe>
        </div>
    @else
    <div class="pk-stack" style="gap: 48px">

        <section aria-labelledby="k-buttons" class="pk-stack">
            <h2 id="k-buttons">Buttons</h2>
            <div class="pk-cluster">
                <x-pulse.button variant="primary">Knock</x-pulse.button>
                <x-pulse.button variant="signal" icon="plus">Say what you want</x-pulse.button>
                <x-pulse.button variant="quiet">Save</x-pulse.button>
                <x-pulse.button variant="danger">Block</x-pulse.button>
                <x-pulse.button variant="primary" href="#">As a link</x-pulse.button>
                <x-pulse.button variant="quiet" size="sm">Small</x-pulse.button>
                <x-pulse.button variant="primary" disabled>Disabled</x-pulse.button>
            </div>
        </section>

        <section aria-labelledby="k-dial" class="pk-stack">
            <h2 id="k-dial">Mode dial</h2>
            <div><x-pulse.mode-dial selected="trade" page label="Floor mode, example" /></div>
        </section>

        <section aria-labelledby="k-small" class="pk-stack">
            <h2 id="k-small">Kind words, pills, place, price, avatars</h2>
            <div class="pk-cluster">
                @foreach (['offer', 'need', 'role', 'talent', 'ask', 'event'] as $k)<x-pulse.kind-word :kind="$k" />@endforeach
            </div>
            <div class="pk-cluster">
                <x-pulse.pill>Open to knocks</x-pulse.pill>
                <x-pulse.pill tone="good">Checked</x-pulse.pill>
                <x-pulse.pill tone="warn">Claimed, not checked</x-pulse.pill>
                <x-pulse.pill tone="bad">Declined</x-pulse.pill>
                <x-pulse.pill tone="signal">Selected</x-pulse.pill>
            </div>
            <div class="pk-cluster">
                <x-pulse.place-label name="Soweto, Gauteng" />
                <x-pulse.place-label :remote="true" />
                <x-pulse.place-label reach="national" />
                <x-pulse.price :amount="1200" unit="per month" />
                <x-pulse.price :amount="350" :to="600" unit="per hour" />
                <x-pulse.price :amount="null" />
                <x-pulse.price :amount="18500" large />
            </div>
            <div class="pk-cluster">
                <x-pulse.avatar name="Thandi Mokoena" size="sm" />
                <x-pulse.avatar name="Thandi Mokoena" />
                <x-pulse.avatar name="Thandi Mokoena" size="lg" member />
                <x-pulse.avatar name="Sample Books" entity size="lg" />
            </div>
        </section>

        <section aria-labelledby="k-beat" class="pk-stack">
            <h2 id="k-beat">Beat</h2>
            <div style="max-width: 680px">
                <x-pulse.beat kind="offer" title="Bookkeeping for small shops and sole traders" href="#" place="Johannesburg, Gauteng" time="2 h ago" open>
                    Monthly books, VAT returns and a report you can read.
                    <x-slot name="from">
                        <x-pulse.avatar name="Sample Books" entity />
                        <span class="pk-beat__from-text">
                            <span class="pk-beat__from-name">Sample Books</span>
                            <x-pulse.ladder :rungs="$rungs" summary />
                        </span>
                    </x-slot>
                    <x-slot name="meter"><x-pulse.sync-meter :reasons="$reasons3" remove="removeReason" /></x-slot>
                    <x-slot name="actions">
                        <x-pulse.button size="sm">Knock</x-pulse.button>
                        <x-pulse.button size="sm" variant="quiet" icon="bookmark">Save</x-pulse.button>
                    </x-slot>
                    <x-slot name="aside"><x-pulse.price :amount="1200" unit="per month" /></x-slot>
                </x-pulse.beat>
                <x-pulse.beat kind="need" title="Someone to paint a two bedroom flat in Pretoria" place="Pretoria, Gauteng" time="Today">
                    Walls and ceilings, paint supplied. Needed before the end of the month.
                    <x-slot name="actions">
                        <x-pulse.button size="sm">Knock</x-pulse.button>
                        <x-pulse.button size="sm" variant="quiet" icon="bookmark">Save</x-pulse.button>
                    </x-slot>
                </x-pulse.beat>
            </div>
        </section>

        <section aria-labelledby="k-meter" class="pk-stack">
            <h2 id="k-meter">Sync meter</h2>
            <div class="pk-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 24px">
                <div class="pk-card"><p class="pk-muted" style="margin-bottom: 8px">One reason</p><x-pulse.sync-meter :reasons="[['code' => 'topic', 'text' => 'You follow cycling, they sell bicycles']]" them-label="Cycle shop" /></div>
                <div class="pk-card"><p class="pk-muted" style="margin-bottom: 8px">Two reasons</p><x-pulse.sync-meter :reasons="array_slice($reasons3, 0, 2)" /></div>
                <div class="pk-card"><p class="pk-muted" style="margin-bottom: 8px">Three reasons</p><x-pulse.sync-meter :reasons="$reasons3" /></div>
                <div class="pk-card"><p class="pk-muted" style="margin-bottom: 8px">Five reasons, stacked</p><x-pulse.sync-meter :reasons="array_merge($reasons3, [['code' => 'follows', 'text' => 'You follow each other\'s updates'], ['code' => 'fresh', 'text' => 'Posted this week']])" /></div>
                <div class="pk-card"><p class="pk-muted" style="margin-bottom: 8px">No reasons: nothing renders</p><x-pulse.sync-meter :reasons="[]" /></div>
            </div>
            <div class="pk-on-field" style="padding: 32px; border-radius: 18px; background: var(--c-field); color: var(--c-field-ink)">
                <x-pulse.sync-meter :reasons="$reasons3" />
            </div>
        </section>

        <section aria-labelledby="k-ladder" class="pk-stack">
            <h2 id="k-ladder">Verification ladder</h2>
            <div class="pk-card" style="max-width: 520px"><x-pulse.ladder :rungs="$rungs" summary /></div>
        </section>

        <section aria-labelledby="k-forms" class="pk-stack">
            <h2 id="k-forms">Fields, chips, toggle</h2>
            <div class="pk-stack" style="max-width: 520px">
                <x-pulse.field name="what" label="What do you need" hint="One sentence. No links." placeholder="Someone to fix a leaking tap" />
                <x-pulse.textarea name="details" label="Details" optional rows="3" />
                <x-pulse.select name="band" label="Budget band" placeholder="Choose one" :options="['low' => 'Under R1 000', 'mid' => 'R1 000 to R5 000', 'high' => 'Over R5 000']" />
                <x-pulse.field name="broken" label="With an error" error="Say what you need in at least five words." value="Pipes" />
                <x-pulse.chips name="topics" legend="Topics" :options="['bookkeeping' => 'Bookkeeping', 'design' => 'Design', 'cycling' => 'Cycling', 'catering' => 'Catering']" :selected="['design', 'cycling']" />
                <x-pulse.toggle name="digest" label="Send me a daily digest, only when it holds something real" hint="You can switch this off at any time." :checked="true" />
                <x-pulse.toggle name="anon" label="Let employers find me without my name" />
            </div>
        </section>

        <section aria-labelledby="k-lists" class="pk-stack">
            <h2 id="k-lists">Empty state, stats, rows</h2>
            <x-pulse.empty title="Nothing matches yet." text="Say what you need and the floor will find it." action="Say what you need" href="#" style="max-width: 680px" />
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; max-width: 680px">
                <x-pulse.stat label="Requests answered" value="12" note="sample number" />
                <x-pulse.stat label="Median reply" value="3 h 20 m" note="sample number" />
                <x-pulse.stat label="Outcomes confirmed" value="0" />
            </div>
            <div style="max-width: 680px">
                <x-pulse.row title="Bookkeeping for small shops" href="#" meta="Request from Thandi, 2 h ago" unread>
                    <x-slot name="lead"><x-pulse.avatar name="Thandi Mokoena" /></x-slot>
                    <x-slot name="end"><x-pulse.pill tone="signal">Waiting for you</x-pulse.pill></x-slot>
                </x-pulse.row>
                <x-pulse.row title="Paint job in Pretoria" href="#" meta="You said yes, yesterday">
                    <x-slot name="lead"><x-pulse.avatar name="Sipho Dlamini" /></x-slot>
                    <x-slot name="end"><span class="pk-mono pk-muted">Yesterday</span></x-slot>
                </x-pulse.row>
            </div>
        </section>

        <section aria-labelledby="k-tabs" class="pk-stack">
            <h2 id="k-tabs">Tabs</h2>
            <x-pulse.tabs :tabs="['requests' => ['label' => 'Requests', 'count' => 2], 'open' => 'Open', 'closed' => 'Closed']" active="requests" label="Lines, example">
                <div data-pk-panel="requests"><p>Two people have knocked. Answer or let them lapse.</p></div>
                <div data-pk-panel="open" hidden><p>Conversations that started with a yes.</p></div>
                <div data-pk-panel="closed" hidden><p>Finished, with an outcome or without.</p></div>
            </x-pulse.tabs>
            <x-pulse.tabs :tabs="['a' => ['label' => 'Offers', 'href' => '#a'], 'b' => ['label' => 'Roles', 'href' => '#b']]" active="a" label="Business, example" />
        </section>

        <section aria-labelledby="k-over" class="pk-stack">
            <h2 id="k-over">Drawer, sheet, confirm, toast</h2>
            <div class="pk-cluster">
                <x-pulse.button variant="quiet" data-pk-dialog="kit-drawer">Open drawer</x-pulse.button>
                <x-pulse.button variant="quiet" data-pk-dialog="kit-sheet">Open sheet</x-pulse.button>
                <x-pulse.button variant="danger" data-pk-dialog="kit-confirm">Close this line</x-pulse.button>
                <x-pulse.button variant="quiet" onclick="pulseToast('Saved for later.', {kind: 'good'})">Show a toast</x-pulse.button>
            </div>
            <x-pulse.drawer id="kit-drawer" title="Filters" description="Narrow the round without leaving the page.">
                <x-pulse.chips name="drawer_topics" legend="Topics" :options="['design' => 'Design', 'cycling' => 'Cycling']" />
                <x-slot name="footer"><x-pulse.button variant="quiet" data-pk-close>Done</x-pulse.button></x-slot>
            </x-pulse.drawer>
            <x-pulse.drawer id="kit-sheet" title="Knock" side="bottom" description="One request, four fields, no links.">
                <div class="pk-stack">
                    <x-pulse.field name="k_what" label="What" />
                    <x-pulse.field name="k_when" label="When or how many" />
                </div>
                <x-slot name="footer"><x-pulse.button>Send knock</x-pulse.button></x-slot>
            </x-pulse.drawer>
            <x-pulse.confirm id="kit-confirm" title="Close this line?" text="Both sides will see it as closed. You can start another from the same offer." label="Close line" />
        </section>
    </div>
    @endif
</x-app-layout>
