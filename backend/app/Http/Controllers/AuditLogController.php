<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $query = AuditLog::query()->with('user:id,name,email');

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->query('from'));
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->query('to'));
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('category')) {
            $query->where('category', $request->query('category'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->query('action'));
        }

        $logs = $query->latest('id')->paginate(50);

        return response()->json($logs);
    }

    public function immutableReject(Request $request, mixed $id): JsonResponse
    {
        AuditLog::query()->create([
            'user_id' => $request->user()?->id,
            'category' => 'security',
            'action' => 'audit.modification_denied',
            'resource_type' => 'audit_log',
            'resource_id' => is_numeric($id) ? (int) $id : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => [
                'attempted_method' => $request->method(),
                'attempted_route' => $request->path(),
            ],
            'created_at' => now(),
        ]);

        return response()->json([
            'message' => 'Audit records cannot be modified or deleted.',
        ], 403);
    }
}
