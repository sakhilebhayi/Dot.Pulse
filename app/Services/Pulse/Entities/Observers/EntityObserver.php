<?php

namespace App\Services\Pulse\Entities\Observers;

use App\Models\PulseEntity;
use App\Services\Pulse\Entities\SignalIndexer;

/**
 * A business page: its own signal follows it, and when what makes its listings visible changes (status,
 * moderation, deletion) every offer and role on it is re-synced, because they are only live while the page is.
 */
class EntityObserver
{
    public function __construct(private readonly SignalIndexer $indexer) {}

    public function saved(PulseEntity $entity): void
    {
        $this->indexer->syncSubject($entity);

        if ($entity->wasRecentlyCreated) {
            return;
        }

        if ($entity->wasChanged(['status', 'moderation', 'deleted_at', 'place_id', 'industry', 'service_area'])) {
            $this->cascade($entity);
        }
    }

    public function deleted(PulseEntity $entity): void
    {
        if ($entity->isForceDeleting()) {
            $this->indexer->retire($entity);

            return;
        }

        $this->indexer->syncSubject($entity);
        $this->cascade($entity);
    }

    public function restored(PulseEntity $entity): void
    {
        $this->indexer->syncSubject($entity);
        $this->cascade($entity);
    }

    public function forceDeleted(PulseEntity $entity): void
    {
        $this->indexer->retire($entity);
    }

    private function cascade(PulseEntity $entity): void
    {
        $entity->offers()->withTrashed()->with(['entity', 'place'])->chunkById(100, fn ($offers) => $offers->each(fn ($o) => $this->indexer->syncSubject($o)));
        $entity->roles()->withTrashed()->with(['entity', 'place'])->chunkById(100, fn ($roles) => $roles->each(fn ($r) => $this->indexer->syncSubject($r)));
    }
}
