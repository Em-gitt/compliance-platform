<?php

namespace Database\Seeders;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $organization = Organization::query()->firstOrCreate(
            ['legal_name' => 'Compliance Platform Demo, S.A. de C.V.'],
            // CMP-17 acceptance criteria use this demo organization: Manufacturing, 120 employees.
            ['organization_type' => 'private_company', 'industry' => 'manufacturing', 'state' => '10', 'municipality' => 'Durango', 'employees_count' => 120, 'business_activities' => ['31-33', '43'], 'is_active' => true]
        );

        $password = (string) env('DEMO_USER_PASSWORD', 'password');
        foreach (['administrator', 'compliance_staff', 'general_employee'] as $role) {
            User::query()->updateOrCreate(
                ['email' => "demo.{$role}@compliance-platform.test"],
                ['organization_id' => $organization->id, 'name' => ucwords(str_replace('_', ' ', $role)), 'password' => Hash::make($password), 'role' => $role, 'is_active' => true]
            );
        }

        $complianceStaff = User::query()->where('email', 'demo.compliance_staff@compliance-platform.test')->first();
        if ($complianceStaff) {
            \App\Models\ComplianceRequirement::query()->firstOrCreate(
                ['name' => 'NOM-035-STPS-2018 Factores de riesgo psicosocial', 'organization_id' => $organization->id],
                [
                    'description' => 'Identificación, análisis, prevención y control de factores de riesgo psicosocial.',
                    'regulation' => 'NOM-035-STPS-2018',
                    'deadline' => now()->addDays(20),
                    'responsible_user_id' => $complianceStaff->id,
                    'status' => 'pending',
                    'applies' => true,
                    'notification_periods' => [30, 15, 7, 1],
                    'created_by' => $complianceStaff->id,
                ]
            );
        }
    }
}
