<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentVersionProcessor;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.ai.url' => 'http://ai-service:8001',
        'services.ai.token' => 'test-secret-token',
    ]);

    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'OCR Test Organization',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);

    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance Staff',
        'email' => 'ocr@test.local',
        'password' => 'password123',
        'role' => 'compliance_staff',
    ]);

    $this->document = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Scanned Land Use Permit',
        'category' => 'permit_license',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $this->version = DocumentVersion::query()->create([
        'document_id' => $this->document->id,
        'version_label' => '1.0',
        'storage_path' => 'documents/scan.pdf',
        'original_filename' => 'scan.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => hash('sha256', 'mock scanned content'),
        'status' => 'queued',
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now(),
    ]);
});

it('processes scanned PDF with OCR, sets ocr_used=true and stores confidence', function (): void {
    Http::fake([
        'http://ai-service:8001/v1/documents/ingest' => Http::response([
            'status' => 'processed',
            'chunks' => 3,
            'pages' => 1,
            'ocr_used' => true,
            'ocr_confidence' => 95.4,
        ], 200),
        'http://ai-service:8001/v1/documents/*/set-current' => Http::response(['status' => 'ok'], 200),
    ]);

    $processedVersion = app(DocumentVersionProcessor::class)->process($this->version, '%PDF-1.4 mock scan');
    $processedVersion->refresh();

    expect($processedVersion->status)->toBe('processed')
        ->and($processedVersion->ocr_used)->toBeTrue()
        ->and($processedVersion->ocr_confidence)->toBe(95.4)
        ->and($processedVersion->chunk_count)->toBe(3);

    // Verify detail endpoint returns ocr_used and ocr_confidence
    $response = $this->actingAs($this->user)->getJson("/api/documents/{$this->document->id}");
    $response->assertStatus(200)
        ->assertJsonPath('ocr_used', true)
        ->assertJsonPath('ocr_confidence', 95.4)
        ->assertJsonPath('versions.0.ocr_used', true);
});

it('marks version as Processing Failed with exact message when OCR fails or produces no text', function (): void {
    $exactMessage = 'The scanned document could not be read (OCR failed). Upload a clearer scan or a text-based PDF.';

    Http::fake([
        'http://ai-service:8001/v1/documents/ingest' => Http::response([
            'error_code' => 'OCR_FAILED',
            'message' => $exactMessage,
        ], 422),
    ]);

    $failedVersion = app(DocumentVersionProcessor::class)->process($this->version, '%PDF-1.4 blank scan');
    $failedVersion->refresh();

    expect($failedVersion->status)->toBe('processing_failed')
        ->and($failedVersion->statusLabel())->toBe('Processing Failed')
        ->and($failedVersion->failure_reason)->toBe($exactMessage)
        ->and($failedVersion->chunk_count)->toBe(0);
});

it('marks version as Processing Failed when scanned PDF exceeds 100 pages', function (): void {
    $exactMessage = 'Scanned documents can have up to 100 pages.';

    Http::fake([
        'http://ai-service:8001/v1/documents/ingest' => Http::response([
            'error_code' => 'VALIDATION_ERROR',
            'message' => $exactMessage,
        ], 422),
    ]);

    $failedVersion = app(DocumentVersionProcessor::class)->process($this->version, '%PDF-1.4 oversized scan');
    $failedVersion->refresh();

    expect($failedVersion->status)->toBe('processing_failed')
        ->and($failedVersion->failure_reason)->toBe($exactMessage);
});
