<?php

use App\Jobs\ProcessDocumentVersion;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentVersionService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.ai.url' => 'http://ai-service:8001',
        'services.ai.token' => 'test-secret-token',
    ]);

    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Version Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 15,
        'business_activities' => ['51'],
    ]);

    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance Officer',
        'email' => 'compliance@version-test.local',
        'password' => 'password123',
        'role' => 'compliance_staff',
    ]);

    $this->document = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Environmental Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $this->v1 = DocumentVersion::query()->create([
        'document_id' => $this->document->id,
        'version_label' => '1.0',
        'storage_path' => 'documents/env-v1.pdf',
        'original_filename' => 'env-v1.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'v1 content'),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now()->subDays(5),
        'processed_at' => now()->subDays(5),
    ]);
});

it('keeps version 1.0 current when version 2.0 is uploaded and queued', function (): void {
    Queue::fake();
    Storage::fake();

    $temp = tempnam(sys_get_temp_dir(), 'test_pdf');
    file_put_contents($temp, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n");
    $pdf = new UploadedFile($temp, 'env-v2.pdf', 'application/pdf', null, true);

    $response = $this->actingAs($this->user)
        ->postJson("/api/documents/{$this->document->id}/versions", [
            'file' => $pdf,
            'version' => '2.0',
        ]);

    $response->assertStatus(201);

    // V1 must remain current
    $this->v1->refresh();
    expect($this->v1->is_current)->toBeTrue();

    // V2 must be queued and is_current = false
    $v2 = DocumentVersion::where('document_id', $this->document->id)
        ->where('version_label', '2.0')
        ->first();

    expect($v2)->not->toBeNull()
        ->and($v2->is_current)->toBeFalse()
        ->and($v2->status)->toBe('queued');

    // Audit log for version creation must exist
    $creationAudit = AuditLog::where('action', 'document_version.created')
        ->where('resource_id', $v2->id)
        ->first();
    expect($creationAudit)->not->toBeNull();
});

it('makes version 2.0 current upon successful processing and unsets version 1.0', function (): void {
    Http::fake([
        'http://ai-service:8001/v1/documents/*/set-current' => Http::response(['status' => 'ok'], 200),
    ]);

    $v2 = DocumentVersion::query()->create([
        'document_id' => $this->document->id,
        'version_label' => '2.0',
        'storage_path' => 'documents/env-v2.pdf',
        'original_filename' => 'env-v2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'v2 content'),
        'status' => 'processed',
        'is_current' => false,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now(),
    ]);

    app(DocumentVersionService::class)->makeCurrent($v2);

    $this->v1->refresh();
    $v2->refresh();

    expect($this->v1->is_current)->toBeFalse()
        ->and($v2->is_current)->toBeTrue();

    // Check set_current audit log
    $audit = AuditLog::where('action', 'document_version.set_current')
        ->where('resource_id', $v2->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->metadata['version_label'])->toBe('2.0');
});

it('leaves previous version current and generates exact failure alert when new version fails processing', function (): void {
    // Current is 2.0
    $this->v1->update(['is_current' => false]);
    $v2 = DocumentVersion::query()->create([
        'document_id' => $this->document->id,
        'version_label' => '2.0',
        'storage_path' => 'documents/env-v2.pdf',
        'original_filename' => 'env-v2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'v2 content'),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now()->subDay(),
    ]);

    // Version 3.0 fails
    $v3 = DocumentVersion::query()->create([
        'document_id' => $this->document->id,
        'version_label' => '3.0',
        'storage_path' => 'documents/env-v3.pdf',
        'original_filename' => 'env-v3.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'v3 content'),
        'status' => 'queued',
        'is_current' => false,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now(),
    ]);

    $job = new ProcessDocumentVersion($v3);
    $job->failed(new RuntimeException('OCR unreadable'));

    $v2->refresh();
    $v3->refresh();

    // v2 remains current
    expect($v2->is_current)->toBeTrue();
    // v3 is failed and not current
    expect($v3->is_current)->toBeFalse()
        ->and($v3->status)->toBe('processing_failed');

    // Alert with exact message
    $alert = Alert::where('user_id', $this->user->id)
        ->where('type', 'processing_failed')
        ->latest('id')
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->message)->toBe('Version 3.0 could not be processed. Version 2.0 remains the current version.');
});

it('lists all versions in versions endpoint and provides version metadata', function (): void {
    $this->v1->update(['is_current' => false]);
    $v2 = DocumentVersion::query()->create([
        'document_id' => $this->document->id,
        'version_label' => '2.0',
        'storage_path' => 'documents/env-v2.pdf',
        'original_filename' => 'env-v2.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 2048,
        'sha256' => hash('sha256', 'v2 content'),
        'status' => 'processed',
        'is_current' => true,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now(),
    ]);

    $response = $this->actingAs($this->user)
        ->getJson("/api/documents/{$this->document->id}/versions");

    $response->assertStatus(200)
        ->assertJsonCount(2);

    $data = $response->json();
    $labels = array_column($data, 'version_label');
    expect($labels)->toContain('1.0')
        ->and($labels)->toContain('2.0');
});

it('returns 403 with exact message on PUT, PATCH, or DELETE on versions and documents', function (): void {
    $exactMessage = 'Document versions cannot be modified or deleted.';

    // PUT on version
    $putResponse = $this->actingAs($this->user)
        ->putJson("/api/documents/{$this->document->id}/versions/{$this->v1->id}", ['version' => '1.1']);
    $putResponse->assertStatus(403)
        ->assertJson(['message' => $exactMessage]);

    // PATCH on version
    $patchResponse = $this->actingAs($this->user)
        ->patchJson("/api/documents/{$this->document->id}/versions/{$this->v1->id}", ['version' => '1.1']);
    $patchResponse->assertStatus(403)
        ->assertJson(['message' => $exactMessage]);

    // DELETE on version
    $deleteVersionResponse = $this->actingAs($this->user)
        ->deleteJson("/api/documents/{$this->document->id}/versions/{$this->v1->id}");
    $deleteVersionResponse->assertStatus(403)
        ->assertJson(['message' => $exactMessage]);

    // DELETE on document
    $deleteDocResponse = $this->actingAs($this->user)
        ->deleteJson("/api/documents/{$this->document->id}");
    $deleteDocResponse->assertStatus(403)
        ->assertJson(['message' => $exactMessage]);
});
