<?php

use App\Enums\Role;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\RequiredDocument;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CatalogSeeder::class);

    $this->org = Organization::create([
        'legal_name' => 'Dashboard Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 100,
        'business_activities' => ['51'],
    ]);

    $this->admin = User::factory()->create([
        'organization_id' => $this->org->id,
        'role' => Role::Administrator,
    ]);

    $this->staff = User::factory()->create([
        'organization_id' => $this->org->id,
        'role' => Role::ComplianceStaff,
    ]);

    $this->employee = User::factory()->create([
        'organization_id' => $this->org->id,
        'role' => Role::GeneralEmployee,
    ]);
});

test('AC #1: Admin and Compliance Staff can view dashboard; General Employee receives 403 exact message', function () {
    // Admin access
    $adminRes = $this->actingAs($this->admin)->getJson('/api/dashboard');
    $adminRes->assertOk()->assertJsonStructure(['summary', 'requirements']);

    // Staff access
    $staffRes = $this->actingAs($this->staff)->getJson('/api/dashboard');
    $staffRes->assertOk()->assertJsonStructure(['summary', 'requirements']);

    // Employee access denied
    $empRes = $this->actingAs($this->employee)->getJson('/api/dashboard');
    $empRes->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to view the compliance dashboard.',
        ]);
});

test('AC #2: dashboard computes summary counts and status distribution accurately', function () {
    // 1 compliant
    ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Req Compliant',
        'description' => 'Desc',
        'regulation' => 'REG-1',
        'deadline' => Carbon::now()->addMonths(2)->toDateString(),
        'responsible_user_id' => $this->staff->id,
        'status' => 'compliant',
        'applies' => true,
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);

    // 1 pending with 1 missing document
    $pendingReq = ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Req Pending',
        'description' => 'Desc',
        'regulation' => 'REG-2',
        'deadline' => Carbon::now()->addDays(5)->toDateString(),
        'responsible_user_id' => $this->staff->id,
        'status' => 'pending',
        'applies' => true,
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);
    RequiredDocument::create([
        'compliance_requirement_id' => $pendingReq->id,
        'name' => 'Required Doc 1',
        'category' => 'permit_license',
        'document_id' => null, // missing
    ]);

    // 1 at_risk
    ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Req At Risk',
        'description' => 'Desc',
        'regulation' => 'REG-3',
        'deadline' => Carbon::now()->addDays(3)->toDateString(),
        'responsible_user_id' => $this->staff->id,
        'status' => 'at_risk',
        'applies' => true,
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);

    // 1 overdue
    ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Req Overdue',
        'description' => 'Desc',
        'regulation' => 'REG-4',
        'deadline' => Carbon::now()->subDays(2)->toDateString(),
        'responsible_user_id' => $this->staff->id,
        'status' => 'overdue',
        'applies' => true,
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);

    // 1 does not apply (should be excluded)
    ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Req Inactive',
        'description' => 'Desc',
        'regulation' => 'REG-5',
        'deadline' => Carbon::now()->addMonths(1)->toDateString(),
        'responsible_user_id' => $this->staff->id,
        'status' => 'pending',
        'applies' => false,
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);

    $res = $this->actingAs($this->staff)->getJson('/api/dashboard');
    $res->assertOk()
        ->assertJsonPath('summary.applicable_requirements', 4)
        ->assertJsonPath('summary.compliant', 1)
        ->assertJsonPath('summary.pending', 1)
        ->assertJsonPath('summary.at_risk', 1)
        ->assertJsonPath('summary.overdue', 1)
        ->assertJsonPath('summary.missing_documents', 1)
        ->assertJsonCount(4, 'requirements');
});

test('AC #3: empty state when no applicable requirements exist', function () {
    $res = $this->actingAs($this->staff)->getJson('/api/dashboard');

    $res->assertOk()
        ->assertJsonPath('summary.applicable_requirements', 0)
        ->assertJsonCount(0, 'requirements');
});
