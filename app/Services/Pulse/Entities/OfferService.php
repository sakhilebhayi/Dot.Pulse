<?php

namespace App\Services\Pulse\Entities;

use App\Models\PulseEntity;
use App\Models\PulseMedia;
use App\Models\PulseOffer;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * What a business sells or provides. An offer starts as a draft; publish() puts it in front of people once its
 * text has passed the ContentGate. Pausing, archiving and deleting take it out of discovery at once (the
 * ListingObserver re-syncs the signal row).
 */
class OfferService
{
    public function __construct(private readonly ContentGate $gate) {}

    /** @param array<string, mixed> $data  pass 'publish' => true to publish straight away */
    public function create(User $by, PulseEntity $e, array $data): PulseOffer
    {
        Gate::forUser($by)->authorize('manageListings', $e);

        if (! $e->is_seller) {
            throw ValidationException::withMessages(['entity' => 'Turn on selling for this page before adding offers.']);
        }

        $max = (int) config('pulse.entities.max_offers', 100);

        if ($e->offers()->where('status', '!=', 'archived')->count() >= $max) {
            throw ValidationException::withMessages(['title' => "A page can hold up to {$max} offers. Archive some first."]);
        }

        $publish = (bool) ($data['publish'] ?? false);
        $clean = $this->validated($data, $e, creating: true);

        $offer = new PulseOffer($clean);
        $offer->forceFill([
            'entity_id' => $e->id,
            'created_by' => $by->id,
            'slug' => Slugger::unique($clean['title'], fn (string $s) => PulseOffer::withTrashed()->where('entity_id', $e->id)->where('slug', $s)->exists(), 140),
            'status' => 'draft',
            'moderation' => 'pending',
        ])->save();

        Audit::record($by, 'offer.create', $offer, ['entity_id' => $e->id]);

        return $publish ? $this->publish($by, $offer) : $offer->refresh();
    }

    /** @param array<string, mixed> $data */
    public function update(User $by, PulseOffer $o, array $data): PulseOffer
    {
        Gate::forUser($by)->authorize('update', $o);
        $this->guardEditable($o);

        $clean = $this->validated($data, $o->entity, creating: false, offer: $o);
        $o->fill($clean);
        $changed = array_keys($o->getDirty());

        if ($o->status === 'live' && $o->isDirty(['title', 'summary', 'description', 'category', 'tags'])) {
            $o->moderation = $this->gate->decide($by, $this->text($o), ['kind' => 'offer'])['moderation'];
        }

        $o->save();
        Audit::record($by, 'offer.update', $o, ['fields' => $changed]);

        return $o->refresh();
    }

    public function pause(User $by, PulseOffer $o): PulseOffer
    {
        Gate::forUser($by)->authorize('update', $o);
        $this->guardEditable($o);

        if ($o->status === 'live') {
            $o->update(['status' => 'paused']);
            Audit::record($by, 'offer.pause', $o);
        }

        return $o->refresh();
    }

    /** Draft, paused or archived to live. The text is checked again at this moment. */
    public function publish(User $by, PulseOffer $o): PulseOffer
    {
        Gate::forUser($by)->authorize('publish', $o);
        $this->guardEditable($o);

        if (blank($o->summary) && blank($o->description)) {
            throw ValidationException::withMessages(['summary' => 'Say what this is in a sentence or two before publishing.']);
        }

        $decision = $this->gate->decide($by, $this->text($o), ['kind' => 'offer']);

        $o->forceFill([
            'status' => 'live',
            'moderation' => $decision['moderation'],
            'published_at' => $o->published_at ?? now(),
        ])->save();

        Audit::record($by, 'offer.publish', $o, ['moderation' => $o->moderation, 'source' => $decision['source']]);

        return $o->refresh();
    }

    public function archive(User $by, PulseOffer $o): PulseOffer
    {
        Gate::forUser($by)->authorize('update', $o);

        if ($o->status !== 'removed') {
            $o->update(['status' => 'archived']);
            Audit::record($by, 'offer.archive', $o);
        }

        return $o->refresh();
    }

    public function delete(User $by, PulseOffer $o): void
    {
        Gate::forUser($by)->authorize('delete', $o);

        $o->delete();
        Audit::record($by, 'offer.delete', $o);
    }

    /** A moderator's removal is final for the owner. */
    private function guardEditable(PulseOffer $o): void
    {
        if ($o->moderation === 'removed' || $o->status === 'removed') {
            throw ValidationException::withMessages(['offer' => 'This offer was removed by a moderator and cannot be changed.']);
        }
    }

    private function text(PulseOffer $o): string
    {
        return implode("\n", array_filter([$o->title, $o->summary, $o->description, $o->category, implode(' ', (array) $o->tags)]));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validated(array $data, PulseEntity $e, bool $creating, ?PulseOffer $offer = null): array
    {
        $req = $creating ? 'required' : 'sometimes';

        $clean = Validator::make($data, [
            'title' => [$req, 'string', 'min:3', 'max:140'],
            'summary' => ['sometimes', 'nullable', 'string', 'max:400'],
            'description' => ['sometimes', 'nullable', 'string', 'max:6000'],
            'kind' => ['sometimes', Rule::in(['product', 'service'])],
            'category' => ['sometimes', 'nullable', 'string', 'max:120'],
            'tags' => ['sometimes', 'nullable', 'array', 'max:15'],
            'price_mode' => ['sometimes', Rule::in(['fixed', 'from', 'quote', 'free', 'contact'])],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000000000'],
            'currency' => ['sometimes', 'string', 'regex:/^[A-Z]{3}$/'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:40'],
            'place_id' => ['sometimes', 'nullable', 'integer', Rule::exists('pulse_places', 'id')],
            'service_area' => ['sometimes', 'nullable', 'array', 'max:20'],
            'availability' => ['sometimes', Rule::in(['available', 'limited', 'sold_out'])],
            'accepts_quotes' => ['sometimes', 'boolean'],
            'media_ids' => ['sometimes', 'nullable', 'array', 'max:'.(int) config('pulse.entities.max_offer_images', 8)],
            'media_ids.*' => ['integer'],
            'expires_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ])->validate();

        foreach (['title' => 140, 'category' => 80, 'unit' => 24] as $key => $max) {
            if (array_key_exists($key, $clean)) {
                $clean[$key] = Sanitizer::line($clean[$key], $max);
            }
        }

        if (array_key_exists('summary', $clean)) {
            $clean['summary'] = Sanitizer::line($clean['summary'], 240);
        }

        if (array_key_exists('description', $clean)) {
            $clean['description'] = Sanitizer::text($clean['description'], 5000);
        }

        foreach (['tags' => [15, 30], 'service_area' => [20, 60]] as $key => [$items, $len]) {
            if (array_key_exists($key, $clean)) {
                $clean[$key] = Sanitizer::list($clean[$key], $items, $len);
            }
        }

        $mode = $clean['price_mode'] ?? $offer?->price_mode ?? 'quote';
        $amount = array_key_exists('amount_cents', $clean) ? $clean['amount_cents'] : $offer?->amount_cents;

        if (in_array($mode, ['fixed', 'from'], true) && ($amount === null || $amount <= 0)) {
            throw ValidationException::withMessages(['amount_cents' => 'Give a price, or choose "on request".']);
        }

        if (! in_array($mode, ['fixed', 'from'], true) && (isset($clean['price_mode']) || array_key_exists('amount_cents', $clean))) {
            $clean['amount_cents'] = null;
        }

        if (array_key_exists('media_ids', $clean)) {
            $ids = array_values(array_unique(array_map('intval', $clean['media_ids'] ?? [])));
            $owned = PulseMedia::query()->whereIn('id', $ids)->where('purpose', 'offer')->where('entity_id', $e->id)->count();

            if ($owned !== count($ids)) {
                throw ValidationException::withMessages(['media_ids' => 'One of those images does not belong to this page.']);
            }

            $clean['media_ids'] = $ids;
        }

        unset($clean['publish']);

        return $clean;
    }
}
