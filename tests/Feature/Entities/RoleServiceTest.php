<?php

namespace Tests\Feature\Entities;

use App\Models\PulseRole;
use App\Models\PulseSignal;
use App\Services\Pulse\Entities\RoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

class RoleServiceTest extends EntitiesTestCase
{
    private function service(): RoleService
    {
        return app(RoleService::class);
    }

    private function signal(PulseRole $role): ?PulseSignal
    {
        return PulseSignal::where('subject_type', 'role')->where('subject_id', $role->id)->first();
    }

    public function test_create_publish_pause_close_and_filled(): void
    {
        $owner = $this->infodotPerson();
        $entity = $this->page($owner);

        $role = $this->service()->create($owner, $entity, $this->roleData());
        $this->assertSame('draft', $role->status);
        $this->assertNull($this->signal($role));

        $role = $this->service()->publish($owner, $role);
        $this->assertSame('live', $this->signal($role)->status);
        $this->assertSame(['bookkeeping', 'excel', 'vat'], $this->signal($role)->skills);

        $this->service()->pause($owner, $role);
        $this->assertSame('paused', $this->signal($role)->status);

        $this->service()->publish($owner, $role->fresh());
        $this->service()->close($owner, $role->fresh(), 'filled');
        $this->assertSame('filled', $role->fresh()->status);
        $this->assertSame('expired', $this->signal($role)->status);
    }

    public function test_only_employer_pages_can_have_roles(): void
    {
        $owner = $this->person();

        $this->expectException(ValidationException::class);
        $this->service()->create($owner, $this->page($owner, ['is_employer' => false]), $this->roleData());
    }

    public function test_the_roles_quota_counts_open_roles_only(): void
    {
        config(['pulse.entities.max_roles' => 2]);
        $owner = $this->person();
        $entity = $this->page($owner);

        $a = $this->service()->create($owner, $entity, $this->roleData(['title' => 'Role one']));
        $this->service()->create($owner, $entity, $this->roleData(['title' => 'Role two']));

        try {
            $this->service()->create($owner, $entity, $this->roleData(['title' => 'Role three']));
            $this->fail('quota');
        } catch (ValidationException) {
        }

        $this->service()->close($owner, $a);
        $this->assertSame('Role three', $this->service()->create($owner, $entity, $this->roleData(['title' => 'Role three']))->title);
    }

    public function test_external_apply_needs_an_address(): void
    {
        $owner = $this->person();

        $this->expectException(ValidationException::class);
        $this->service()->create($owner, $this->page($owner), $this->roleData(['apply_mode' => 'external_url']));
    }

    public function test_authorisation(): void
    {
        $owner = $this->person();
        $entity = $this->page($owner);
        $role = $this->service()->create($owner, $entity, $this->roleData());

        foreach ([$this->person(), $this->member($entity, 'responder')] as $nobody) {
            foreach (['pause', 'publish', 'close', 'delete'] as $method) {
                try {
                    $this->service()->$method($nobody, $role);
                    $this->fail("$method must be refused");
                } catch (AuthorizationException) {
                }
            }
        }

        $editor = $this->member($entity, 'editor');
        $this->assertSame('Edited', $this->service()->update($editor, $role, ['title' => 'Edited'])->title);
    }

    public function test_without_rules_a_stranger_roles_are_held(): void
    {
        $owner = $this->person();
        $role = $this->service()->create($owner, $this->page($owner), $this->roleData() + ['publish' => true]);

        $this->assertSame('pending', $role->moderation);
        $this->assertSame('held', $this->signal($role)->status);
    }

    public function test_deleting_retires_the_signal(): void
    {
        $owner = $this->infodotPerson();
        $role = $this->service()->create($owner, $this->page($owner), $this->roleData() + ['publish' => true]);

        $this->service()->delete($owner, $role);

        $this->assertSame('removed', $this->signal($role)->status);
    }
}
