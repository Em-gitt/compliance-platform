<?php

use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\LoadTestSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
});

test('LoadTestSeeder populates 1 org, 100 users, 100 reqs, 300 docs, and 2000 chunks', function () {
    $this->seed(LoadTestSeeder::class);

    $org = Organization::where('legal_name', 'Load Test Industrial, S.A. de C.V.')->first();
    expect($org)->not->toBeNull();

    $usersCount = User::where('organization_id', $org->id)->count();
    expect($usersCount)->toBe(100);

    $adminsCount = User::where('organization_id', $org->id)->where('role', 'administrator')->count();
    $staffCount = User::where('organization_id', $org->id)->where('role', 'compliance_staff')->count();
    $employeesCount = User::where('organization_id', $org->id)->where('role', 'general_employee')->count();

    expect($adminsCount)->toBe(5)
        ->and($staffCount)->toBe(25)
        ->and($employeesCount)->toBe(70);

    $reqsCount = ComplianceRequirement::where('organization_id', $org->id)->count();
    expect($reqsCount)->toBe(100);

    $docsCount = Document::where('organization_id', $org->id)->count();
    expect($docsCount)->toBe(300);

    $chunksCount = DB::table('document_chunks')->count();
    expect($chunksCount)->toBe(2000);
});

test('ADR-031 workload benchmark satisfies standard p95 < 2.0s and AI p90 < 10.0s', function () {
    $this->seed(LoadTestSeeder::class);

    $staff = User::where('role', 'compliance_staff')->first();
    $admin = User::where('role', 'administrator')->first();

    config(['services.ai.url' => 'http://ai-service.test', 'services.ai.token' => 'test-token']);
    Http::fake([
        'http://ai-service.test/v1/ask' => Http::response([
            'answer' => 'Sample grounded answer for benchmark.',
            'status' => 'answered',
            'citations' => [],
            'disclaimer' => 'Benchmark disclaimer',
        ], 200),
    ]);

    $standardDurations = [];
    $aiDurations = [];
    $errors = 0;

    // Simulate batch of 50 requests reflecting ADR-031 mix:
    // 25 Dashboard/Requirements (50%)
    for ($i = 0; $i < 25; $i++) {
        $start = hrtime(true);
        $res = ($i % 2 === 0)
            ? $this->actingAs($staff)->getJson('/api/dashboard')
            : $this->actingAs($staff)->getJson('/api/requirements');

        $elapsed = (hrtime(true) - $start) / 1e9;
        $standardDurations[] = $elapsed;
        if (! $res->isSuccessful()) {
            $errors++;
        }
    }

    // 10 Search / Document list (20%)
    for ($i = 0; $i < 10; $i++) {
        $start = hrtime(true);
        $res = $this->actingAs($staff)->getJson('/api/documents?name=politica');
        $elapsed = (hrtime(true) - $start) / 1e9;
        $standardDurations[] = $elapsed;
        if (! $res->isSuccessful()) {
            $errors++;
        }
    }

    // 5 Document detail (10%)
    $doc = Document::first();
    for ($i = 0; $i < 5; $i++) {
        $start = hrtime(true);
        $res = $this->actingAs($staff)->getJson("/api/documents/{$doc->id}");
        $elapsed = (hrtime(true) - $start) / 1e9;
        $standardDurations[] = $elapsed;
        if (! $res->isSuccessful()) {
            $errors++;
        }
    }

    // 5 Document upload (10%)
    for ($i = 0; $i < 5; $i++) {
        $start = hrtime(true);
        $file = UploadedFile::fake()->createWithContent("bench_{$i}.pdf", "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF");
        $res = $this->actingAs($admin)->postJson('/api/documents', [
            'name' => "Benchmark Document {$i}",
            'category' => 'policy',
            'access_level' => 'all_staff',
            'version' => '1.0.0',
            'file' => $file,
        ]);
        $elapsed = (hrtime(true) - $start) / 1e9;
        $standardDurations[] = $elapsed;
        if (! $res->isSuccessful()) {
            $errors++;
        }
    }

    // 5 AI regulatory assistance questions (10%)
    for ($i = 0; $i < 5; $i++) {
        $start = hrtime(true);
        $res = $this->actingAs($staff)->postJson('/api/assistant/questions', [
            'question' => 'What are the safety requirements under NOM-002?',
        ]);
        $elapsed = (hrtime(true) - $start) / 1e9;
        $aiDurations[] = $elapsed;
        if (! $res->isSuccessful()) {
            $errors++;
        }
    }

    // Calculate percentiles
    sort($standardDurations);
    sort($aiDurations);

    $p95Index = (int) ceil(count($standardDurations) * 0.95) - 1;
    $p95Standard = $standardDurations[$p95Index];

    $p90Index = (int) ceil(count($aiDurations) * 0.90) - 1;
    $p90Ai = $aiDurations[$p90Index];

    $totalRequests = count($standardDurations) + count($aiDurations);
    $errorRate = $errors / $totalRequests;

    expect($p95Standard)->toBeLessThan(2.0)
        ->and($p90Ai)->toBeLessThan(10.0)
        ->and($errorRate)->toBeLessThan(0.01);
});
