<?php

namespace App\Http\Controllers;

use App\Events\OrganizationRegistered;
use App\Http\Requests\StoreOrganizationRequest;
use App\Http\Resources\OrganizationResource;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Support\OrganizationMessages;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class OrganizationController extends Controller
{
    /**
     * POST /api/organizations (FR-001).
     */
    public function store(StoreOrganizationRequest $request): JsonResponse
    {
        $organization = DB::transaction(function () use ($request): Organization {
            return Organization::query()->create($request->organizationData());
        });

        OrganizationRegistered::dispatch($organization->refresh());

        return (new OrganizationResource($organization))
            ->additional(['message' => OrganizationMessages::CREATED])
            ->response()
            ->setStatusCode(201);
    }

    /**
     * GET /api/organizations/{organization}.
     */
    public function show(Organization $organization): OrganizationResource
    {
        Gate::authorize('view', $organization);

        return new OrganizationResource($organization);
    }
}
