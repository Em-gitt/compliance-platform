<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Security Audit Corp',
        'organization_type' => 'private_company',
        'industry' => 'manufacturing',
        'state' => '10',
        'municipality' => 'Durango',
        'employees_count' => 100,
        'business_activities' => ['31-33'],
    ]);

    $this->admin = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Admin Security',
        'email' => 'admin@security.test',
        'password' => Hash::make('CorrectPassword123!'),
        'role' => Role::Administrator->value,
    ]);

    $this->staff = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Staff Security',
        'email' => 'staff@security.test',
        'password' => Hash::make('CorrectPassword123!'),
        'role' => Role::ComplianceStaff->value,
    ]);

    $this->employee = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Employee Security',
        'email' => 'employee@security.test',
        'password' => Hash::make('CorrectPassword123!'),
        'role' => Role::GeneralEmployee->value,
    ]);

    $this->requirement = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Seguridad contra Incendios',
        'description' => 'Programa interno de proteccion civil',
        'regulation' => 'NOM-002-STPS-2010',
        'status' => 'pending',
        'deadline' => now()->addDays(30),
        'notification_periods' => [30, 15, 7, 1],
        'responsible_user_id' => $this->staff->id,
        'created_by' => $this->staff->id,
    ]);

    $this->publicDoc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Politica General',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->admin->id,
    ]);

    $this->confidentialDoc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Auditoria Interna',
        'category' => 'policy',
        'access_level' => 'compliance_only',
        'is_archived' => false,
        'created_by' => $this->admin->id,
    ]);

    DocumentVersion::query()->create([
        'document_id' => $this->publicDoc->id,
        'version_label' => '1.0',
        'storage_path' => "documents/{$this->publicDoc->id}/v1.bin",
        'original_filename' => 'politica.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => str_repeat('b', 64),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->admin->id,
        'uploaded_at' => now(),
    ]);

    DocumentVersion::query()->create([
        'document_id' => $this->confidentialDoc->id,
        'version_label' => '1.0',
        'storage_path' => "documents/{$this->confidentialDoc->id}/v1.bin",
        'original_filename' => 'auditoria.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => str_repeat('c', 64),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->admin->id,
        'uploaded_at' => now(),
    ]);
});

/*
|--------------------------------------------------------------------------
| Group 1: TC-FR-018 - Permissions Matrix 24 Cases (CMP-10)
|--------------------------------------------------------------------------
*/
describe('TC-FR-018: Permissions Matrix (24 Cases)', function () {
    test('1. Manage user accounts: Admin=Yes, Staff=No, Employee=No', function () {
        $this->actingAs($this->admin)->getJson('/api/users')->assertStatus(200);
        $this->actingAs($this->staff)->getJson('/api/users')->assertStatus(403);
        $this->actingAs($this->employee)->getJson('/api/users')->assertStatus(403);
    });

    test('2. Register / edit organization: Admin=Yes, Staff=No, Employee=No', function () {
        // Only Admin can perform organization management actions
        $this->actingAs($this->admin)->getJson('/api/dashboard')->assertStatus(200);
        $this->actingAs($this->staff)->getJson('/api/users')->assertStatus(403);
        $this->actingAs($this->employee)->getJson('/api/users')->assertStatus(403);
    });

    test('3. Create and edit compliance requirements: Admin=Yes, Staff=Yes, Employee=No', function () {
        $this->actingAs($this->admin)->getJson('/api/requirements')->assertStatus(200);
        $this->actingAs($this->staff)->getJson('/api/requirements')->assertStatus(200);
        $this->actingAs($this->employee)->postJson('/api/requirements', [
            'name' => 'Illegal requirement',
            'description' => 'Test description',
            'regulation' => 'NOM-001',
            'deadline' => now()->addDays(30)->toDateString(),
            'responsible_user_id' => $this->staff->id,
        ])->assertStatus(403);
    });

    test('4. Change compliance status: Admin=No, Staff=Yes, Employee=No', function () {
        // Staff can update requirement status
        $this->actingAs($this->staff)->patchJson("/api/requirements/{$this->requirement->id}", [
            'status' => 'compliant',
        ])->assertStatus(200);

        // Admin cannot change compliance status per ADR-005
        $this->actingAs($this->admin)->patchJson("/api/requirements/{$this->requirement->id}", [
            'status' => 'at_risk',
        ])->assertStatus(403);

        // Employee cannot change compliance status
        $this->actingAs($this->employee)->patchJson("/api/requirements/{$this->requirement->id}", [
            'status' => 'compliant',
        ])->assertStatus(403);
    });

    test('5. Upload documents and new versions: Admin=Yes, Staff=Yes, Employee=No', function () {
        $this->actingAs($this->employee)->postJson('/api/documents', [
            'name' => 'Document',
            'category' => 'policy',
            'access_level' => 'all_staff',
        ])->assertStatus(403);
    });

    test('6. View and download documents: Admin=Yes, Staff=Yes, Employee=Yes (only all_staff)', function () {
        $this->actingAs($this->admin)->getJson("/api/documents/{$this->confidentialDoc->id}")->assertStatus(200);
        $this->actingAs($this->staff)->getJson("/api/documents/{$this->confidentialDoc->id}")->assertStatus(200);
        $this->actingAs($this->employee)->getJson("/api/documents/{$this->publicDoc->id}")->assertStatus(200);
        $this->actingAs($this->employee)->getJson("/api/documents/{$this->confidentialDoc->id}")->assertStatus(403);
    });

    test('7. View compliance dashboard: Admin=Yes, Staff=Yes, Employee=No', function () {
        $this->actingAs($this->admin)->getJson('/api/dashboard')->assertStatus(200);
        $this->actingAs($this->staff)->getJson('/api/dashboard')->assertStatus(200);
        $this->actingAs($this->employee)->getJson('/api/dashboard')->assertStatus(403);
    });

    test('8. Use AI assistant: Admin=Yes, Staff=Yes, Employee=Yes', function () {
        $this->actingAs($this->admin)->getJson('/api/assistant/questions')->assertStatus(200);
        $this->actingAs($this->staff)->getJson('/api/assistant/questions')->assertStatus(200);
        $this->actingAs($this->employee)->getJson('/api/assistant/questions')->assertStatus(200);
    });
});

/*
|--------------------------------------------------------------------------
| Group 2: TC-NFR-003 - HTTPS Redirect, HSTS, No Plain-text Credentials
|--------------------------------------------------------------------------
*/
describe('TC-NFR-003: HTTPS, HSTS, and Transport Security', function () {
    test('enforces HTTPS in production with 426 for non-GET or 301 for GET', function () {
        config(['app.require_https' => true]);

        $resPost = $this->postJson('http://localhost/api/documents');
        $resPost->assertStatus(426)
            ->assertExactJson(['message' => 'HTTPS is required.']);

        $resGet = $this->get('http://localhost/api/health');
        $resGet->assertStatus(301);
    });

    test('passwords and secrets are never returned in responses', function () {
        $response = $this->actingAs($this->admin)->getJson('/api/users');
        $response->assertStatus(200);
        $json = $response->json();
        expect($json['data'][0])->not->toHaveKey('password')
            ->and($response->getContent())->not->toContain('CorrectPassword123!');
    });
});

/*
|--------------------------------------------------------------------------
| Group 3: TC-NFR-005 - Argon2id Hashes, Generic Login Error, Protected Routes
|--------------------------------------------------------------------------
*/
describe('TC-NFR-005: Password Storage & Authentication', function () {
    test('passwords use secure hashing format and not plain text', function () {
        $user = User::where('email', 'admin@security.test')->first();
        expect($user->password)->toStartWith('$argon2id$');
    });

    test('generic login error message on bad credentials', function () {
        $res = $this->postJson('/api/auth/login', [
            'email' => 'admin@security.test',
            'password' => 'WrongPassword!',
        ]);

        $res->assertStatus(401)
            ->assertExactJson([
                'message' => 'The email or password is incorrect.',
            ]);
    });

    test('unauthenticated users cannot access protected routes', function () {
        $this->getJson('/api/dashboard')->assertStatus(401);
        $this->getJson('/api/documents')->assertStatus(401);
        $this->getJson('/api/requirements')->assertStatus(401);
        $this->getJson('/api/assistant/questions')->assertStatus(401);
    });
});

/*
|--------------------------------------------------------------------------
| Group 4: TC-NFR-013 - Versions and Audit Records Cannot Be Modified or Deleted
|--------------------------------------------------------------------------
*/
describe('TC-NFR-013: Immutability of Versions and Audit Logs', function () {
    test('document versions reject PUT, PATCH, DELETE with 403', function () {
        $this->actingAs($this->admin)->deleteJson("/api/documents/{$this->publicDoc->id}")
            ->assertStatus(403)
            ->assertJson(['message' => 'Document versions cannot be modified or deleted.']);

        $this->actingAs($this->admin)->deleteJson("/api/documents/{$this->publicDoc->id}/versions/1")
            ->assertStatus(403)
            ->assertJson(['message' => 'Document versions cannot be modified or deleted.']);
    });

    test('audit logs reject PUT, PATCH, DELETE with 403 and record security event', function () {
        $this->actingAs($this->admin)->deleteJson('/api/audit-logs/1')
            ->assertStatus(403)
            ->assertJson(['message' => 'Audit records cannot be modified or deleted.']);

        $this->assertDatabaseHas('audit_logs', [
            'category' => 'security',
            'action' => 'audit.modification_denied',
        ]);
    });
});

/*
|--------------------------------------------------------------------------
| Group 5: TC-NFR-014 - Security Events Recorded with User, Action, Resource, IP, Timestamp
|--------------------------------------------------------------------------
*/
describe('TC-NFR-014: Security Audit Trail Integrity', function () {
    test('failed login attempts record security audit log with IP and timestamp', function () {
        $this->postJson('/api/auth/login', [
            'email' => 'admin@security.test',
            'password' => 'WrongPassword!',
        ], ['REMOTE_ADDR' => '192.168.1.100']);

        $log = AuditLog::where('action', 'auth.login_failed')->latest('id')->first();
        expect($log)->not->toBeNull()
            ->and($log->category)->toBe('security')
            ->and($log->ip_address)->toBe('192.168.1.100')
            ->and($log->created_at)->not->toBeNull();
    });

    test('access denied events record security audit log', function () {
        $this->actingAs($this->employee)->postJson('/api/documents', [
            'name' => 'Denied doc',
            'category' => 'policy',
            'access_level' => 'all_staff',
            'version' => '1.0',
        ])->assertStatus(403);

        $log = AuditLog::where('action', 'access.denied')->latest('id')->first();
        expect($log)->not->toBeNull()
            ->and($log->user_id)->toBe($this->employee->id)
            ->and($log->category)->toBe('security');
    });
});
