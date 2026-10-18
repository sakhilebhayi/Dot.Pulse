<?php

namespace App\Livewire\Pulse;

use App\Models\Community;
use App\Models\PulsePost;
use App\Services\AiModerationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

class CreatePost extends Component
{
    public string $type = 'discussion';

    public string $title = '';

    public string $body = '';

    public ?int $communityId = null;

    public bool $showForm = false;

    protected array $rules = [
        'type' => 'required|string|in:discussion,announcement,question,idea,bug_report,release,success_story,showcase,tutorial,agent,integration,event,article,poll,video,job,marketplace',
        'title' => 'nullable|string|max:200',
        'body' => 'required|string|min:10|max:10000',
        'communityId' => 'nullable|integer',
    ];

    /** Communities the author belongs to: the only places they may post. */
    #[Computed]
    public function communities(): Collection
    {
        return auth()->user()->joinedCommunities()->orderBy('name')->get();
    }

    public function publish(): void
    {
        $this->validate();

        $community = null;

        if ($this->communityId) {
            $community = Community::find($this->communityId);

            // One message for "does not exist" and "not yours to post in":
            // the form must not reveal which private communities exist.
            if ($community === null || ! Gate::allows('post', $community)) {
                throw ValidationException::withMessages([
                    'communityId' => 'You can only post in communities you have joined.',
                ]);
            }
        }

        $post = PulsePost::create([
            'user_id' => auth()->id(),
            'community_id' => $community?->id,
            // Teams are optional: a user with no current team still posts.
            'team_id' => auth()->user()->currentTeam?->id,
            'type' => $this->type,
            'title' => $this->title ?: null,
            'body' => $this->body,
            'status' => 'pending',
        ]);

        app(AiModerationService::class)->enrichPost($post);

        $this->reset(['type', 'title', 'body', 'communityId', 'showForm']);
        $this->dispatch('post-created');
    }

    public function render(): View
    {
        return view('livewire.pulse.create-post');
    }
}
