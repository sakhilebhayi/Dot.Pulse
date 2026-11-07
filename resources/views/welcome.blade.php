@php
    // Sample text for the worked example. Everything in it is invented for illustration and is labelled as an example on the page.
    $sampleReasons = [
        ['code' => 'need_offer', 'text' => 'You need bookkeeping, they offer bookkeeping', 'weight' => 40],
        ['code' => 'place_region', 'text' => 'Both in Gauteng', 'weight' => 20],
        ['code' => 'responsive', 'text' => 'They usually answer within a day', 'weight' => 10],
    ];
    $heroReasons = [
        ['code' => 'offer_need', 'text' => 'You offer web design, they need a web designer', 'weight' => 40],
        ['code' => 'place_region', 'text' => 'Both in the Western Cape', 'weight' => 20],
        ['code' => 'fresh', 'text' => 'They asked for it this week', 'weight' => 10],
    ];
    $sampleRungs = [
        ['label' => 'InfoDot account', 'state' => 'checked', 'source' => 'InfoDot'],
        ['label' => 'Business registration number', 'state' => 'claimed', 'detail' => 'Entered by the business'],
        ['label' => 'Website', 'state' => 'claimed', 'detail' => 'Entered by the business'],
        ['label' => 'Trade licence', 'state' => 'missing'],
    ];
    $door = pulse_infodot_url();
    $signedIn = auth()->check();
    $doorLabel = $signedIn ? 'Open the Floor' : 'Sign in with InfoDot';
    $doorHref = $signedIn ? pulse_route_first(['floor', 'dashboard']) : $door;
@endphp
<x-public-layout title="Dot.Pulse: say what you want" description="Dot.Pulse introduces the people and businesses that fit what you want, shows the reasons for every match, and never contacts anyone without a yes.">

    <section class="lp-hero" aria-labelledby="hero-title">
        <div class="pub-wrap">
            <h1 id="hero-title">Say what you want.</h1>
            <p class="lp-hero__lead">Pulse introduces people and businesses that fit, and always says why.</p>
            <div class="lp-hero__actions">
                <x-pulse.button variant="signal" :href="$doorHref">{{ $doorLabel }}</x-pulse.button>
                <x-pulse.button variant="ghost" href="#match">See how a match works</x-pulse.button>
            </div>
            <div class="lp-hero__stage pk-on-field">
                <x-pulse.sync-meter :reasons="$heroReasons" you-label="You" them-label="Someone who needs you" />
                <span class="lp-example">Example only. Sample text, not a real match.</span>
            </div>
        </div>
    </section>

    <section class="lp-section" id="match" aria-labelledby="match-title">
        <div class="pub-wrap lp-split">
            <div class="lp-split__text">
                <h2 id="match-title">Every introduction shows its reasons.</h2>
                <p>Your line is cobalt. Theirs is ink. Wherever the two meet, a yellow node appears with the plain sentence that put it there.</p>
                <p>Tap a reason to stop Pulse using it. A match with no reasons does not exist.</p>
            </div>
            <div class="lp-sample">
                <x-pulse.beat kind="offer" title="Bookkeeping for small shops and sole traders" place="Johannesburg, Gauteng" open>
                    Monthly books, VAT returns and a report you can read.
                    <x-slot name="from">
                        <x-pulse.avatar name="Sample Books" entity />
                        <span class="pk-beat__from-text">
                            <span class="pk-beat__from-name">Sample Books</span>
                            <x-pulse.pill>Example business</x-pulse.pill>
                        </span>
                    </x-slot>
                    <x-slot name="meter">
                        <x-pulse.sync-meter :reasons="$sampleReasons" />
                    </x-slot>
                </x-pulse.beat>
                <span class="lp-sample__note">Example. The business, the words and the reasons are invented for this page.</span>
            </div>
        </div>
    </section>

    <section class="lp-section lp-section--raised" aria-labelledby="yes-title">
        <div class="pub-wrap">
            <h2 id="yes-title">Nothing happens without a yes.</h2>
            <ol class="lp-steps">
                <li><strong>Knock</strong><p>One request that carries the thing that started it. Four fields, no links.</p></li>
                <li><strong>They answer</strong><p>Accept, ignore, or say not now. Not now is a fine answer.</p></li>
                <li><strong>Talk</strong><p>Links and files open up after a yes. Block or report at any time.</p></li>
                <li><strong>Close the loop</strong><p>Both sides confirm the outcome. Only then does Pulse count it.</p></li>
            </ol>
            <ul class="lp-promises">
                <li><strong>Who may reach you</strong><span>Anyone, only through something of yours, by request, or nobody. You change it whenever you like.</span></li>
                <li><strong>Who may find you</strong><span>Off, anonymous with skills and region but never your name, or open. Employers cannot see more than you allow.</span></li>
                <li><strong>What lands in your inbox</strong><span>A daily digest goes out only when it holds something real.</span></li>
            </ul>
        </div>
    </section>

    <section class="lp-section" aria-labelledby="ladder-title">
        <div class="pub-wrap lp-split">
            <div class="lp-split__text">
                <h2 id="ladder-title">Verified means checked.</h2>
                <p>Pulse lists each check by name: checked, claimed or missing. A claim stays labelled as a claim, with the source beside it.</p>
                <p>No badge is for sale, and nothing is marked verified that was not.</p>
            </div>
            <div class="pk-card">
                <x-pulse.ladder :rungs="$sampleRungs" summary />
                <span class="lp-sample__note">Example ladder. The rungs are invented for this page.</span>
            </div>
        </div>
    </section>

    <section class="lp-section lp-section--raised" aria-labelledby="member-title">
        <div class="pub-wrap">
            <h2 id="member-title">What membership means here.</h2>
            <ul class="lp-promises">
                <li><strong>One door</strong><span>Sign in once with InfoDot. The same identity carries across the Dot platforms.</span></li>
                <li><strong>A fair allowance</strong><span>Everyone gets a weekly allowance of first requests. It refills when people reply, and it is never for sale.</span></li>
                <li><strong>Free to answer</strong><span>Sellers pay nothing to see a request that fits them.</span></li>
                <li><strong>No marketing</strong><span>Businesses cannot send marketing through Pulse. Every message exists because someone knocked.</span></li>
                <li><strong>Adults only</strong><span>Pulse is for people aged 18 and over.</span></li>
            </ul>
        </div>
    </section>

    <section class="lp-final" aria-labelledby="final-title">
        <div class="pub-wrap">
            <h2 id="final-title">Come in through InfoDot.</h2>
            <p>New to InfoDot? Start there, then come back through the same door.</p>
            <x-pulse.button variant="signal" :href="$doorHref">{{ $doorLabel }}</x-pulse.button>
        </div>
    </section>

</x-public-layout>
