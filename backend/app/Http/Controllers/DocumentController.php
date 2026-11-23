<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\StoreDocumentVersionRequest;
use App\Jobs\ProcessDocumentVersion;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentEncryptionService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    public function __construct(protected DocumentEncryptionService $encryptionService) {}

    /**
     * List documents visible to the authenticated user with search and filters (CMP-27).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);
        $user = $request->user();

        // 1. Date filter validation
        $uploadedFrom = $this->parseDateFilter($request->query('uploaded_from'), $error1);
        if ($error1) {
            return response()->json(['message' => $error1], 422);
        }

        $uploadedTo = $this->parseDateFilter($request->query('uploaded_to'), $error2);
        if ($error2) {
            return response()->json(['message' => $error2], 422);
        }

        if ($uploadedFrom && $uploadedTo && $uploadedFrom->gt($uploadedTo)) {
            return response()->json([
                'message' => "The 'from' date must be before or equal to the 'to' date.",
            ], 422);
        }

        $expiresFrom = $this->parseDateFilter($request->query('expires_from'), $error3);
        if ($error3) {
            return response()->json(['message' => $error3], 422);
        }

        $expiresTo = $this->parseDateFilter($request->query('expires_to'), $error4);
        if ($error4) {
            return response()->json(['message' => $error4], 422);
        }

        if ($expiresFrom && $expiresTo && $expiresFrom->gt($expiresTo)) {
            return response()->json([
                'message' => "The 'from' date must be before or equal to the 'to' date.",
            ], 422);
        }

        // 2. Query builder
        $query = Document::query()->visibleTo($user)->with(['currentVersion', 'creator']);

        // Name filter (accent- and case-insensitive)
        $name = $request->query('name') ?? $request->query('search');
        if (! empty($name)) {
            $isPgsql = DB::connection()->getDriverName() === 'pgsql';
            if ($isPgsql) {
                $query->whereRaw('unaccent(name) ILIKE unaccent(?)', ["%{$name}%"]);
            } else {
                $query->whereRaw('lower(unaccent(name)) LIKE lower(unaccent(?))', ["%{$name}%"]);
            }
        }

        // Category filter (array or single)
        if ($request->filled('category')) {
            $cats = (array) $request->query('category');
            $query->whereIn('category', $cats);
        }

        // Processing status filter
        if ($request->filled('status')) {
            $status = (string) $request->query('status');
            $query->whereHas('currentVersion', fn ($q) => $q->where('status', $status));
        }

        // Uploaded date filter
        if ($uploadedFrom || $uploadedTo) {
            $query->whereHas('currentVersion', function ($q) use ($uploadedFrom, $uploadedTo) {
                if ($uploadedFrom) {
                    $q->where('uploaded_at', '>=', $uploadedFrom->startOfDay());
                }
                if ($uploadedTo) {
                    $q->where('uploaded_at', '<=', $uploadedTo->endOfDay());
                }
            });
        }

        // Expiration date filter
        if ($expiresFrom || $expiresTo) {
            $query->whereHas('currentVersion', function ($q) use ($expiresFrom, $expiresTo) {
                if ($expiresFrom) {
                    $q->where('expiration_date', '>=', $expiresFrom->toDateString());
                }
                if ($expiresTo) {
                    $q->where('expiration_date', '<=', $expiresTo->toDateString());
                }
            });
        }

        $query->orderBy('name');

        if ($request->has('page')) {
            $paginator = $query->paginate(20);

            return response()->json([
                'data' => $paginator->items(),
                'total' => $paginator->total(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
            ]);
        }

        $documents = $query->get();

        return response()->json($documents)->header('X-Total-Count', (string) $documents->count());
    }

    private function parseDateFilter(?string $date, ?string &$error): ?Carbon
    {
        $error = null;
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($date);
            if ($parsed->year < 1900 || $parsed->year > 2200) {
                $error = 'Enter a valid date.';

                return null;
            }

            return $parsed;
        } catch (Throwable $e) {
            $error = 'Enter a valid date.';

            return null;
        }
    }

    /**
     * Store a newly uploaded document and its first version.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', Document::class);
        $user = $request->user();
        $file = $request->file('file');
        $validated = $request->validated();

        $sha256 = hash_file('sha256', $file->getRealPath());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $file->getRealPath()) : $file->getMimeType();
        if ($finfo) {
            finfo_close($finfo);
        }

        $fileStored = false;
        $storedStoragePath = null;

        try {
            $result = DB::transaction(function () use ($request, $user, $file, $validated, $sha256, $detectedMime, &$fileStored, &$storedStoragePath) {
                // 1. Create the document record scoped to user's organization
                $document = Document::create([
                    'organization_id' => $user->organization_id,
                    'name' => $validated['name'],
                    'category' => $validated['category'],
                    'access_level' => $validated['access_level'],
                    'is_archived' => false,
                    'created_by' => $user->id,
                ]);

                // 2. Storage path: documents/<organization_id>/<document_id>/<uuid>.bin
                $uuid = (string) Str::uuid();
                $storagePath = "documents/{$document->organization_id}/{$document->id}/{$uuid}.bin";
                $storedStoragePath = $storagePath;

                // 3. Encrypt file using streaming libsodium encryption
                $this->encryptionService->encryptFile($file->getRealPath(), $storagePath);
                $fileStored = true;

                // 4. Create document version with the specified version label and status 'queued'
                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version_label' => $validated['version'],
                    'storage_path' => $storagePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $detectedMime,
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha256,
                    'expiration_date' => $validated['expiration_date'] ?? null,
                    'status' => 'queued',
                    'is_current' => true,
                    'uploaded_by' => $user->id,
                    'uploaded_at' => now(),
                    'queued_at' => now(),
                ]);

                AuditLog::query()->create([
                    'user_id' => $user->id,
                    'category' => 'functional',
                    'action' => 'document_version.created',
                    'resource_type' => 'document_version',
                    'resource_id' => $version->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'document_id' => $document->id,
                        'version_label' => $version->version_label,
                    ],
                    'created_at' => now(),
                ]);

                return [$document, $version];
            });

            [$document, $version] = $result;

            $version->forceFill(['queued_at' => now()])->save();

            try {
                ProcessDocumentVersion::dispatch($version)
                    ->onQueue('documents')
                    ->afterCommit();
            } catch (Throwable $e) {
                Log::error('ProcessDocumentVersion dispatch failed: ' . $e->getMessage(), [
                    'exception' => $e,
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                ]);

                $version->forceFill([
                    'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
                    'failure_reason' => 'queue_unavailable',
                ])->save();

                Alert::create([
                    'user_id' => $user->id,
                    'document_version_id' => $version->id,
                    'compliance_requirement_id' => null,
                    'type' => 'queue_unavailable',
                    'period_days' => 0,
                    'message' => "We could not start processing \"{$document->name}\". Please upload the file again or contact your administrator.",
                    'due_date' => now()->toDateString(),
                ]);
            }

            return response()->json([
                'message' => 'Document uploaded successfully. It is now being processed.',
                'id' => $document->id,
                'document' => [
                    'id' => $document->id,
                    'organization_id' => $document->organization_id,
                    'name' => $document->name,
                    'category' => $document->category,
                    'access_level' => $document->access_level,
                    'status' => $version->refresh()->status,
                    'version_label' => $version->version_label,
                    'created_at' => $document->created_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            if ($fileStored && $storedStoragePath) {
                $this->encryptionService->delete($storedStoragePath);
            }

            throw $e;
        }
    }

    /**
     * Store a new version for an existing document.
     */
    public function storeVersion(StoreDocumentVersionRequest $request, Document $document): JsonResponse
    {
        $this->authorize('createVersion', $document);
        $user = $request->user();
        $file = $request->file('file');
        $validated = $request->validated();

        $sha256 = hash_file('sha256', $file->getRealPath());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $file->getRealPath()) : $file->getMimeType();
        if ($finfo) {
            finfo_close($finfo);
        }

        $fileStored = false;
        $storedStoragePath = null;

        try {
            $version = DB::transaction(function () use ($request, $user, $file, $validated, $document, $sha256, $detectedMime, &$fileStored, &$storedStoragePath) {
                $uuid = (string) Str::uuid();
                $storagePath = "documents/{$document->organization_id}/{$document->id}/{$uuid}.bin";
                $storedStoragePath = $storagePath;

                // 1. Encrypt file using streaming libsodium encryption
                $this->encryptionService->encryptFile($file->getRealPath(), $storagePath);
                $fileStored = true;

                // 2. Create new document version with is_current = false per CMP-23
                $newVersion = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version_label' => $validated['version'],
                    'storage_path' => $storagePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $detectedMime,
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha256,
                    'expiration_date' => $validated['expiration_date'] ?? null,
                    'status' => 'queued',
                    'is_current' => false,
                    'uploaded_by' => $user->id,
                    'uploaded_at' => now(),
                    'queued_at' => now(),
                ]);

                AuditLog::query()->create([
                    'user_id' => $user->id,
                    'category' => 'functional',
                    'action' => 'document_version.created',
                    'resource_type' => 'document_version',
                    'resource_id' => $newVersion->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'document_id' => $document->id,
                        'version_label' => $newVersion->version_label,
                    ],
                    'created_at' => now(),
                ]);

                return $newVersion;
            });

            $version->forceFill(['queued_at' => now()])->save();

            try {
                ProcessDocumentVersion::dispatch($version)
                    ->onQueue('documents')
                    ->afterCommit();
            } catch (Throwable $e) {
                Log::error('ProcessDocumentVersion dispatch failed: ' . $e->getMessage(), [
                    'exception' => $e,
                    'document_id' => $document->id,
                    'version_id' => $version->id,
                ]);

                $version->forceFill([
                    'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
                    'failure_reason' => 'queue_unavailable',
                ])->save();

                Alert::create([
                    'user_id' => $user->id,
                    'document_version_id' => $version->id,
                    'compliance_requirement_id' => null,
                    'type' => 'queue_unavailable',
                    'period_days' => 0,
                    'message' => "We could not start processing \"{$document->name}\". Please upload the file again or contact your administrator.",
                    'due_date' => now()->toDateString(),
                ]);
            }

            return response()->json([
                'message' => 'New document version uploaded successfully.',
                'version' => [
                    'id' => $version->id,
                    'document_id' => $document->id,
                    'version_label' => $version->version_label,
                    'status' => $version->refresh()->status,
                    'is_current' => $version->is_current,
                    'uploaded_at' => $version->uploaded_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            if ($fileStored && $storedStoragePath) {
                $this->encryptionService->delete($storedStoragePath);
            }

            throw $e;
        }
    }

    /**
     * Download an authorized document version stream after authorization and verification.
     */
    public function download(Request $request, mixed $id, mixed $versionId): JsonResponse|StreamedResponse
    {
        $document = Document::find($id);

        if (! $document) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        $user = $request->user();

        if ($user->cannot('download', $document)) {
            AuditLog::query()->create([
                'user_id' => $user->id,
                'category' => 'security',
                'action' => 'document.download_denied',
                'resource_type' => 'document',
                'resource_id' => $document->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'document_id' => $document->id,
                    'version_id' => is_numeric($versionId) ? (int) $versionId : $versionId,
                    'access_level' => $document->access_level,
                    'user_role' => $user->role->value,
                ],
                'created_at' => now(),
            ]);
        }

        $this->authorize('download', $document);

        $version = $document->versions()->find($versionId);

        if (! $version) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        try {
            $stream = $this->encryptionService->decryptAndVerify($version);
        } catch (Throwable) {
            return response()->json([
                'message' => 'The file could not be retrieved. Please contact your administrator.',
            ], 500);
        }

        return response()->stream(function () use ($stream) {
            while (! feof($stream)) {
                $buffer = fread($stream, 65536);
                if ($buffer !== false && $buffer !== '') {
                    echo $buffer;
                    flush();
                }
            }
            fclose($stream);
        }, 200, [
            'Content-Type' => $version->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$version->original_filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * List all versions for a document.
     */
    public function versions(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $versions = $document->versions()
            ->with('uploader:id,name,email')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($v) use ($document) {
                return [
                    'id' => $v->id,
                    'version' => $v->version_label,
                    'version_label' => $v->version_label,
                    'uploaded_by' => $v->uploader?->name ?? 'Unknown',
                    'upload_date' => $v->uploaded_at ? $v->uploaded_at->format('d/m/Y H:i') : null,
                    'uploaded_at' => $v->uploaded_at?->toISOString(),
                    'status' => $v->status,
                    'expiration_date' => $v->expiration_date ? $v->expiration_date->format('d/m/Y') : 'No expiration',
                    'is_current' => (bool) $v->is_current,
                    'ocr_used' => (bool) $v->ocr_used,
                    'ocr_confidence' => $v->ocr_confidence ? (float) $v->ocr_confidence : null,
                    'download_url' => "/api/documents/{$document->id}/versions/{$v->id}/download",
                    'failure_reason' => $v->failure_reason,
                ];
            });

        return response()->json($versions);
    }

    /**
     * Display the specified document metadata.
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $currentVersion = $document->versions()
            ->where('is_current', true)
            ->with('uploader')
            ->first() ?? $document->versions()->latest('uploaded_at')->with('uploader')->first();

        $categoryName = StoreDocumentRequest::CATEGORY_NAMES[$document->category] ?? ucfirst(str_replace('_', ' ', $document->category));
        $visibilityName = $document->access_level === 'all_staff' ? 'All staff' : 'Compliance only';

        $expirationFormatted = $currentVersion?->expiration_date
            ? $currentVersion->expiration_date->format('d/m/Y')
            : 'No expiration';

        $uploadedAtFormatted = $currentVersion?->uploaded_at
            ? $currentVersion->uploaded_at->format('d/m/Y H:i')
            : null;

        $uploaderName = $currentVersion?->uploader?->name ?? 'Unknown';

        $versions = $document->versions()
            ->with('uploader:id,name,email')
            ->orderBy('id', 'desc')
            ->get()
            ->map(function ($v) use ($document) {
                return [
                    'id' => $v->id,
                    'version' => $v->version_label,
                    'version_label' => $v->version_label,
                    'uploaded_by' => $v->uploader?->name ?? 'Unknown',
                    'upload_date' => $v->uploaded_at ? $v->uploaded_at->format('d/m/Y H:i') : null,
                    'uploaded_at' => $v->uploaded_at?->toISOString(),
                    'status' => $v->status,
                    'expiration_date' => $v->expiration_date ? $v->expiration_date->format('d/m/Y') : 'No expiration',
                    'is_current' => (bool) $v->is_current,
                    'ocr_used' => (bool) $v->ocr_used,
                    'ocr_confidence' => $v->ocr_confidence ? (float) $v->ocr_confidence : null,
                    'download_url' => "/api/documents/{$document->id}/versions/{$v->id}/download",
                    'failure_reason' => $v->failure_reason,
                ];
            });

        return response()->json([
            'id' => $document->id,
            'name' => $document->name,
            'category' => $document->category,
            'category_name' => $categoryName,
            'access_level' => $document->access_level,
            'visibility' => $visibilityName,
            'is_archived' => $document->is_archived,
            'current_version' => $currentVersion?->version_label,
            'expiration_date' => $currentVersion?->expiration_date?->format('Y-m-d'),
            'expiration_date_formatted' => $expirationFormatted,
            'uploaded_at' => $currentVersion?->uploaded_at?->toISOString(),
            'upload_date' => $uploadedAtFormatted,
            'uploaded_by' => $uploaderName,
            'processing_status' => $currentVersion?->status ?? 'queued',
            'status' => $currentVersion?->status ?? 'queued',
            'ocr_used' => (bool) ($currentVersion?->ocr_used ?? false),
            'ocr_confidence' => $currentVersion?->ocr_confidence ? (float) $currentVersion->ocr_confidence : null,
            'versions' => $versions,
            'document' => [
                'id' => $document->id,
                'name' => $document->name,
                'category' => $document->category,
                'category_name' => $categoryName,
                'access_level' => $document->access_level,
                'visibility' => $visibilityName,
                'current_version' => $currentVersion?->version_label,
                'expiration_date' => $expirationFormatted,
                'uploaded_by' => $uploaderName,
                'upload_date' => $uploadedAtFormatted,
                'processing_status' => $currentVersion?->status ?? 'queued',
                'ocr_used' => (bool) ($currentVersion?->ocr_used ?? false),
                'ocr_confidence' => $currentVersion?->ocr_confidence ? (float) $currentVersion->ocr_confidence : null,
                'versions' => $versions,
            ],
        ]);
    }
}
