<?php

use App\Enums\Role;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CatalogSeeder::class);

    $this->org = Organization::create([
        'legal_name' => 'Citation Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 50,
        'business_activities' => ['51'],
    ]);

    $this->staff = User::factory()->create([
        'name' => 'Compliance Officer',
        'organization_id' => $this->org->id,
        'role' => Role::ComplianceStaff,
    ]);

    $this->employee = User::factory()->create([
        'name' => 'Regular Worker',
        'organization_id' => $this->org->id,
        'role' => Role::GeneralEmployee,
    ]);

    $this->complianceDoc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Confidential Internal Audit',
        'category' => 'report',
        'access_level' => 'compliance_only',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    $this->complianceVersion = DocumentVersion::create([
        'document_id' => $this->complianceDoc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/audit.bin',
        'original_filename' => 'audit.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'audit'),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $this->complianceChunk = DocumentChunk::create([
        'organization_id' => $this->org->id,
        'document_id' => $this->complianceDoc->id,
        'document_version_id' => $this->complianceVersion->id,
        'chunk_index' => 0,
        'content' => 'Highly confidential compliance findings on page 4.',
        'page_start' => 4,
        'page_end' => 4,
        'section' => 'Audit Findings',
        'is_current' => true,
    ]);

    $this->publicDoc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Public Safety Manual',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    $this->publicVersion = DocumentVersion::create([
        'document_id' => $this->publicDoc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/safety.bin',
        'original_filename' => 'safety.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'safety'),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $this->publicChunk = DocumentChunk::create([
        'organization_id' => $this->org->id,
        'document_id' => $this->publicDoc->id,
        'document_version_id' => $this->publicVersion->id,
        'chunk_index' => 0,
        'content' => 'In case of fire, use the nearest emergency exit immediately.',
        'page_start' => 2,
        'page_end' => 3,
        'section' => 'Evacuation Plan',
        'is_current' => true,
    ]);
});

test('authorized staff can view compliance_only citation source with highlighted details', function () {
    $response = $this->actingAs($this->staff)->getJson("/api/citations/{$this->complianceChunk->id}");

    $response->assertOk()
        ->assertJsonPath('id', $this->complianceChunk->id)
        ->assertJsonPath('document_name', 'Confidential Internal Audit')
        ->assertJsonPath('page_start', 4)
        ->assertJsonPath('section', 'Audit Findings')
        ->assertJsonPath('content', 'Highly confidential compliance findings on page 4.');
});

test('AC #2: General Employee calling /api/citations/{id} for compliance_only document gets 403 with exact message and no text', function () {
    $response = $this->actingAs($this->employee)->getJson("/api/citations/{$this->complianceChunk->id}");

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to view this source.',
        ]);

    // Ensure NO document text or content is in the response body
    $responseData = $response->json();
    expect(array_key_exists('content', $responseData))->toBeFalse()
        ->and(array_key_exists('text', $responseData))->toBeFalse();

    // Verify access.denied was audited
    $this->assertDatabaseHas('audit_logs', [
        'action' => 'access.denied',
        'resource_type' => 'document',
        'resource_id' => $this->complianceDoc->id,
    ]);
});

test('general employee can view all_staff citation source successfully', function () {
    $response = $this->actingAs($this->employee)->getJson("/api/citations/{$this->publicChunk->id}");

    $response->assertOk()
        ->assertJsonPath('document_name', 'Public Safety Manual')
        ->assertJsonPath('content', 'In case of fire, use the nearest emergency exit immediately.');
});

test('returns exact 404 message when source chunk or document does not exist', function () {
    $response = $this->actingAs($this->staff)->getJson('/api/citations/99999');

    $response->assertStatus(404)
        ->assertJson([
            'message' => 'This source is no longer available.',
        ]);
});

test('cross-organization access returns 403 and audits access.denied', function () {
    $otherOrg = Organization::create([
        'legal_name' => 'Foreign Corp',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);

    $foreignUser = User::factory()->create([
        'organization_id' => $otherOrg->id,
        'role' => Role::ComplianceStaff,
    ]);

    $response = $this->actingAs($foreignUser)->getJson("/api/citations/{$this->publicChunk->id}");

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to view this source.',
        ]);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'access.denied',
        'resource_type' => 'document',
        'resource_id' => $this->publicDoc->id,
    ]);
});
