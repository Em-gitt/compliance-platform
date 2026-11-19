<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Services\RequiredDocumentStatusService;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(CatalogSeeder::class);

    $this->org = Organization::create([
        'legal_name' => 'Acme Corp',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 100,
        'business_activities' => ['51'],
    ]);

    $this->staff = User::factory()->create([
        'organization_id' => $this->org->id,
        'role' => Role::ComplianceStaff,
    ]);

    $this->requirement = ComplianceRequirement::create([
        'organization_id' => $this->org->id,
        'name' => 'Hazardous Waste Management',
        'description' => 'Annual hazardous waste authorization',
        'regulation' => 'NOM-052-SEMARNAT-2005',
        'deadline' => Carbon::now()->addMonths(6),
        'responsible_user_id' => $this->staff->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);

    $this->requiredDoc = RequiredDocument::create([
        'compliance_requirement_id' => $this->requirement->id,
        'name' => 'Waste Manifest',
        'category' => 'permit_license',
        'document_id' => null,
    ]);
});

test('a requirement with a required document and no linked document shows Missing Document (AC #1)', function () {
    $response = $this->actingAs($this->staff)->getJson("/api/requirements/{$this->requirement->id}");

    $response->assertOk()
        ->assertJsonPath('missing_documents_count', 1)
        ->assertJsonPath('all_documents_provided', false)
        ->assertJsonPath('missing_documents_label', '1 missing documents')
        ->assertJsonPath('required_documents.0.status', 'missing')
        ->assertJsonPath('required_documents.0.badge', 'Missing Document')
        ->assertJsonPath('required_documents.0.is_satisfied', false);
});

test('linking a processed document of the right category changes it to Provided without page reload (AC #2)', function () {
    $doc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'SEMARNAT Permit 2026',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    DocumentVersion::create([
        'document_id' => $doc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/test.bin',
        'original_filename' => 'permit.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'content'),
        'status' => 'processed',
        'expiration_date' => Carbon::now('America/Mexico_City')->addYear()->toDateString(),
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $response = $this->actingAs($this->staff)->postJson("/api/requirements/{$this->requirement->id}/documents", [
        'required_document_id' => $this->requiredDoc->id,
        'document_id' => $doc->id,
    ]);

    $response->assertOk()
        ->assertJsonPath('message', 'Document linked successfully.')
        ->assertJsonPath('required_document.status', 'provided')
        ->assertJsonPath('required_document.badge', 'Provided')
        ->assertJsonPath('required_document.is_satisfied', true);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'requirement.document_linked',
        'resource_type' => 'requirement',
        'resource_id' => $this->requirement->id,
    ]);

    // Check show endpoint now reflects Provided
    $showRes = $this->actingAs($this->staff)->getJson("/api/requirements/{$this->requirement->id}");
    $showRes->assertOk()
        ->assertJsonPath('missing_documents_count', 0)
        ->assertJsonPath('all_documents_provided', true)
        ->assertJsonPath('missing_documents_label', 'All documents provided')
        ->assertJsonPath('required_documents.0.status', 'provided')
        ->assertJsonPath('required_documents.0.badge', 'Provided');
});

test('pest tests cover: expired document shows Missing Document with tooltip', function () {
    $doc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Expired Permit 2025',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    $expiredDate = Carbon::now('America/Mexico_City')->subDays(5)->toDateString();

    DocumentVersion::create([
        'document_id' => $doc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/test.bin',
        'original_filename' => 'permit.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'content'),
        'status' => 'processed',
        'expiration_date' => $expiredDate,
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $this->requiredDoc->update(['document_id' => $doc->id]);

    $response = $this->actingAs($this->staff)->getJson("/api/requirements/{$this->requirement->id}");

    $formattedDate = Carbon::parse($expiredDate)->format('d/m/Y');
    $expectedTooltip = "The linked document expired on {$formattedDate}.";

    $response->assertOk()
        ->assertJsonPath('missing_documents_count', 1)
        ->assertJsonPath('required_documents.0.status', 'expired')
        ->assertJsonPath('required_documents.0.badge', 'Missing Document')
        ->assertJsonPath('required_documents.0.tooltip', $expectedTooltip)
        ->assertJsonPath('required_documents.0.is_satisfied', false);
});

test('pest tests cover: replaced version that fails is still satisfied by previous current version', function () {
    $doc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Permit Multi-version',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    // Current v1.0 is processed and valid
    $v1 = DocumentVersion::create([
        'document_id' => $doc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/v1.bin',
        'original_filename' => 'v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'content1'),
        'status' => 'processed',
        'expiration_date' => Carbon::now('America/Mexico_City')->addMonths(12)->toDateString(),
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    // New v2.0 was uploaded but failed processing, so is_current remains false
    $v2 = DocumentVersion::create([
        'document_id' => $doc->id,
        'version_label' => 'v2.0',
        'storage_path' => 'documents/v2.bin',
        'original_filename' => 'v2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'content2'),
        'status' => 'processing_failed',
        'failure_reason' => 'Damaged file',
        'is_current' => false,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $this->requiredDoc->update(['document_id' => $doc->id]);

    $statusService = app(RequiredDocumentStatusService::class);
    $status = $statusService->evaluateRequiredDocument($this->requiredDoc);

    expect($status['status'])->toBe('provided')
        ->and($status['badge'])->toBe('Provided')
        ->and($status['is_satisfied'])->toBeTrue();
});

test('pest tests cover: unlinked document becomes missing again', function () {
    $doc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Valid Document',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    DocumentVersion::create([
        'document_id' => $doc->id,
        'version_label' => 'v1.0',
        'storage_path' => 'documents/valid.bin',
        'original_filename' => 'valid.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'v1'),
        'status' => 'processed',
        'expiration_date' => Carbon::now('America/Mexico_City')->addYear()->toDateString(),
        'is_current' => true,
        'uploaded_by' => $this->staff->id,
        'uploaded_at' => now(),
    ]);

    $this->requiredDoc->update(['document_id' => $doc->id]);

    $response = $this->actingAs($this->staff)->deleteJson("/api/requirements/{$this->requirement->id}/documents/{$this->requiredDoc->id}");

    $response->assertOk()
        ->assertJsonPath('message', 'Document unlinked successfully.')
        ->assertJsonPath('required_document.status', 'missing')
        ->assertJsonPath('required_document.badge', 'Missing Document')
        ->assertJsonPath('required_document.is_satisfied', false);

    $this->assertDatabaseHas('audit_logs', [
        'action' => 'requirement.document_unlinked',
        'resource_type' => 'requirement',
        'resource_id' => $this->requirement->id,
    ]);
});

test('exact error: linking a document with a different category returns 422', function () {
    $doc = Document::create([
        'organization_id' => $this->org->id,
        'name' => 'Safety Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    $response = $this->actingAs($this->staff)->postJson("/api/requirements/{$this->requirement->id}/documents", [
        'required_document_id' => $this->requiredDoc->id,
        'document_id' => $doc->id,
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => "This document's category (policy) does not match the required category (permit_license).",
        ]);
});

test('exact error: linking a document of another organization returns 403', function () {
    $otherOrg = Organization::create([
        'legal_name' => 'Other Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);

    $doc = Document::create([
        'organization_id' => $otherOrg->id,
        'name' => 'Other Org Permit',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'is_archived' => false,
        'created_by' => $this->staff->id,
    ]);

    $response = $this->actingAs($this->staff)->postJson("/api/requirements/{$this->requirement->id}/documents", [
        'required_document_id' => $this->requiredDoc->id,
        'document_id' => $doc->id,
    ]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to link this document.',
        ]);
});
