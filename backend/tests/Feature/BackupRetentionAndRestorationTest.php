<?php

use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
    $this->organization = Organization::query()->create([
        'legal_name' => 'Restore Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);
    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Admin Restore',
        'email' => 'restore@example.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);
});

it('prunes backups older than 30 days and retains backups within 30-day window (CMP-41 AC 1)', function (): void {
    $tempDir = sys_get_temp_dir().'/retention_test_'.uniqid();
    mkdir($tempDir.'/backups', 0755, true);
    mkdir($tempDir.'/remote/db', 0755, true);
    $logFile = $tempDir.'/backup.log';
    $passphrase = 'TestPassphrase-2026';

    // 1. Create a 35-day-old backup (should be purged)
    $oldFile = $tempDir.'/remote/db/compliance_20261015_0230.dump.gpg';
    file_put_contents($oldFile, 'old-dump-35-days');
    // Set modification time to 35 days ago
    touch($oldFile, time() - (35 * 86400));

    // 2. Create a 20-day-old backup (should be retained)
    $recentFile = $tempDir.'/remote/db/compliance_20261030_0230.dump.gpg';
    file_put_contents($recentFile, 'recent-dump-20-days');
    touch($recentFile, time() - (20 * 86400));

    // 3. Create a 1-day-old backup (should be retained)
    $todayFile = $tempDir.'/remote/db/compliance_20261118_0230.dump.gpg';
    file_put_contents($todayFile, 'yesterday-dump');
    touch($todayFile, time() - (1 * 86400));

    // Run backup script with 30-day retention
    $scriptPath = base_path('../infra/backup/backup.sh');
    $process = new Process([
        'bash',
        $scriptPath,
    ], base_path('..'), [
        'BACKUP_DIR' => $tempDir.'/backups',
        'BACKUP_REMOTE' => $tempDir.'/remote',
        'BACKUP_LOG_FILE' => $logFile,
        'BACKUP_ENCRYPTION_PASSPHRASE' => $passphrase,
        'BACKUP_RETENTION_DAYS' => '30',
        'BACKUP_TEST_MODE' => '1',
    ]);

    $process->run();
    if (! $process->isSuccessful()) {
        dump($process->getOutput(), $process->getErrorOutput());
    }
    expect($process->isSuccessful())->toBeTrue();

    // The 35-day-old file must be deleted
    expect(file_exists($oldFile))->toBeFalse();

    // The 20-day-old and 1-day-old files must be retained
    expect(file_exists($recentFile))->toBeTrue();
    expect(file_exists($todayFile))->toBeTrue();
});

it('verifies restored database integrity and row counts via artisan app:verify-restore (CMP-41 AC 2)', function (): void {
    // Current database has at least 1 organization and 1 user
    $this->artisan('app:verify-restore')
        ->expectsOutputToContain('Restore verification PASSED')
        ->assertExitCode(0);

    // Create a snapshot report matching current counts
    $snapshotPath = sys_get_temp_dir().'/snapshot_'.uniqid().'.json';
    file_put_contents($snapshotPath, json_encode([
        'organizations' => Organization::count(),
        'users' => User::count(),
        'documents' => 0,
        'document_versions' => 0,
    ]));

    $this->artisan("app:verify-restore --snapshot={$snapshotPath}")
        ->expectsOutputToContain('MATCH')
        ->expectsOutputToContain('Restore verification PASSED')
        ->assertExitCode(0);

    // Mismatched snapshot should fail
    $mismatchSnapshotPath = sys_get_temp_dir().'/mismatch_'.uniqid().'.json';
    file_put_contents($mismatchSnapshotPath, json_encode([
        'organizations' => 999, // Mismatched!
        'users' => User::count(),
    ]));

    $this->artisan("app:verify-restore --snapshot={$mismatchSnapshotPath}")
        ->expectsOutputToContain('MISMATCH')
        ->expectsOutputToContain('Restore verification FAILED')
        ->assertExitCode(1);
});

it('executes restoration drill script and validates completion within 4-hour RTO', function (): void {
    $tempDir = sys_get_temp_dir().'/restore_drill_'.uniqid();
    mkdir($tempDir, 0755, true);
    $passphrase = 'RestorePassphrase-Test-41';

    // Create a test encrypted dump
    $plainDump = $tempDir.'/test_db.dump';
    file_put_contents($plainDump, 'Mock pg_dump binary payload');
    $encryptedDump = $tempDir.'/compliance_20261119_0230.dump.gpg';

    $gpgProcess = new Process([
        'gpg', '--batch', '--yes', '--symmetric', '--cipher-algo', 'AES256',
        '--passphrase', $passphrase, '-o', $encryptedDump, $plainDump,
    ]);
    $gpgProcess->run();
    expect($gpgProcess->isSuccessful())->toBeTrue();
    unlink($plainDump);

    $testDb = $tempDir.'/restored_test.sqlite';
    touch($testDb);
    $migrateProcess = new Process([
        'php', 'backend/artisan', 'migrate', '--database=sqlite', '--force',
    ], base_path('..'), [
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $testDb,
    ]);
    $migrateProcess->run();
    expect($migrateProcess->isSuccessful())->toBeTrue();

    // Execute restore script
    $restoreScript = base_path('../infra/scripts/restore-backup.sh');
    $process = new Process([
        'bash',
        $restoreScript,
        $encryptedDump,
    ], base_path('..'), [
        'BACKUP_ENCRYPTION_PASSPHRASE' => $passphrase,
        'RESTORE_WORKDIR' => $tempDir.'/work',
        'TARGET_DB' => 'compliance_restore_test',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $testDb,
    ]);

    $process->run();
    expect($process->isSuccessful())->toBeTrue();
    expect($process->getOutput())->toContain('Restoration Drill Summary');
    expect($process->getOutput())->toContain('Result: PASSED (Restoration time is within the 4-hour RTO objective)');
});
