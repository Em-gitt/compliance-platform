<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreComplianceRequirementRequest;
use App\Http\Requests\UpdateRequirementStatusRequest;
use App\Http\Resources\ComplianceRequirementDetailResource;
use App\Http\Resources\ComplianceRequirementListResource;
use App\Models\ComplianceRequirement;
use App\Support\RequirementMessages;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ComplianceRequirementController extends Controller
{
    /**
     * Default notification periods in days (ADR-020).
     */
    private const DEFAULT_NOTIFICATION_PERIODS = [30, 15, 7, 1];

    /**
     * POST /api/compliance-requirements (FR-006).
     * The requirement, its required documents and the audit record are saved in one transaction.
     */
    public function store(StoreComplianceRequirementRequest $request): JsonResponse
    {
        $user = $request->user();

        $requirement = DB::transaction(function () use ($request, $user): ComplianceRequirement {
            $requirement = ComplianceRequirement::query()->create($request->requirementData() + [
                'organization_id' => $user->organization_id,
                'created_by' => $user->id,
                'applicability_criteria' => ['all' => []],
                'applies' => true,
                'notification_periods' => self::DEFAULT_NOTIFICATION_PERIODS,
            ]);

            $requirement->requiredDocuments()->createMany($request->requiredDocumentsData());

            return $requirement;
        });

        $requirement->load(['responsibleUser', 'requiredDocuments']);

        return (new ComplianceRequirementDetailResource($requirement))
            ->additional(['message' => RequirementMessages::CREATED])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * PATCH /api/compliance-requirements/{complianceRequirement}/status (FR-016).
     * The row is locked so "from" in the audit record is the status that was really replaced.
     */
    public function updateStatus(UpdateRequirementStatusRequest $request, ComplianceRequirement $complianceRequirement): JsonResponse
    {
        $to = $request->validated('status');

        $requirement = DB::transaction(function () use ($complianceRequirement, $to): ComplianceRequirement {
            $requirement = ComplianceRequirement::query()->lockForUpdate()->findOrFail($complianceRequirement->id);
            $from = $requirement->status;

            if ($from === $to) {
                throw ValidationException::withMessages(['status' => RequirementMessages::STATUS_UNCHANGED]);
            }

            $requirement->update(['status' => $to]);

            return $requirement;
        });

        return (new ComplianceRequirementDetailResource($requirement->load(['responsibleUser', 'requiredDocuments'])))
            ->additional(['message' => RequirementMessages::statusUpdated($to)])
            ->response();
    }

    /**
     * GET /api/compliance-requirements: requirements of the user's organization that apply to it.
     * Requirements with applies = false stay stored but are hidden unless include_not_applicable=1 (ADR-021).
     */
    public function index(Request $request): AnonymousResourceCollection|JsonResponse
    {
        // Date filter validation
        $error1 = null;
        $deadlineFrom = $this->parseDateFilter($request->query('deadline_from'), $error1);
        if ($error1) {
            return response()->json(['message' => $error1], 422);
        }

        $error2 = null;
        $deadlineTo = $this->parseDateFilter($request->query('deadline_to'), $error2);
        if ($error2) {
            return response()->json(['message' => $error2], 422);
        }

        if ($deadlineFrom && $deadlineTo && $deadlineFrom->gt($deadlineTo)) {
            return response()->json([
                'message' => "The 'from' date must be before or equal to the 'to' date.",
            ], 422);
        }

        $query = ComplianceRequirement::query()
            ->where('organization_id', $request->user()->organization_id)
            ->unless($request->boolean('include_not_applicable'), fn ($query) => $query->where('applies', true));

        if ($request->filled('name')) {
            $name = (string) $request->query('name');
            $isPgsql = DB::connection()->getDriverName() === 'pgsql';
            if ($isPgsql) {
                $query->whereRaw('unaccent(name) ILIKE unaccent(?)', ["%{$name}%"]);
            } else {
                $query->whereRaw('lower(unaccent(name)) LIKE lower(unaccent(?))', ["%{$name}%"]);
            }
        }

        if ($request->filled('regulation')) {
            $reg = (string) $request->query('regulation');
            $query->where('regulation', 'like', "%{$reg}%");
        }

        if ($request->filled('status')) {
            $statuses = (array) $request->query('status');
            $query->whereIn('status', $statuses);
        }

        if ($deadlineFrom) {
            $query->where('deadline', '>=', $deadlineFrom->toDateString());
        }
        if ($deadlineTo) {
            $query->where('deadline', '<=', $deadlineTo->toDateString());
        }

        $requirements = $query->orderBy('deadline')->orderBy('id')->get();

        return ComplianceRequirementListResource::collection($requirements);
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
        } catch (\Throwable $e) {
            $error = 'Enter a valid date.';

            return null;
        }
    }

    /**
     * GET /api/compliance-requirements/{complianceRequirement}.
     */
    public function show(ComplianceRequirement $complianceRequirement): ComplianceRequirementDetailResource
    {
        Gate::authorize('view', $complianceRequirement);

        return new ComplianceRequirementDetailResource($complianceRequirement->load(['responsibleUser', 'requiredDocuments']));
    }
}
