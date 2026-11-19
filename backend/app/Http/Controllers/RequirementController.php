<?php

namespace App\Http\Controllers;

use App\Exceptions\AuditLogException;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\RequiredDocument;
use App\Services\RequiredDocumentStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RequirementController extends Controller
{
    public function __construct(
        protected RequiredDocumentStatusService $statusService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ComplianceRequirement::class);
        $user = $request->user();
        $requirements = ComplianceRequirement::where('organization_id', $user->organization_id)
            ->with(['requiredDocuments.document.currentVersion', 'responsibleUser'])
            ->get();

        $data = $requirements->map(function ($req) {
            $missingCount = $this->statusService->missingDocumentsCount($req);
            $totalCount = $req->requiredDocuments->count();

            return array_merge($req->toArray(), [
                'missing_documents_count' => $missingCount,
                'all_documents_provided' => $totalCount > 0 && $missingCount === 0,
                'missing_documents_label' => $missingCount === 0
                    ? 'All documents provided'
                    : "{$missingCount} missing documents",
            ]);
        });

        return response()->json($data);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ComplianceRequirement::class);
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'required|string',
            'regulation' => 'required|string|max:150',
            'deadline' => 'required|date',
            'responsible_user_id' => 'required|exists:users,id',
            'status' => 'required|string|in:compliant,pending,at_risk,overdue',
            'notification_periods' => 'sometimes|array',
            'applicability_criteria' => 'sometimes|nullable|array',
            'applies' => 'sometimes|boolean',
        ]);

        try {
            $requirement = DB::transaction(function () use ($user, $validated) {
                return ComplianceRequirement::create([
                    'organization_id' => $user->organization_id,
                    'name' => $validated['name'],
                    'description' => $validated['description'],
                    'regulation' => $validated['regulation'],
                    'deadline' => $validated['deadline'],
                    'responsible_user_id' => $validated['responsible_user_id'],
                    'status' => $validated['status'],
                    'notification_periods' => $validated['notification_periods'] ?? [30, 15, 7, 1],
                    'applicability_criteria' => $validated['applicability_criteria'] ?? null,
                    'applies' => $validated['applies'] ?? true,
                    'created_by' => $user->id,
                ]);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        return response()->json([
            'message' => 'Requirement created successfully.',
            'requirement' => $requirement,
        ], 201);
    }

    public function show(Request $request, ComplianceRequirement $requirement): JsonResponse
    {
        $this->authorize('view', $requirement);

        $requirement->load(['requiredDocuments.document.currentVersion', 'responsibleUser']);

        $evaluatedDocs = $requirement->requiredDocuments->map(function ($rd) {
            $eval = $this->statusService->evaluateRequiredDocument($rd);

            return array_merge($rd->toArray(), [
                'status' => $eval['status'],
                'badge' => $eval['badge'],
                'tooltip' => $eval['tooltip'],
                'is_satisfied' => $eval['is_satisfied'],
            ]);
        });

        $missingCount = $this->statusService->missingDocumentsCount($requirement);

        $res = array_merge($requirement->toArray(), [
            'required_documents' => $evaluatedDocs,
            'missing_documents_count' => $missingCount,
            'all_documents_provided' => $missingCount === 0,
            'missing_documents_label' => $missingCount === 0 ? 'All documents provided' : "{$missingCount} missing documents",
        ]);

        return response()->json($res);
    }

    public function update(Request $request, ComplianceRequirement $requirement): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:compliant,pending,at_risk,overdue',
            'name' => 'sometimes|string|max:150',
            'description' => 'sometimes|string',
            'regulation' => 'sometimes|string|max:150',
            'deadline' => 'sometimes|date',
            'responsible_user_id' => 'sometimes|exists:users,id',
        ]);

        if (array_key_exists('status', $validated) && $validated['status'] !== $requirement->status) {
            $this->authorize('updateStatus', $requirement);
        } else {
            $this->authorize('update', $requirement);
        }

        try {
            DB::transaction(function () use ($requirement, $validated) {
                $requirement->update($validated);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        return response()->json([
            'message' => 'Requirement updated successfully.',
            'requirement' => $requirement->fresh(),
        ]);
    }

    public function linkDocument(Request $request, ComplianceRequirement $requirement): JsonResponse
    {
        $this->authorize('update', $requirement);
        $user = $request->user();

        $validated = $request->validate([
            'required_document_id' => 'required|exists:required_documents,id',
            'document_id' => 'required|integer',
        ]);

        $requiredDoc = $requirement->requiredDocuments()->findOrFail($validated['required_document_id']);

        $document = Document::with('currentVersion')->find($validated['document_id']);
        if (! $document || $document->organization_id !== $user->organization_id || $document->is_archived) {
            return response()->json([
                'message' => 'You do not have permission to link this document.',
            ], 403);
        }

        $userRole = is_string($user->role) ? $user->role : $user->role?->value;
        if (($userRole === 'general_employee' || $userRole === 'employee') && $document->access_level !== 'all_staff') {
            return response()->json([
                'message' => 'You do not have permission to link this document.',
            ], 403);
        }

        if ($document->category !== $requiredDoc->category) {
            return response()->json([
                'message' => "This document's category ({$document->category}) does not match the required category ({$requiredDoc->category}).",
            ], 422);
        }

        try {
            DB::transaction(function () use ($requiredDoc, $document) {
                $requiredDoc->update(['document_id' => $document->id]);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        $requiredDoc->load(['document.currentVersion']);
        $eval = $this->statusService->evaluateRequiredDocument($requiredDoc);

        return response()->json([
            'message' => 'Document linked successfully.',
            'required_document' => array_merge($requiredDoc->toArray(), [
                'status' => $eval['status'],
                'badge' => $eval['badge'],
                'tooltip' => $eval['tooltip'],
                'is_satisfied' => $eval['is_satisfied'],
            ]),
        ]);
    }

    public function unlinkDocument(Request $request, ComplianceRequirement $requirement, RequiredDocument $requiredDocument): JsonResponse
    {
        $this->authorize('update', $requirement);

        try {
            DB::transaction(function () use ($requiredDocument) {
                $requiredDocument->update(['document_id' => null]);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        $eval = $this->statusService->evaluateRequiredDocument($requiredDocument);

        return response()->json([
            'message' => 'Document unlinked successfully.',
            'required_document' => array_merge($requiredDocument->toArray(), [
                'status' => $eval['status'],
                'badge' => $eval['badge'],
                'tooltip' => $eval['tooltip'],
                'is_satisfied' => $eval['is_satisfied'],
            ]),
        ]);
    }
}
