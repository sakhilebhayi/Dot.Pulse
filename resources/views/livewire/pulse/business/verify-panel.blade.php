<div class="bz">
    @if ($notice)
        <div class="bz-notice" role="status"><x-pulse.icon name="info" :size="18" /><p>{{ $notice }}</p></div>
    @endif

    <div class="bz-cols bz-cols--even">
        <section class="pk-card" aria-labelledby="vf-ladder">
            <h2 id="vf-ladder">Your ladder</h2>
            <p class="pk-muted" style="margin: 6px 0 14px">This is what people see on your page. Pulse builds it only from real records.</p>
            <x-pulse.ladder :rungs="$this->ladder" :summary="true" />
        </section>

        <div class="pk-stack">
            <section class="pk-card pk-stack" aria-labelledby="vf-infodot">
                <h2 id="vf-infodot">InfoDot company</h2>
                @if ($this->linkedCompany)
                    <p>Linked to <strong>{{ $this->linkedCompany->name }}</strong>. The ladder shows that you belong to that company in InfoDot.</p>
                    <div><x-pulse.button variant="quiet" size="sm" wire:click="unlink" wire:confirm="Unlink this company from the page?">Unlink</x-pulse.button></div>
                @elseif (! $this->isOwner)
                    <p class="pk-muted">Only an owner can link an InfoDot company.</p>
                @elseif ($this->companies)
                    <form class="pk-stack" wire:submit="link" novalidate>
                        <x-pulse.select name="team" id="vf-team" label="Company" wire:model="team" placeholder="Pick a company" :options="$this->companies" hint="Companies you own or administer in InfoDot." />
                        <div><x-pulse.button type="submit" variant="primary" size="sm">Link company</x-pulse.button></div>
                    </form>
                @else
                    <p class="pk-muted">No InfoDot company to link. Companies you own or administer in InfoDot show up here after you sign in through InfoDot.</p>
                @endif
            </section>

            <section class="pk-card pk-stack" aria-labelledby="vf-reg">
                <h2 id="vf-reg">Registration number</h2>
                <form class="pk-stack" wire:submit="claim" novalidate>
                    <x-pulse.field name="registration_number" id="vf-number" label="As on your registration document" wire:model="registration_number" maxlength="40" hint="Shown as claimed. Pulse does not check it against a registry." />
                    <div><x-pulse.button type="submit" variant="primary" size="sm">Save as a claim</x-pulse.button></div>
                </form>
            </section>

            <section class="pk-card pk-stack" aria-labelledby="vf-what">
                <h2 id="vf-what">What InfoDot confirms, and what it does not</h2>
                <p>Linking confirms one thing: you belong to a company in InfoDot. It does not mean InfoDot has checked that the business exists.</p>
                <p>InfoDot has its own business review on its own site. Pulse cannot read the result today, so Pulse cannot show it and does not call any business verified. Your website, phone and registration number are shown as claims for the same reason.</p>
                <p class="pk-muted">Owner tip: if your company is reviewed at InfoDot, ask InfoDot to share that result with Pulse. When it does, this ladder will show it with the date.</p>
            </section>
        </div>
    </div>
</div>
