<span style="display:inline-block;position:relative;">
    @if($this->alreadyReported)
        <span style="font-size:11.5px;color:#52525b;" title="A moderator will take a look. You will hear back once it is reviewed.">Reported</span>
    @else
        <button wire:click="toggle" type="button" style="background:none;border:none;font-size:11.5px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Report</button>

        @if($open)
            <form wire:submit="submit" class="dot-card" style="position:absolute;left:0;top:100%;z-index:30;margin-top:6px;width:300px;padding:1rem;display:flex;flex-direction:column;gap:10px;box-shadow:0 12px 32px rgba(0,0,0,0.5);">
                <p style="font-family:'Syne',sans-serif;font-size:12.5px;font-weight:700;color:#f4f4f5;margin:0;">What is wrong with this {{ $targetType === 'user' ? 'profile' : $targetType }}?</p>
                <select wire:model="reason" class="dot-input" aria-label="Reason">
                    <option value="">Choose a reason…</option>
                    @foreach(\App\Enums\ReportReason::cases() as $r)
                        <option value="{{ $r->value }}">{{ $r->label() }}</option>
                    @endforeach
                </select>
                @error('reason')<p style="color:#f87171;font-size:11px;margin:0;">{{ $message }}</p>@enderror
                <textarea wire:model="note" rows="3" maxlength="500" placeholder="Add a note (optional unless “Something else”)" class="dot-input" aria-label="Note"></textarea>
                @error('note')<p style="color:#f87171;font-size:11px;margin:0;">{{ $message }}</p>@enderror
                <p style="font-size:11px;color:#52525b;margin:0;">Moderators see your note, not your name. The author is not told who reported.</p>
                <div style="display:flex;gap:10px;">
                    <button type="submit" class="dot-btn dot-btn-primary" style="padding:5px 12px;font-size:12px;">Send report</button>
                    <button type="button" wire:click="toggle" style="background:none;border:none;font-size:12px;color:#71717a;cursor:pointer;font-family:'Inter',sans-serif;">Cancel</button>
                </div>
            </form>
        @endif
    @endif
</span>
