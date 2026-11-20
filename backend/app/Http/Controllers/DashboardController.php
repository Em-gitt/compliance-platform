<?php

namespace App\Http\Controllers;

use App\Models\ComplianceRequirement;
use App\Services\RequiredDocumentStatusService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected RequiredDocumentStatusService $statusService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->can('view-dashboard')) {
            return response()->json([
                'message' => 'You do not have permission to view the compliance dashboard.',
            ], 403);
        }

        $now = Carbon::now('America/Mexico_City')->startOfDay();

        $requirements = ComplianceRequirement::where('organization_id', $user->organization_id)
            ->where('applies', true)
            ->with(['requiredDocuments.document.currentVersion', 'responsibleUser'])
            ->orderBy('deadline', 'asc')
            ->get();

        $compliantCount = 0;
        $pendingCount = 0;
        $atRiskCount = 0;
        $overdueCount = 0;
        $missingDocumentsCount = 0;

        $requirementItems = $requirements->map(function ($req) use ($now, &$compliantCount, &$pendingCount, &$atRiskCount, &$overdueCount, &$missingDocumentsCount) {
            $status = strtolower($req->status);
            match ($status) {
                'compliant' => $compliantCount++,
                'pending' => $pendingCount++,
                'at_risk' => $atRiskCount++,
                'overdue' => $overdueCount++,
                default => null,
            };

            $hasMissing = $this->statusService->hasMissingDocument($req);
            if ($hasMissing) {
                $missingDocumentsCount++;
            }

            $totalDocs = $req->requiredDocuments->count();
            $providedDocs = $req->requiredDocuments->filter(fn ($rd) => $this->statusService->isSatisfied($rd))->count();

            $deadlineStr = is_a($req->deadline, CarbonInterface::class)
                ? $req->deadline->toDateString()
                : (string) $req->deadline;
            $deadline = Carbon::parse($deadlineStr, 'America/Mexico_City')->startOfDay();
            $isPast = $deadline->lt($now);
            $diffDays = $now->diffInDays($deadline, false);
            $isWithin7Days = ! $isPast && $diffDays <= 7;

            return [
                'id' => $req->id,
                'name' => $req->name,
                'regulation' => $req->regulation,
                'deadline' => $deadlineStr,
                'deadline_formatted' => $deadline->format('d/m/Y'),
                'is_past_deadline' => $isPast,
                'is_deadline_within_7_days' => $isWithin7Days,
                'documents_provided' => $providedDocs,
                'documents_total' => $totalDocs,
                'documents_label' => "{$providedDocs} of {$totalDocs} provided",
                'status' => $req->status,
                'status_label' => match ($status) {
                    'compliant' => 'Compliant',
                    'pending' => 'Pending',
                    'at_risk' => 'At Risk',
                    'overdue' => 'Overdue',
                    default => ucfirst($status),
                },
                'responsible' => $req->responsibleUser?->name ?? 'Unassigned',
                'has_missing_documents' => $hasMissing,
            ];
        });

        $summary = [
            'applicable_requirements' => $requirements->count(),
            'compliant' => $compliantCount,
            'pending' => $pendingCount,
            'at_risk' => $atRiskCount,
            'overdue' => $overdueCount,
            'missing_documents' => $missingDocumentsCount,
        ];

        return response()->json([
            'summary' => $summary,
            'requirements' => $requirementItems,
        ]);
    }
}
