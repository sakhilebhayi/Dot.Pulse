<?php

namespace App\Livewire\Pulse;

use App\Models\PulseModerationLog;
use App\Models\PulsePost;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The author's own view of posts that are not live: being checked, waiting
 * for a person, or removed. Without this a new post silently vanished from
 * the author's sight until it appeared (or never did).
 */
class MyPostStatus extends Component
{
    /** @return Collection<int, PulsePost> */
    #[Computed]
    public function posts(): Collection
    {
        return PulsePost::where('user_id', auth()->id())
            ->whereIn('status', ['pending', 'flagged', 'removed'])
            ->where('created_at', '>=', now()->subDays(14))
            ->latest()
            ->limit(10)
            ->get();
    }

    /** @return array<int, string> post id => the moderator's reason for the latest removal */
    #[Computed]
    public function removalReasons(): array
    {
        $ids = $this->posts->where('status', 'removed')->pluck('id');

        if ($ids->isEmpty()) {
            return [];
        }

        return PulseModerationLog::where('target_type', PulsePost::class)
            ->whereIn('target_id', $ids)
            ->whereIn('action', ['reject', 'remove'])
            ->whereNotNull('rationale')
            ->orderBy('id')
            ->get()
            ->pluck('rationale', 'target_id')
            ->all();
    }

    #[On('post-created')]
    public function refresh(): void
    {
        unset($this->posts, $this->removalReasons);
    }

    public function render(): View
    {
        return view('livewire.pulse.my-post-status');
    }
}
