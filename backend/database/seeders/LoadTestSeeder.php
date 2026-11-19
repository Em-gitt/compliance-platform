<?php

namespace Database\Seeders;

use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class LoadTestSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Organization
        $organization = Organization::query()->firstOrCreate(
            ['legal_name' => 'Load Test Industrial, S.A. de C.V.'],
            [
                'organization_type' => 'private_company',
                'industry' => 'manufacturing',
                'state' => '10',
                'municipality' => 'Durango',
                'employees_count' => 500,
                'business_activities' => ['31-33'],
                'is_active' => true,
            ]
        );

        // 2. 100 Users (5 Admin, 25 Compliance Staff, 70 General Employee)
        $hashedPassword = Hash::make('Password123!');
        $users = [];
        $userIds = [];

        // 5 Admins
        for ($i = 1; $i <= 5; $i++) {
            $user = User::query()->firstOrCreate(
                ['email' => "load.admin.{$i}@compliance-platform.test"],
                [
                    'organization_id' => $organization->id,
                    'name' => "Load Admin {$i}",
                    'password' => $hashedPassword,
                    'role' => 'administrator',
                    'is_active' => true,
                ]
            );
            $users[] = $user;
            $userIds[] = $user->id;
        }

        // 25 Compliance Staff
        $staffIds = [];
        for ($i = 1; $i <= 25; $i++) {
            $user = User::query()->firstOrCreate(
                ['email' => "load.staff.{$i}@compliance-platform.test"],
                [
                    'organization_id' => $organization->id,
                    'name' => "Load Staff {$i}",
                    'password' => $hashedPassword,
                    'role' => 'compliance_staff',
                    'is_active' => true,
                ]
            );
            $users[] = $user;
            $staffIds[] = $user->id;
            $userIds[] = $user->id;
        }

        // 70 General Employees
        for ($i = 1; $i <= 70; $i++) {
            $user = User::query()->firstOrCreate(
                ['email' => "load.employee.{$i}@compliance-platform.test"],
                [
                    'organization_id' => $organization->id,
                    'name' => "Load Employee {$i}",
                    'password' => $hashedPassword,
                    'role' => 'general_employee',
                    'is_active' => true,
                ]
            );
            $users[] = $user;
            $userIds[] = $user->id;
        }

        $defaultStaffId = $staffIds[0];

        // 3. 100 Requirements
        $categories = ['safety', 'environmental', 'labor', 'tax', 'governance'];
        $statuses = ['compliant', 'pending', 'at_risk', 'overdue'];

        $existingReqsCount = ComplianceRequirement::query()->where('organization_id', $organization->id)->count();
        if ($existingReqsCount < 100) {
            $reqsToCreate = 100 - $existingReqsCount;
            $reqData = [];
            for ($i = 1; $i <= $reqsToCreate; $i++) {
                $idx = $existingReqsCount + $i;
                $cat = $categories[($idx - 1) % count($categories)];
                $status = $statuses[($idx - 1) % count($statuses)];
                $staffId = $staffIds[($idx - 1) % count($staffIds)];

                $reqData[] = [
                    'organization_id' => $organization->id,
                    'name' => "Requisito de Cumplimiento #{$idx} - {$cat}",
                    'description' => "Descripcion detallada del requisito normativo #{$idx} para auditoria.",
                    'regulation' => "NOM-00" . (($idx % 35) + 1) . "-STPS",
                    'status' => $status,
                    'deadline' => now()->addDays(($idx % 60) - 10)->toDateString(),
                    'notification_periods' => json_encode([30, 15, 7, 1]),
                    'responsible_user_id' => $staffId,
                    'created_by' => $defaultStaffId,
                    'applies' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            DB::table('compliance_requirements')->insert($reqData);
        }

        // 4. 300 Documents & Versions
        $existingDocsCount = Document::query()->where('organization_id', $organization->id)->count();
        if ($existingDocsCount < 300) {
            $docsToCreate = 300 - $existingDocsCount;
            for ($i = 1; $i <= $docsToCreate; $i++) {
                $docIndex = $existingDocsCount + $i;
                $accessLevel = ($docIndex % 3 === 0) ? 'compliance_only' : 'all_staff';
                $docCategory = ($docIndex % 2 === 0) ? 'policy' : 'permit_license';

                $docId = DB::table('documents')->insertGetId([
                    'organization_id' => $organization->id,
                    'name' => "Politica y Procedimiento Operativo #{$docIndex}",
                    'category' => $docCategory,
                    'access_level' => $accessLevel,
                    'is_archived' => false,
                    'created_by' => $defaultStaffId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('document_versions')->insert([
                    'document_id' => $docId,
                    'version_label' => '1.0',
                    'storage_path' => "documents/{$docId}/v1.bin",
                    'original_filename' => "documento_{$docIndex}.pdf",
                    'mime_type' => 'application/pdf',
                    'size_bytes' => 1024 * 150,
                    'sha256' => hash('sha256', "document_content_{$docId}"),
                    'status' => 'processed',
                    'is_current' => true,
                    'expiration_date' => $docCategory === 'permit_license' ? now()->addDays(90)->toDateString() : null,
                    'uploaded_by' => $defaultStaffId,
                    'uploaded_at' => now(),
                ]);
            }
        }

        // 5. 2,000 Document Chunks
        $existingChunksCount = DB::table('document_chunks')->count();
        if ($existingChunksCount < 2000) {
            $chunksToCreate = 2000 - $existingChunksCount;
            $versions = DB::table('document_versions')->join('documents', 'documents.id', '=', 'document_versions.document_id')
                ->select('document_versions.id as version_id', 'documents.id as doc_id', 'documents.organization_id')
                ->limit(300)
                ->get();
            $versionsCount = $versions->count();

            if ($versionsCount > 0) {
                $batchSize = 250;
                $chunksBatch = [];

                for ($c = 1; $c <= $chunksToCreate; $c++) {
                    $v = $versions[($c - 1) % $versionsCount];
                    $chunksBatch[] = [
                        'organization_id' => $v->organization_id,
                        'document_id' => $v->doc_id,
                        'document_version_id' => $v->version_id,
                        'chunk_index' => (int) floor($c / $versionsCount),
                        'page_start' => ($c % 20) + 1,
                        'page_end' => ($c % 20) + 1,
                        'section' => "Seccion " . (($c % 10) + 1),
                        'content' => "Contenido del fragmento normativo #{$c}. Este fragmento contiene especificaciones tecnicas sobre medidas de seguridad, prevencion y regulacion aplicable a la organizacion conforme a la normativa oficial.",
                        'is_current' => true,
                        'created_at' => now(),
                    ];

                    if (count($chunksBatch) >= $batchSize) {
                        DB::table('document_chunks')->insert($chunksBatch);
                        $chunksBatch = [];
                    }
                }

                if (!empty($chunksBatch)) {
                    DB::table('document_chunks')->insert($chunksBatch);
                }
            }
        }
    }
}
