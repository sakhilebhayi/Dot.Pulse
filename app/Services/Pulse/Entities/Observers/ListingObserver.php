<?php

namespace App\Services\Pulse\Entities\Observers;

use App\Services\Pulse\Entities\SignalIndexer;
use Illuminate\Database\Eloquent\Model;

/**
 * Offers, roles, needs and profiles: any write re-syncs the subject's signal row, so the index cannot drift
 * from the data whether the change came from a service, a moderator, a factory or tinker.
 */
class ListingObserver
{
    public function __construct(private readonly SignalIndexer $indexer) {}

    public function saved(Model $model): void
    {
        $this->indexer->syncSubject($model);
    }

    public function deleted(Model $model): void
    {
        method_exists($model, 'isForceDeleting') && $model->isForceDeleting()
            ? $this->indexer->retire($model)
            : $this->indexer->syncSubject($model);
    }

    public function restored(Model $model): void
    {
        $this->indexer->syncSubject($model);
    }

    public function forceDeleted(Model $model): void
    {
        $this->indexer->retire($model);
    }
}
