<?php

use App\Console\Commands\GenerateAlertsCommand;
use App\Enums\Role;
use App\Models\Alert;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CatalogSeeder::class);

    $this->org = Organization::create([
        'legal_name' => 'Alerts Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 50,
        'business_activities' => ['51'],
    ]);

    $this->responsibleUser = User::factory()->create([
        'name' => 'Alice Responsible',
        'organization_id' => $this->org->id,
        'role' => Role::ComplianceStaff,
    ]);

    $this->otherUser = User::factory()->create([
        'name' => 'Bob Other',
        'organization_id' => $this->org->id,
        'role' => Role::GeneralEmployee,
    ]);
});

test('AC #1: requirement due in 7 days creates one alert with exact text; running again creates no duplicate', function () {
    $now = Carbon::parse('2026-11-19', 'America/Mexico_City')->startOfDay();
    $deadline = $now->copy()->addDays(7); // 2026-11-26

    $requirement = ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Fire Safety Standard',
        'description' => 'Annual fire inspection',
        'regulation' => 'NOM-002-STPS-2010',
        'deadline' => $deadline->toDateString(),
        'responsible_user_id' => $this->responsibleUser->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->responsibleUser->id,
    ]);

    $this->artisan(GenerateAlertsCommand::class, ['--date' => '2026-11-19'])
        ->assertSuccessful();

    $expectedText = 'Requirement "Fire Safety Standard" is due in 7 days (26/11/2026). Responsible: Alice Responsible.';

    $this->assertDatabaseHas('alerts', [
        'type' => 'requirement_due',
        'compliance_requirement_id' => $requirement->id,
        'user_id' => $this->responsibleUser->id,
        'period_days' => 7,
        'message' => $expectedText,
    ]);

    expect(Alert::where('compliance_requirement_id', $requirement->id)->count())->toBe(1);

    // Running again creates no duplicate
    $this->artisan(GenerateAlertsCommand::class, ['--date' => '2026-11-19'])
        ->assertSuccessful();

    expect(Alert::where('compliance_requirement_id', $requirement->id)->count())->toBe(1);
});

test('AC #2: document version expiring in 30 days generates the exact document alert', function () {
    $now = Carbon::parse('2026-11-19', 'America/Mexico_City')->startOfDay();
    $expiration = $now->copy()->addDays(30); // 2026-12-19

    $doc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Operating Permit',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->responsibleUser->id,
    ]);

    $version = DocumentVersion::create([
        'document_id' => $doc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/op.bin',
        'original_filename' => 'operating_permit.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'operating_permit'),
        'status' => 'processed',
        'expiration_date' => $expiration->toDateString(),
        'is_current' => true,
        'uploaded_by' => $this->responsibleUser->id,
        'uploaded_at' => now(),
    ]);

    $this->artisan(GenerateAlertsCommand::class, ['--date' => '2026-11-19'])
        ->assertSuccessful();

    $expectedText = 'Document "Operating Permit" version v1.0 expires in 30 days (19/12/2026). Responsible: Alice Responsible.';

    $this->assertDatabaseHas('alerts', [
        'type' => 'document_expiring',
        'document_version_id' => $version->id,
        'user_id' => $this->responsibleUser->id,
        'period_days' => 30,
        'message' => $expectedText,
    ]);
});

test('AC #3: only the allowed periods can be saved; any other returns the exact message', function () {
    $response = $this->actingAs($this->responsibleUser)->postJson('/api/requirements', [
        'name' => 'Invalid Notification Period Requirement',
        'description' => 'Test invalid periods',
        'regulation' => 'NOM-001',
        'deadline' => Carbon::now()->addMonths(3)->toDateString(),
        'responsible_user_id' => $this->responsibleUser->id,
        'status' => 'pending',
        'notification_periods' => [45, 10], // Not in [30, 15, 7, 1]
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Notification period must be one of: 30, 15, 7 or 1 days.',
        ]);

    // Test valid periods succeeds
    $validResponse = $this->actingAs($this->responsibleUser)->postJson('/api/requirements', [
        'name' => 'Valid Notification Period Requirement',
        'description' => 'Test valid periods',
        'regulation' => 'NOM-001',
        'deadline' => Carbon::now()->addMonths(3)->toDateString(),
        'responsible_user_id' => $this->responsibleUser->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
    ]);

    $validResponse->assertStatus(201);
});

test('AC #4: the responsible user sees the alert in the bell / API; other users do not', function () {
    Alert::create([
        'user_id' => $this->responsibleUser->id,
        'type' => 'requirement_due',
        'compliance_requirement_id' => null,
        'document_version_id' => null,
        'period_days' => 7,
        'message' => 'Requirement "Safety" is due in 7 days.',
        'due_date' => Carbon::now()->addDays(7)->toDateString(),
        'created_at' => now(),
    ]);

    // Responsible user sees it
    $res = $this->actingAs($this->responsibleUser)->getJson('/api/alerts');
    $res->assertOk()
        ->assertJsonPath('unread_count', 1)
        ->assertJsonCount(1, 'alerts');

    // Other user does NOT see it
    $otherRes = $this->actingAs($this->otherUser)->getJson('/api/alerts');
    $otherRes->assertOk()
        ->assertJsonPath('unread_count', 0)
        ->assertJsonCount(0, 'alerts');
});

test('overdue requirement generates exact overdue alert', function () {
    $yesterday = Carbon::parse('2026-11-18', 'America/Mexico_City')->startOfDay();

    $requirement = ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Overdue Requirement Test',
        'description' => 'Overdue description',
        'regulation' => 'NOM-003',
        'deadline' => $yesterday->toDateString(),
        'responsible_user_id' => $this->responsibleUser->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->responsibleUser->id,
    ]);

    $this->artisan(GenerateAlertsCommand::class, ['--date' => '2026-11-19'])
        ->assertSuccessful();

    $expectedText = 'Requirement "Overdue Requirement Test" is overdue since 18/11/2026. Responsible: Alice Responsible.';

    $this->assertDatabaseHas('alerts', [
        'type' => 'requirement_overdue',
        'compliance_requirement_id' => $requirement->id,
        'user_id' => $this->responsibleUser->id,
        'message' => $expectedText,
    ]);
});

test('alerts can be marked as read individually or all at once', function () {
    $a1 = Alert::create([
        'user_id' => $this->responsibleUser->id,
        'type' => 'requirement_due',
        'period_days' => 7,
        'message' => 'Alert 1',
        'due_date' => Carbon::now()->toDateString(),
    ]);

    $a2 = Alert::create([
        'user_id' => $this->responsibleUser->id,
        'type' => 'requirement_due',
        'period_days' => 1,
        'message' => 'Alert 2',
        'due_date' => Carbon::now()->toDateString(),
    ]);

    // Mark single as read
    $readRes = $this->actingAs($this->responsibleUser)->postJson("/api/alerts/{$a1->id}/read");
    $readRes->assertOk()->assertJsonPath('message', 'Alert marked as read.');
    expect($a1->fresh()->read_at)->not->toBeNull();
    expect($a2->fresh()->read_at)->toBeNull();

    // Mark all as read
    $allRes = $this->actingAs($this->responsibleUser)->postJson('/api/alerts/read-all');
    $allRes->assertOk()->assertJsonPath('message', 'All alerts marked as read.');
    expect($a2->fresh()->read_at)->not->toBeNull();
});
