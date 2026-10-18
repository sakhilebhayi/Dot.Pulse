<div class="dot-card" style="padding:1.5rem;">
    <div class="section-head">
        <h3 style="font-family:'Syne',sans-serif;font-size:0.875rem;font-weight:700;color:#f4f4f5;">Communities</h3>
        <input
            wire:model.live="search"
            type="text"
            placeholder="Search communities…"
            class="dot-input search-field"
            aria-label="Search communities"
            style="font-size:12px;padding:6px 10px;"
        />
    </div>

    @if($this->communities->isEmpty())
        <p style="font-size:13px;color:#52525b;padding:1.5rem 0;text-align:center;margin:0;">No communities found.</p>
    @else
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach($this->communities as $community)
                @php
                    $isMember = isset($this->memberOf[$community->id]);
                    $requestStatus = $this->requests[$community->id] ?? null;
                    $canOpen = $community->isPublic() || $isMember;
                @endphp
                <div class="dot-row" style="padding:14px;display:flex;align-items:flex-start;justify-content:space-between;gap:12px;" wire:key="community-{{ $community->id }}">
                    <div style="min-width:0;">
                        <div style="display:flex;align-items:center;gap:8px;">
                            @if($canOpen)
                                <a href="{{ route('communities.show', $community) }}" style="display:block;font-size:13.5px;font-weight:600;color:#f4f4f5;text-decoration:none;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $community->name }}</a>
                            @else
                                <span style="display:block;font-size:13.5px;font-weight:600;color:#f4f4f5;margin:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ $community->name }}</span>
                            @endif
                            @if($community->isPrivate())
                                <span class="dot-badge dot-badge-neutral" title="Only members can see what is posted here">
                                    <span class="material-symbols-rounded" style="font-size:12px;margin-right:3px;" aria-hidden="true">lock</span>Private
                                </span>
                            @endif
                        </div>
                        @if($community->industry)
                            <p style="font-size:11px;color:#f1c62e;font-weight:600;margin:3px 0;">{{ $community->industry }}</p>
                        @endif
                        @if($canOpen)
                            <p style="font-size:12px;color:#71717a;line-height:1.5;margin:3px 0 0;" class="line-clamp-2">{{ $community->description }}</p>
                        @else
                            <p style="font-size:12px;color:#52525b;line-height:1.5;margin:3px 0 0;">Posts and details are visible to members only.</p>
                        @endif
                        <p style="font-size:11px;color:#52525b;margin:6px 0 0;">{{ number_format($community->members_count) }} members</p>
                    </div>
                    <div style="flex-shrink:0;">
                        @if($isMember)
                            <button wire:click="leave({{ $community->id }})" class="dot-btn dot-btn-ghost tap" style="font-size:11px;padding:5px 11px;">
                                Leave
                            </button>
                        @elseif($requestStatus === 'pending')
                            <button wire:click="cancelRequest({{ $community->id }})" class="dot-btn dot-btn-ghost tap" style="font-size:11px;padding:5px 11px;" title="Withdraw your request">
                                Requested
                            </button>
                        @elseif(isset($this->coolingDown[$community->id]))
                            <span class="dot-badge dot-badge-neutral" title="A manager declined your request. You can ask again later.">Not accepted</span>
                        @elseif($community->isPrivate())
                            <button wire:click="join({{ $community->id }})" class="dot-btn dot-btn-primary tap" style="font-size:11px;padding:5px 11px;">
                                Request to join
                            </button>
                        @else
                            <button wire:click="join({{ $community->id }})" class="dot-btn dot-btn-primary tap" style="font-size:11px;padding:5px 11px;">
                                Join
                            </button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>
