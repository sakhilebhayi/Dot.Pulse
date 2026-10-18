<div style="max-width:760px;">
    <div style="display:flex;gap:4px;background:rgba(255,255,255,0.04);border:1px solid rgba(255,255,255,0.07);border-radius:8px;padding:3px;width:fit-content;margin-bottom:1.25rem;">
        <button wire:click="$set('tab', 'held')" class="tap" style="padding:5px 14px;border-radius:6px;border:none;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;background:{{ $tab === 'held' ? 'rgba(241,198,46,0.12)' : 'transparent' }};color:{{ $tab === 'held' ? '#f1c62e' : '#71717a' }};">
            Held posts
            <span class="metric-val" style="margin-left:6px;font-size:11px;">{{ $this->heldPosts->count() }}</span>
        </button>
        <button wire:click="$set('tab', 'reports')" class="tap" style="padding:5px 14px;border-radius:6px;border:none;font-size:12px;font-weight:600;cursor:pointer;font-family:'Inter',sans-serif;background:{{ $tab === 'reports' ? 'rgba(241,198,46,0.12)' : 'transparent' }};color:{{ $tab === 'reports' ? '#f1c62e' : '#71717a' }};">
            Reports
            <span class="metric-val" style="margin-left:6px;font-size:11px;">{{ $this->reportCount }}</span>
        </button>
    </div>

    @if($tab === 'held')
        @if($this->heldPosts->isEmpty())
            <div class="dot-card" style="padding:2.5rem 1.5rem;text-align:center;">
                <p style="font-size:13px;color:#71717a;margin:0;">Nothing waiting for review.</p>
            </div>
        @endif

        @foreach($this->heldPosts as $post)
            @php
                $status = $post->enrichment?->moderation_status;
                $badge = match (true) {
                    $status === 'rejected' => ['AI recommended reject', 'dot-badge-danger'],
                    $status === 'flagged' => ['AI flagged for review', 'dot-badge-accent'],
                    default => ['Automated review unavailable', 'dot-badge-neutral'],
                };
            @endphp
            <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;" wire:key="held-{{ $post->id }}">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px 12px;margin-bottom:10px;">
                    <div>
                        <p style="font-size:13.5px;font-weight:600;color:#f4f4f5;margin:0;">{{ $post->author->name }}</p>
                        <p style="font-size:11.5px;color:#71717a;margin:2px 0 0;">
                            {{ $post->community?->name ?? 'No community' }}@if($post->community?->isPrivate()) (private)@endif &middot; {{ ucfirst($post->type) }}
                        </p>
                    </div>
                    <span class="dot-badge {{ $badge[1] }}">{{ $badge[0] }}</span>
                </div>

                @if($post->title)
                    <p style="font-family:'Syne',sans-serif;font-size:14.5px;font-weight:600;color:#f4f4f5;margin:0 0 4px;">{{ $post->title }}</p>
                @endif
                <p style="font-size:13px;color:#a1a1aa;line-height:1.6;margin:0 0 12px;white-space:pre-line;">{{ $post->body }}</p>

                @if($post->enrichment)
                    <div style="font-size:11.5px;color:#52525b;margin-bottom:12px;display:flex;flex-direction:column;gap:3px;">
                        @if($post->enrichment->moderation_rationale)
                            <p style="margin:0;">{{ $status === 'pending' ? 'Why it is here' : 'AI rationale' }}: {{ $post->enrichment->moderation_rationale }}</p>
                        @endif
                        @if($status !== 'pending')
                            <p style="margin:0;">Spam score: {{ number_format($post->enrichment->spam_score, 2) }} &middot; Safety score: {{ number_format($post->enrichment->safety_score, 2) }}</p>
                        @endif
                    </div>
                @endif

                @if($rejectingPostId === $post->id)
                    <div class="inline-form-row">
                        <input type="text" wire:model="rejectReason" placeholder="Reason for rejecting" class="dot-input" aria-label="Reason" />
                        <button wire:click="confirmReject({{ $post->id }})" class="dot-btn tap" style="background:#e24b4a;color:#fff;flex-shrink:0;">
                            Confirm reject
                        </button>
                        <button wire:click="cancelReject" class="dot-btn dot-btn-ghost tap" style="flex-shrink:0;">
                            Cancel
                        </button>
                    </div>
                @else
                    <div class="inline-form-row">
                        <button wire:click="approve({{ $post->id }})" class="dot-btn tap" style="background:#22c55e;color:#052e10;">
                            Approve
                        </button>
                        <button wire:click="promptReject({{ $post->id }})" class="dot-btn tap" style="background:#e24b4a;color:#fff;">
                            Reject
                        </button>
                    </div>
                @endif
            </div>
        @endforeach
    @else
        @if($reportGroups->isEmpty())
            <div class="dot-card" style="padding:2.5rem 1.5rem;text-align:center;">
                <p style="font-size:13px;color:#71717a;margin:0;">No open reports.</p>
            </div>
        @endif

        @foreach($reportGroups as $group)
            @php
                $target = $group['target'];
                $isPost = $target instanceof \App\Models\PulsePost;
                $isComment = $target instanceof \App\Models\PulseComment;
                $kind = $isPost ? 'Post' : ($isComment ? 'Comment' : 'Profile');
                $resolving = $resolvingType === $group['type'] && $resolvingId === $group['id'];
                $authorName = $isPost || $isComment ? $target?->author?->name : $target?->name;
            @endphp
            <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;" wire:key="report-{{ $group['key'] }}">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:6px 12px;margin-bottom:10px;">
                    <div style="min-width:0;">
                        <p style="font-size:13.5px;font-weight:600;color:#f4f4f5;margin:0;">{{ $kind }} by {{ $authorName ?? 'a deleted account' }}</p>
                        <p style="font-size:11.5px;color:#71717a;margin:2px 0 0;">
                            {{ $group['community'] ?? 'Platform-wide' }}@if($group['private']) (private)@endif
                        </p>
                    </div>
                    <span class="dot-badge dot-badge-danger" style="flex-shrink:0;">{{ $group['count'] }} {{ \Illuminate\Support\Str::plural('report', $group['count']) }}</span>
                </div>

                @if($isPost)
                    @if($target->title)
                        <p style="font-family:'Syne',sans-serif;font-size:14.5px;font-weight:600;color:#f4f4f5;margin:0 0 4px;">{{ $target->title }}</p>
                    @endif
                    <p style="font-size:13px;color:#a1a1aa;line-height:1.6;margin:0 0 12px;white-space:pre-line;">{{ $target->body }}</p>
                @elseif($isComment)
                    <p style="font-size:13px;color:#a1a1aa;line-height:1.6;margin:0 0 12px;white-space:pre-line;">{{ $target->body }}</p>
                @elseif($target)
                    <p style="font-size:13px;color:#a1a1aa;margin:0 0 12px;">
                        <a href="{{ route('users.show', $target) }}" style="color:#f1c62e;text-decoration:none;">{{ $target->name }}</a>
                        @if($target->pulseProfile?->headline) &middot; {{ $target->pulseProfile->headline }} @endif
                    </p>
                @endif

                <div style="display:flex;flex-wrap:wrap;gap:6px;margin-bottom:{{ count($group['notes']) ? '8px' : '12px' }};">
                    @foreach($group['reasons'] as $reason => $n)
                        <span class="dot-badge dot-badge-neutral">{{ \App\Enums\ReportReason::tryFrom($reason)?->label() ?? ucfirst($reason) }} &times; {{ $n }}</span>
                    @endforeach
                </div>

                @if(count($group['notes']))
                    <ul style="font-size:12px;color:#71717a;margin:0 0 12px;padding-left:16px;line-height:1.5;">
                        @foreach($group['notes'] as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                @endif

                @if($resolving)
                    <div class="inline-form-row">
                        <input type="text" wire:model="resolveReason" placeholder="Why it is being removed (kept in the log)" class="dot-input" aria-label="Reason" />
                        <button wire:click="resolveReports('{{ $group['slug'] }}', {{ $group['id'] }})" class="dot-btn tap" style="background:#e24b4a;color:#fff;flex-shrink:0;">Confirm removal</button>
                        <button wire:click="cancelResolve" class="dot-btn dot-btn-ghost tap" style="flex-shrink:0;">Cancel</button>
                    </div>
                    @error('resolveReason')<p style="color:#f87171;font-size:11px;margin:6px 0 0;">{{ $message }}</p>@enderror
                @else
                    <div class="inline-form-row">
                        @if($isPost || $isComment)
                            <button wire:click="promptResolve('{{ $group['slug'] }}', {{ $group['id'] }})" class="dot-btn tap" style="background:#e24b4a;color:#fff;">Remove {{ strtolower($kind) }}</button>
                        @else
                            <button wire:click="resolveReports('{{ $group['slug'] }}', {{ $group['id'] }})" class="dot-btn tap" style="background:#22c55e;color:#052e10;">Mark reviewed</button>
                        @endif
                        <button wire:click="dismissReports('{{ $group['slug'] }}', {{ $group['id'] }})" class="dot-btn dot-btn-ghost tap">Dismiss</button>
                    </div>
                @endif
            </div>
        @endforeach
    @endif
</div>
