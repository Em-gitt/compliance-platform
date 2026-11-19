<?php

namespace App\Http\Controllers;

use App\Models\DocumentChunk;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CitationController extends Controller
{
    /**
     * View a cited document chunk with authorization check (CMP-34 / FR-013).
     */
    public function show(Request $request, int|string $chunkId): JsonResponse
    {
        $chunk = DocumentChunk::with(['document', 'documentVersion'])->find($chunkId);

        if (! $chunk || ! $chunk->document || $chunk->document->is_archived) {
            return response()->json([
                'message' => 'This source is no longer available.',
            ], 404);
        }

        $user = $request->user();

        // 1. Organization check
        if ($user->organization_id !== $chunk->document->organization_id) {
            AuditLogger::log('access.denied', $chunk->document, [
                'reason' => 'Cross-organization citation access denied',
                'chunk_id' => (int) $chunkId,
            ]);

            return response()->json([
                'message' => 'You do not have permission to view this source.',
            ], 403);
        }

        // 2. Role permission check (General Employee cannot access compliance_only documents)
        $userRole = is_string($user->role) ? $user->role : $user->role?->value;
        if (($userRole === 'general_employee' || $userRole === 'employee') && $chunk->document->access_level !== 'all_staff') {
            AuditLogger::log('access.denied', $chunk->document, [
                'reason' => 'General employee attempted access to compliance_only citation source',
                'chunk_id' => (int) $chunkId,
            ]);

            // Exact 403 message with NO text/content in response body (AC #2)
            return response()->json([
                'message' => 'You do not have permission to view this source.',
            ], 403);
        }

        return response()->json([
            'id' => $chunk->id,
            'document_id' => $chunk->document->id,
            'document_name' => $chunk->document->name,
            'category' => $chunk->document->category,
            'access_level' => $chunk->document->access_level,
            'version_label' => $chunk->documentVersion?->version_label ?? 'v1.0',
            'version_id' => $chunk->document_version_id,
            'page_start' => $chunk->page_start,
            'page_end' => $chunk->page_end,
            'section' => $chunk->section,
            'content' => $chunk->content,
            'mime_type' => $chunk->documentVersion?->mime_type ?? 'application/pdf',
        ]);
    }
}
