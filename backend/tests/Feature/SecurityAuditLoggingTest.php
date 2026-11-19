<?php

use App\Enums\Role;
use App\Exceptions\AuditLogImmutableException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Security Audit Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 15,
        'business_activities' => ['51'],
    ]);

    $this->admin = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Security Admin',
        'email' => 'admin@security.test',
        'password' => 'AdminPass123!',
        'role' => Role::Administrator,
        'is_active' => true,
    ]);

    $this->complianceUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance Staff',
        'email' => 'staff@security.test',
        'password' => 'StaffPass123!',
        'role' => Role::ComplianceStaff,
        'is_active' => true,
    ]);
});

it('records audit logs with details for successful login, failed login, access denial and role change', function (): void {
    // 1. Successful login
    $this->postJson('/api/auth/login', [
        'email' => 'admin@security.test',
        'password' => 'AdminPass123!',
    ])->assertOk();

    $loginAudit = AuditLog::where('action', 'auth.login_succeeded')->where('user_id', $this->admin->id)->first();
    expect($loginAudit)->not->toBeNull();
    expect($loginAudit->category)->toBe('security');
    expect($loginAudit->resource_type)->toBe('user');
    expect($loginAudit->resource_id)->toBe($this->admin->id);
    expect($loginAudit->ip_address)->not->toBeNull();
    expect($loginAudit->created_at)->not->toBeNull();

    // 2. Failed login
    $this->postJson('/api/auth/login', [
        'email' => 'admin@security.test',
        'password' => 'WrongPassword',
    ])->assertStatus(401);

    $failedAudit = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();
    expect($failedAudit)->not->toBeNull();
    expect($failedAudit->category)->toBe('security');
    expect($failedAudit->metadata['attempted_email'])->toBe('admin@security.test');
    expect($failedAudit->ip_address)->not->toBeNull();

    // 3. Access denial
    $this->actingAs($this->complianceUser)
        ->postJson('/api/users', [
            'name' => 'Denied User',
            'email' => 'denied@example.test',
            'role' => 'general_employee',
            'password' => 'Password123!',
        ])->assertForbidden();

    $deniedAudit = AuditLog::where('action', 'access.denied')->where('user_id', $this->complianceUser->id)->first();
    expect($deniedAudit)->not->toBeNull();
    expect($deniedAudit->category)->toBe('security');
    expect($deniedAudit->metadata['user_role'])->toBe(Role::ComplianceStaff->value);

    // 4. Role change
    $targetUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Role Change Target',
        'email' => 'target@security.test',
        'password' => 'TargetPass123!',
        'role' => Role::GeneralEmployee,
        'is_active' => true,
    ]);

    $this->actingAs($this->admin)
        ->putJson("/api/users/{$targetUser->id}", [
            'name' => 'Role Change Target',
            'email' => 'target@security.test',
            'role' => 'compliance_staff',
        ])->assertOk();

    $roleAudit = AuditLog::where('action', 'user.role_changed')->where('resource_id', $targetUser->id)->first();
    expect($roleAudit)->not->toBeNull();
    expect($roleAudit->category)->toBe('security');
    expect($roleAudit->metadata['from'])->toBe('general_employee');
    expect($roleAudit->metadata['to'])->toBe('compliance_staff');
});

it('rejects direct UPDATE or DELETE in database via immutability trigger', function (): void {
    $log = AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'category' => 'security',
        'action' => 'auth.login_succeeded',
        'resource_type' => 'user',
        'resource_id' => $this->admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestRunner',
        'metadata' => [],
        'created_at' => now(),
    ]);

    // Test direct DB UPDATE
    try {
        DB::statement("UPDATE audit_logs SET action = 'tampered' WHERE id = ?", [$log->id]);
        $this->fail('Expected database trigger to block UPDATE.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('audit_logs records are immutable');
    }

    // Test direct DB DELETE
    try {
        DB::statement('DELETE FROM audit_logs WHERE id = ?', [$log->id]);
        $this->fail('Expected database trigger to block DELETE.');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('audit_logs records are immutable');
    }
});

it('blocks PUT and DELETE to /api/audit-logs/{id} with 403 and creates audit.modification_denied', function (): void {
    $log = AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'category' => 'security',
        'action' => 'auth.login_succeeded',
        'resource_type' => 'user',
        'resource_id' => $this->admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestRunner',
        'metadata' => [],
        'created_at' => now(),
    ]);

    // PUT attempt
    $putResponse = $this->actingAs($this->admin)->putJson("/api/audit-logs/{$log->id}", [
        'action' => 'altered',
    ]);

    $putResponse->assertStatus(403)
        ->assertJson(['message' => 'Audit records cannot be modified or deleted.']);

    // DELETE attempt
    $deleteResponse = $this->actingAs($this->admin)->deleteJson("/api/audit-logs/{$log->id}");

    $deleteResponse->assertStatus(403)
        ->assertJson(['message' => 'Audit records cannot be modified or deleted.']);

    // Verify audit.modification_denied was recorded
    $modDeniedLogs = AuditLog::where('action', 'audit.modification_denied')->get();
    expect($modDeniedLogs->count())->toBe(2);
    expect($modDeniedLogs->first()->category)->toBe('security');
    expect($modDeniedLogs->first()->resource_type)->toBe('audit_log');
});

it('throws AuditLogImmutableException when updating or deleting through Eloquent model', function (): void {
    $log = AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'category' => 'security',
        'action' => 'auth.login_succeeded',
        'resource_type' => 'user',
        'resource_id' => $this->admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestRunner',
        'metadata' => [],
        'created_at' => now(),
    ]);

    expect(fn () => $log->update(['action' => 'modified']))
        ->toThrow(AuditLogImmutableException::class, 'Audit records cannot be modified or deleted.');

    expect(fn () => $log->delete())
        ->toThrow(AuditLogImmutableException::class, 'Audit records cannot be modified or deleted.');
});

it('allows Administrator to query audit logs with pagination and filters', function (): void {
    AuditLog::query()->create([
        'user_id' => $this->admin->id,
        'category' => 'security',
        'action' => 'auth.login_succeeded',
        'resource_type' => 'user',
        'resource_id' => $this->admin->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'TestRunner',
        'metadata' => [],
        'created_at' => now(),
    ]);

    $response = $this->actingAs($this->admin)->getJson('/api/audit-logs');
    $response->assertOk()
        ->assertJsonStructure([
            'data' => [
                '*' => ['id', 'category', 'action', 'user_id', 'ip_address', 'created_at'],
            ],
            'current_page',
            'per_page',
        ]);

    expect($response->json('per_page'))->toBe(50);
});
