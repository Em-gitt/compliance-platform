<?php

use App\Enums\Role;
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
        'legal_name' => 'Search Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 80,
        'business_activities' => ['51'],
    ]);

    $this->staff = User::factory()->create([
        'name' => 'Staff Searcher',
        'organization_id' => $this->org->id,
        'role' => Role::ComplianceStaff,
    ]);

    $this->employee = User::factory()->create([
        'name' => 'General Employee',
        'organization_id' => $this->org->id,
        'role' => Role::GeneralEmployee,
    ]);
});

test('AC #1: searching "politica" finds "Política de Seguridad" (accent-insensitive)', function () {
    $doc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Política de Seguridad',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    DocumentVersion::create([
        'document_id' => $doc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/pol.bin',
        'original_filename' => 'politica.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'pol'),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $response = $this->actingAs($this->staff)->getJson('/api/documents?name=politica');

    $response->assertOk()
        ->assertJsonFragment(['name' => 'Política de Seguridad']);

    // Also verify requirement accent search
    ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Gestión Ambiental Estándar',
        'description' => 'Ambiental desc',
        'regulation' => 'NOM-001',
        'deadline' => Carbon::now()->addMonths(6)->toDateString(),
        'responsible_user_id' => $this->staff->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);

    $reqRes = $this->actingAs($this->staff)->getJson('/api/compliance-requirements?name=gestion');
    $reqRes->assertOk()
        ->assertJsonFragment(['name' => 'Gestión Ambiental Estándar']);
});

test('AC #2: combining category + expiration range returns only documents that meet both', function () {
    // Doc 1: category = permit_license, expires in 20 days (target match)
    $doc1 = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Matching Permit',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);
    DocumentVersion::create([
        'document_id' => $doc1->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/d1.bin',
        'original_filename' => 'd1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'd1'),
        'status' => 'processed',
        'expiration_date' => Carbon::now()->addDays(20)->toDateString(),
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    // Doc 2: category = permit_license, expires in 60 days (outside range)
    $doc2 = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Out of range Permit',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);
    DocumentVersion::create([
        'document_id' => $doc2->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/d2.bin',
        'original_filename' => 'd2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'd2'),
        'status' => 'processed',
        'expiration_date' => Carbon::now()->addDays(60)->toDateString(),
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    // Doc 3: category = policy, expires in 20 days (wrong category)
    $doc3 = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Wrong category Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);
    DocumentVersion::create([
        'document_id' => $doc3->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/d3.bin',
        'original_filename' => 'd3.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'd3'),
        'status' => 'processed',
        'expiration_date' => Carbon::now()->addDays(20)->toDateString(),
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $from = Carbon::now()->addDays(10)->toDateString();
    $to = Carbon::now()->addDays(30)->toDateString();

    $response = $this->actingAs($this->staff)->getJson("/api/documents?category=permit_license&expires_from={$from}&expires_to={$to}");

    $response->assertOk();
    $data = $response->json();
    $items = isset($data['data']) ? $data['data'] : $data;

    expect(count($items))->toBe(1);
    expect($items[0]['name'])->toBe('Matching Permit');
});

test('AC #3: a General Employee never receives compliance_only documents in results', function () {
    // all_staff document
    Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Staff Public Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    // compliance_only document
    Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Confidential Compliance Audit',
        'category' => 'report',
        'access_level' => 'compliance_only',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    // Employee query
    $empRes = $this->actingAs($this->employee)->getJson('/api/documents');
    $empRes->assertOk();
    $empData = isset($empRes->json()['data']) ? $empRes->json()['data'] : $empRes->json();

    expect(count($empData))->toBe(1);
    expect($empData[0]['name'])->toBe('Staff Public Policy');

    // Staff query sees both
    $staffRes = $this->actingAs($this->staff)->getJson('/api/documents');
    $staffRes->assertOk();
    $staffData = isset($staffRes->json()['data']) ? $staffRes->json()['data'] : $staffRes->json();
    expect(count($staffData))->toBe(2);
});

test('exact date validation errors: from after to and invalid date', function () {
    // 1. From after To -> 422 "The 'from' date must be before or equal to the 'to' date."
    $res1 = $this->actingAs($this->staff)->getJson('/api/documents?expires_from=2026-12-31&expires_to=2026-01-01');
    $res1->assertStatus(422)
        ->assertJson(['message' => "The 'from' date must be before or equal to the 'to' date."]);

    $reqRes1 = $this->actingAs($this->staff)->getJson('/api/compliance-requirements?deadline_from=2026-12-31&deadline_to=2026-01-01');
    $reqRes1->assertStatus(422)
        ->assertJson(['message' => "The 'from' date must be before or equal to the 'to' date."]);

    // 2. Invalid date -> 422 "Enter a valid date."
    $res2 = $this->actingAs($this->staff)->getJson('/api/documents?expires_from=not-a-date');
    $res2->assertStatus(422)
        ->assertJson(['message' => 'Enter a valid date.']);

    $reqRes2 = $this->actingAs($this->staff)->getJson('/api/compliance-requirements?deadline_to=bad-date');
    $reqRes2->assertStatus(422)
        ->assertJson(['message' => 'Enter a valid date.']);
});
