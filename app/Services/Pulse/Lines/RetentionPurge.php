<?php

namespace App\Services\Pulse\Lines;

use App\Models\PulseConversation;
use App\Models\PulseMessage;
use App\Models\PulseReport;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Retention (POPIA s14: keep personal information no longer than the purpose needs). Every number is config
 * (`pulse.retention`). Run daily by `pulse:retention`; safe to run twice, each run only touches what has aged out.
 * What is held, why and for how long is written for people in docs/programme/R41_PRIVACY.md.
 *
 *  - Messages of closed or expired lines: erased after 24 months, kept as stubs so a thread still has its shape.
 *  - Declined and ignored requests: deleted after 90 days. Nothing was ever answered, so there is no thread to keep.
 *  - Signals that expired or were removed, dismissed matches, and the free text of ended needs: after 90 days.
 *  - Read notifications: deleted after 12 months.
 *  - Audit rows: after 24 months the actor and the ip hash are nulled. The rows are never deleted (the one change the
 *    append only log allows, the same one erasure makes).
 *  - Team invitations: deleted 30 days after they ended (answered, taken back or expired).
 *
 * Anything with an open report against it is left alone until a moderator has dealt with it.
 */
class RetentionPurge
{
    /** @return array<string, int> what was removed or changed, by kind */
    public function run(bool $dryRun = false): array
    {
        $cfg = (array) config('pulse.retention', []);
        $chunk = max(50, (int) ($cfg['chunk'] ?? 500));
        $now = now();

        $months = fn (string $key, int $default) => $now->copy()->subMonths(max(1, (int) ($cfg[$key] ?? $default)));
        $days = fn (string $key, int $default) => $now->copy()->subDays(max(1, (int) ($cfg[$key] ?? $default)));

        return [
            'messages_erased' => $this->eraseOldMessages($months('closed_line_messages_months', 24), $chunk, $dryRun),
            'requests_deleted' => $this->deleteDeadRequests($days('declined_request_days', 90), $chunk, $dryRun),
            'signals_deleted' => $this->deleteEndedSignals($days('ended_signal_days', 90), $chunk, $dryRun),
            'matches_deleted' => $this->deleteDismissedMatches($days('ended_signal_days', 90), $chunk, $dryRun),
            'need_texts_cleared' => $this->clearEndedNeedText($days('ended_signal_days', 90), $dryRun),
            'notifications_deleted' => $this->deleteReadNotifications($months('read_notification_months', 12), $chunk, $dryRun),
            'audit_rows_anonymised' => $this->anonymiseAudit($months('audit_actor_months', 24), $chunk, $dryRun),
            'invitations_deleted' => $this->deleteOldInvitations($days('invitation_days', 30), $chunk, $dryRun),
        ];
    }

    private function eraseOldMessages(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        $closed = fn () => DB::table('pulse_conversations')
            ->whereIn('status', [PulseConversation::CLOSED, PulseConversation::DECLINED, PulseConversation::SPAM])
            ->whereRaw('COALESCE(closed_at, last_message_at, updated_at) < ?', [$cutoff]);

        $reported = $this->reportedIds(PulseConversation::class);
        $reportedMessages = $this->reportedIds(PulseMessage::class);
        $total = 0;
        $lastId = 0;

        while (true) {
            $ids = $closed()->where('id', '>', $lastId)->when($reported !== [], fn ($q) => $q->whereNotIn('id', $reported))
                ->orderBy('id')->limit($chunk)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $lastId = end($ids);

            $messages = DB::table('pulse_messages')->whereIn('pulse_conversation_id', $ids)->whereNull('erased_at')
                ->when($reportedMessages !== [], fn ($q) => $q->whereNotIn('id', $reportedMessages));

            $count = (clone $messages)->count();
            $total += $count;

            if ($dryRun || $count === 0) {
                continue;
            }

            $this->deleteMessagePhotos((clone $messages)->whereNotNull('media_id')->pluck('media_id')->all());
            $messages->update(['body' => 'This message was deleted.', 'kind' => 'system', 'meta' => null, 'media_id' => null, 'erased_at' => now()]);
        }

        return $total;
    }

    /** @param list<int> $mediaIds */
    private function deleteMessagePhotos(array $mediaIds): void
    {
        foreach (DB::table('pulse_media_files')->whereIn('id', $mediaIds)->where('purpose', 'message')->get() as $media) {
            foreach ((array) json_decode((string) $media->variants, true) as $variant) {
                try {
                    Storage::disk($media->disk)->delete($variant['path'] ?? '');
                } catch (Throwable $e) {
                    report($e);
                }
            }

            DB::table('pulse_messages')->where('media_id', $media->id)->update(['media_id' => null]);
            DB::table('pulse_media_files')->where('id', $media->id)->delete();
        }
    }

    private function deleteDeadRequests(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        $reported = $this->reportedIds(PulseConversation::class);

        $dead = fn () => DB::table('pulse_conversations')->whereNull('first_response_at')->where(function ($q) use ($cutoff) {
            $q->where(fn ($d) => $d->where('status', PulseConversation::DECLINED)->whereRaw('COALESCE(closed_at, updated_at) < ?', [$cutoff]))
                ->orWhere(fn ($i) => $i->whereIn('status', [PulseConversation::REQUESTED, PulseConversation::CLOSED])->whereNotNull('ignored_at')->where('ignored_at', '<', $cutoff));
        })->when($reported !== [], fn ($q) => $q->whereNotIn('id', $reported));

        if ($dryRun) {
            return $dead()->count();
        }

        return $this->deleteInChunks($dead, 'pulse_conversations', $chunk, function (array $ids) {
            DB::table('pulse_messages')->whereIn('pulse_conversation_id', $ids)->delete();
        });
    }

    private function deleteEndedSignals(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        $ended = fn () => DB::table('pulse_signals')->where(function ($q) use ($cutoff) {
            $q->where(fn ($e) => $e->where('status', 'expired')->whereRaw('COALESCE(expires_at, updated_at) < ?', [$cutoff]))
                ->orWhere(fn ($r) => $r->where('status', 'removed')->where('updated_at', '<', $cutoff));
        });

        if ($dryRun) {
            return $ended()->count();
        }

        return $this->deleteInChunks($ended, 'pulse_signals', $chunk, function (array $ids) {
            DB::table('pulse_saves')->where('saveable_type', 'signal')->whereIn('saveable_id', $ids)->delete();
            DB::table('pulse_matches')->whereIn('signal_id', $ids)->delete();
        });
    }

    private function deleteDismissedMatches(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        $old = fn () => DB::table('pulse_matches')->whereNotNull('dismissed_at')->where('dismissed_at', '<', $cutoff);

        return $dryRun ? $old()->count() : $this->deleteInChunks($old, 'pulse_matches', $chunk);
    }

    /** A need that ended more than the period ago keeps its title, loses the words the person wrote about it. */
    private function clearEndedNeedText(Carbon $cutoff, bool $dryRun): int
    {
        $ended = DB::table('pulse_needs')->where('status', '!=', 'open')
            ->whereRaw('COALESCE(expires_at, updated_at) < ?', [$cutoff])
            ->where(fn ($q) => $q->whereNotNull('details')->orWhereNotNull('budget_text'));

        return $dryRun ? $ended->count() : $ended->update(['details' => null, 'budget_text' => null]);
    }

    private function deleteReadNotifications(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        $old = fn () => DB::table('notifications')->whereNotNull('read_at')->where('read_at', '<', $cutoff);

        return $dryRun ? $old()->count() : $this->deleteInChunks($old, 'notifications', $chunk);
    }

    private function anonymiseAudit(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        $old = fn () => DB::table('pulse_audit_log')->where('created_at', '<', $cutoff)->where(fn ($q) => $q->whereNotNull('actor_id')->orWhereNotNull('ip_hash'));

        if ($dryRun) {
            return $old()->count();
        }

        $total = 0;

        do {
            $ids = $old()->orderBy('id')->limit($chunk)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            // The only change the append only log allows: the person is taken out of the row, the event stays.
            $total += DB::table('pulse_audit_log')->whereIn('id', $ids)->update(['actor_id' => null, 'ip_hash' => null]);
        } while (count($ids) === $chunk);

        return $total;
    }

    private function deleteOldInvitations(Carbon $cutoff, int $chunk, bool $dryRun): int
    {
        $old = fn () => DB::table('pulse_entity_invitations')->where(function ($q) use ($cutoff) {
            $q->where('expires_at', '<', $cutoff)
                ->orWhere('accepted_at', '<', $cutoff)
                ->orWhere('declined_at', '<', $cutoff)
                ->orWhere('revoked_at', '<', $cutoff);
        });

        return $dryRun ? $old()->count() : $this->deleteInChunks($old, 'pulse_entity_invitations', $chunk);
    }

    /**
     * @param  callable(): Builder  $query  a fresh builder selecting the rows to delete
     * @param  (callable(list<int|string>): void)|null  $before  runs on each chunk of ids just before they are deleted
     */
    private function deleteInChunks(callable $query, string $table, int $chunk, ?callable $before = null): int
    {
        $total = 0;

        do {
            $ids = $query()->orderBy('id')->limit($chunk)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            if ($before !== null) {
                $before($ids);
            }

            $total += DB::table($table)->whereIn('id', $ids)->delete();
        } while (count($ids) === $chunk);

        return $total;
    }

    /** @return list<int> ids of the given model class with a report still open */
    private function reportedIds(string $class): array
    {
        return PulseReport::query()->open()->where('reportable_type', $class)->pluck('reportable_id')->map(fn ($id) => (int) $id)->all();
    }
}
