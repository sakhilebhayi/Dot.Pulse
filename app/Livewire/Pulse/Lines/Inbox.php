<?php

namespace App\Livewire\Pulse\Lines;

use App\Livewire\Pulse\Lines\Concerns\AnswersRequests;
use App\Models\PulseConversation;
use App\Models\PulseEntity;
use App\Models\PulseEntityMember;
use App\Services\Pulse\Lines\Access;
use App\Services\Pulse\Lines\LineService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Lines: every conversation, in five tabs. Requests wait for your answer, Open are running, Sent wait for them,
 * Closed are over, Ignored are the ones you let lapse. If you answer for a business page, a switcher shows that page's
 * lines only.
 *
 *   <livewire:pulse.lines.inbox />                        the signed-in person's lines (and their pages')
 *   <livewire:pulse.lines.inbox :entity-id="$page->id" /> one page's lines
 *   :start-tab="open" picks the first tab (requests by default).
 */
class Inbox extends Component
{
    use AnswersRequests;

    public const TABS = ['requests' => 'Requests', 'open' => 'Open', 'sent' => 'Sent', 'closed' => 'Closed', 'ignored' => 'Ignored'];

    #[Url(as: 'tab')]
    public string $tab = 'requests';

    public ?int $entityId = null;

    /** Only the lines that are mine as a person, not the ones I answer for through a page. */
    public bool $meOnly = false;

    public string $kind = '';

    public bool $unreadOnly = false;

    public int $page = 1;

    public function mount(?int $entityId = null, ?string $startTab = null): void
    {
        $this->entityId = $entityId;

        if ($startTab !== null && array_key_exists($startTab, self::TABS)) {
            $this->tab = $startTab;
        }

        $this->normalise();
    }

    public function hydrate(): void
    {
        $this->normalise();
    }

    public function setTab(string $tab): void
    {
        if (array_key_exists($tab, self::TABS)) {
            $this->tab = $tab;
            $this->page = 1;
            $this->declining = $this->blocking = null;
        }
    }

    public function switchTo(?int $entityId): void
    {
        $this->entityId = $entityId !== null && $this->answersFor()->has($entityId) ? $entityId : null;
        $this->meOnly = false;
        $this->page = 1;
    }

    public function switchToMe(): void
    {
        $this->entityId = null;
        $this->meOnly = true;
        $this->page = 1;
    }

    public function updatedKind(): void
    {
        $this->page = 1;
    }

    public function updatedUnreadOnly(): void
    {
        $this->page = 1;
    }

    public function clearFilters(): void
    {
        $this->kind = '';
        $this->unreadOnly = false;
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    /** The 30 second refresh while the page is in view: the render does the work. */
    public function refresh(): void {}

    protected function afterAnswer(string $what, int $id): void
    {
        $this->dispatch('toast', message: match ($what) {
            'accepted' => 'They said yes. The line is open.',
            'declined' => 'Answered. They are told only what you chose.',
            'ignored' => 'Set aside. It lapses quietly unless you answer.',
            'closed' => 'Closed.',
            'blocked' => 'Blocked. They are not told.',
            default => 'Done.',
        }, kind: 'good');
    }

    private function normalise(): void
    {
        if (! array_key_exists($this->tab, self::TABS)) {
            $this->tab = 'requests';
        }

        if ($this->entityId !== null && ! $this->answersFor()->has($this->entityId)) {
            $this->entityId = null;
        }

        if ($this->kind !== '' && ! in_array($this->kind, PulseConversation::KINDS, true)) {
            $this->kind = '';
        }

        $this->page = max(1, $this->page);
    }

    /** @return Collection<int, PulseEntity> pages the person answers for, keyed by id */
    private function answersFor()
    {
        return once(function () {
            $ids = PulseEntityMember::query()->where('user_id', auth()->id())->whereIn('role', Access::RESPONDER_ROLES)->pluck('entity_id');

            return PulseEntity::query()->whereIn('id', $ids)->orderBy('name')->get(['id', 'name', 'slug'])->keyBy('id');
        });
    }

    public function render(LineService $lines): View
    {
        $user = auth()->user();
        $scope = $this->meOnly ? 'none' : $this->entityId;
        $base = array_filter(['entity_id' => $scope, 'kind' => $this->kind ?: null, 'unread' => $this->unreadOnly ?: null]);

        // The service paginates from the request's page number; a Livewire update has none, so say which page this is.
        request()->merge(['page' => $this->page]);
        $paginator = $lines->inbox($user, $base + ['tab' => $this->tab, 'per_page' => 20]);

        $items = $paginator->getCollection();

        $pending = $lines->inbox($user, array_filter(['entity_id' => $scope]) + ['tab' => 'requests', 'per_page' => 1])->total();
        $fresh = $lines->inbox($user, array_filter(['entity_id' => $scope]) + ['tab' => 'open', 'unread' => true, 'per_page' => 1])->total();

        return view('livewire.pulse.lines.inbox', [
            'lines' => $items,
            'paginator' => $paginator,
            'meOnly' => $this->meOnly,
            'pending' => $pending,
            'fresh' => $fresh,
            'pages' => $this->answersFor(),
            'filtered' => $this->kind !== '' || $this->unreadOnly,
        ]);
    }
}
