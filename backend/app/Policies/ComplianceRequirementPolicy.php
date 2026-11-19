<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Support\RequirementMessages;
use Illuminate\Auth\Access\Response;

class ComplianceRequirementPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->staffCanManage($user);
    }

    public function view(User $user, ComplianceRequirement $requirement): Response
    {
        if ($user->organization_id !== $requirement->organization_id) {
            return Response::deny('You do not have permission to view this requirement.');
        }

        return Response::allow();
    }

    public function create(User $user): Response
    {
        $role = $user->role instanceof Role ? $user->role->value : (string) $user->role;

        return in_array($role, ['administrator', 'compliance_staff'], true)
            ? Response::allow()
            : Response::deny(RequirementMessages::FORBIDDEN);
    }

    public function update(User $user, ComplianceRequirement $requirement): Response
    {
        if ($user->organization_id !== $requirement->organization_id) {
            return Response::deny('You do not have permission to edit this requirement.');
        }

        return $this->staffCanManage($user);
    }

    public function updateStatus(User $user, ComplianceRequirement $requirement): Response
    {
        if ($user->organization_id !== $requirement->organization_id) {
            return Response::deny('You do not have permission to change this compliance status.');
        }

        $role = $user->role instanceof Role ? $user->role->value : (string) $user->role;

        return $role === 'compliance_staff'
            ? Response::allow()
            : Response::deny('You do not have permission to change this compliance status.');
    }

    private function staffCanManage(User $user): Response
    {
        $role = $user->role instanceof Role ? $user->role->value : (string) $user->role;

        return in_array($role, ['administrator', 'compliance_staff'], true)
            ? Response::allow()
            : Response::deny('You do not have permission to manage requirements.');
    }
}
