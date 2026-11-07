<?php

namespace Tests\Feature\Lines;

use App\Models\PulseAuditEntry;
use App\Services\Pulse\Lines\AuditLog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\Support\LinesFixtures;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use LinesFixtures;
    use RefreshDatabase;

    public function test_record_appends_a_row_with_actor_subject_and_meta(): void
    {
        $u = $this->person();
        $page = $this->page($u);

        AuditLog::record($u, 'test.action', $page, ['why' => 'because']);

        $row = PulseAuditEntry::latest('id')->first();
        $this->assertSame($u->id, $row->actor_id);
        $this->assertSame('test.action', $row->action);
        $this->assertSame($page::class, $row->subject_type);
        $this->assertSame($page->id, $row->subject_id);
        $this->assertSame(['why' => 'because'], $row->meta);
        $this->assertNotNull($row->created_at);
    }

    public function test_the_model_has_no_update_path(): void
    {
        AuditLog::record($this->person(), 'x', null);
        $row = PulseAuditEntry::first();

        $this->expectException(LogicException::class);
        $row->update(['action' => 'tampered']);
    }

    public function test_the_model_has_no_delete_path(): void
    {
        AuditLog::record($this->person(), 'x', null);

        $this->expectException(LogicException::class);
        PulseAuditEntry::first()->delete();
    }

    public function test_the_database_refuses_bulk_updates(): void
    {
        AuditLog::record($this->person(), 'x', null);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append only');
        DB::table('pulse_audit_log')->update(['action' => 'tampered']);
    }

    public function test_the_database_refuses_bulk_deletes(): void
    {
        AuditLog::record($this->person(), 'x', null);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('append only');
        DB::table('pulse_audit_log')->delete();
    }

    public function test_the_database_refuses_changing_the_actor_to_someone_else(): void
    {
        $a = $this->person();
        $b = $this->person();
        AuditLog::record($a, 'x', null);

        $this->expectException(QueryException::class);
        DB::table('pulse_audit_log')->update(['actor_id' => $b->id]);
    }

    public function test_the_one_permitted_change_is_forgetting_the_actor_for_erasure(): void
    {
        $a = $this->person();
        $b = $this->person();
        AuditLog::record($a, 'a.did', null, ['n' => 1]);
        AuditLog::record($b, 'b.did', null);

        $this->assertSame(1, AuditLog::forgetActor($a));

        $row = PulseAuditEntry::where('action', 'a.did')->first();
        $this->assertNull($row->actor_id);
        $this->assertNull($row->ip_hash);
        $this->assertSame(['n' => 1], $row->meta, 'the record of what happened stays');
        $this->assertSame($b->id, PulseAuditEntry::where('action', 'b.did')->value('actor_id'));
    }

    public function test_deleting_a_user_nulls_the_actor_but_keeps_the_row(): void
    {
        $a = $this->person();
        AuditLog::record($a, 'a.did', null);

        $a->delete();

        $this->assertNull(PulseAuditEntry::where('action', 'a.did')->first()->actor_id);
    }

    public function test_the_service_exposes_no_update_or_delete_method(): void
    {
        $methods = collect((new \ReflectionClass(AuditLog::class))->getMethods(\ReflectionMethod::IS_PUBLIC))->map->getName()->sort()->values()->all();

        $this->assertSame(['forgetActor', 'record'], $methods);
    }
}
