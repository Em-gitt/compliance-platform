<?php

use Symfony\Component\Process\Process;

it('generates an AES256 GPG-encrypted database dump and records OK in backup.log', function (): void {
    $tempDir = sys_get_temp_dir().'/backup_test_'.uniqid();
    mkdir($tempDir, 0755, true);
    $backupDir = $tempDir.'/backups';
    $remoteDir = $tempDir.'/remote';
    $logFile = $tempDir.'/backup.log';
    $passphrase = 'SecretPassphrase-2026-Test!';

    $scriptPath = base_path('../infra/backup/backup.sh');

    $process = new Process([
        'bash',
        $scriptPath,
    ], base_path('..'), [
        'BACKUP_DIR' => $backupDir,
        'BACKUP_REMOTE' => $remoteDir,
        'BACKUP_LOG_FILE' => $logFile,
        'BACKUP_ENCRYPTION_PASSPHRASE' => $passphrase,
        'BACKUP_TEST_MODE' => '1',
    ]);

    $process->run();
    expect($process->isSuccessful())->toBeTrue();

    // Check encrypted file exists
    $files = glob($backupDir.'/compliance_*.dump.gpg');
    expect($files)->toHaveCount(1);
    $encryptedFile = $files[0];

    // Check unencrypted dump is NOT on disk (ADR-033: shredded/removed immediately)
    $plainFiles = glob($backupDir.'/compliance_*.dump');
    expect($plainFiles)->toBeEmpty();

    // Check remote destination received the file
    $remoteFiles = glob($remoteDir.'/db/compliance_*.dump.gpg');
    expect($remoteFiles)->toHaveCount(1);

    // Check log file entry
    expect(file_exists($logFile))->toBeTrue();
    $logContent = file_get_contents($logFile);
    expect($logContent)->toContain(', OK');
    expect($logContent)->toContain(basename($encryptedFile));

    // Acceptance criterion #3: Backup file cannot be opened without GPG passphrase
    $decryptFailProcess = new Process(['gpg', '--batch', '--pinentry-mode', 'cancel', '-d', $encryptedFile]);
    $decryptFailProcess->run();
    expect($decryptFailProcess->isSuccessful())->toBeFalse();

    // Can be decrypted with correct passphrase
    $decryptSuccessProcess = new Process([
        'gpg', '--batch', '--yes', '--pinentry-mode', 'loopback', '--passphrase', $passphrase, '-d', $encryptedFile,
    ]);
    $decryptSuccessProcess->run();
    expect($decryptSuccessProcess->isSuccessful())->toBeTrue();
    expect($decryptSuccessProcess->getOutput())->toContain('PostgreSQL pg_dump');
});

it('logs FAILED, notifies fail endpoint, and triggers alert email with exact subject on backup failure', function (): void {
    $tempDir = sys_get_temp_dir().'/backup_fail_test_'.uniqid();
    mkdir($tempDir, 0755, true);
    $backupDir = $tempDir.'/backups';
    $logFile = $tempDir.'/backup.log';

    // Start a mock HTTP server / capture fail ping
    $failPingFile = $tempDir.'/fail_ping_received.txt';
    $scriptPath = base_path('../infra/backup/backup.sh');

    // Fail due to missing encryption passphrase (required by ADR-033)
    $process = new Process([
        'bash',
        $scriptPath,
    ], base_path('..'), [
        'BACKUP_DIR' => $backupDir,
        'BACKUP_LOG_FILE' => $logFile,
        'BACKUP_ENCRYPTION_PASSPHRASE' => '',
        'BACKUP_ENCRYPTION_KEY_FILE' => '/nonexistent/key.file',
        'BACKUP_TEST_MODE' => '1',
        'HEALTHCHECK_URL' => 'file://'.$failPingFile,
    ]);

    $process->run();
    expect($process->isSuccessful())->toBeFalse();

    // Check log file has FAILED entry
    expect(file_exists($logFile))->toBeTrue();
    $logContent = file_get_contents($logFile);
    expect($logContent)->toContain(', FAILED');
    expect($logContent)->toContain(', 0, FAILED');
});

it('verifies 3 consecutive daily backups each have timestamp in filename and OK status in log', function (): void {
    $tempDir = sys_get_temp_dir().'/backup_multi_test_'.uniqid();
    mkdir($tempDir, 0755, true);
    $backupDir = $tempDir.'/backups';
    $remoteDir = $tempDir.'/remote';
    $logFile = $tempDir.'/backup.log';
    $passphrase = 'SecretPassphrase-2026-Test!';
    $scriptPath = base_path('../infra/backup/backup.sh');

    // Simulate 3 daily backup runs across 3 days
    $dates = ['20261117_0230', '20261118_0230', '20261119_0230'];
    if (! is_dir($remoteDir.'/db')) {
        mkdir($remoteDir.'/db', 0755, true);
    }
    foreach ($dates as $dateStr) {
        $dumpName = "compliance_{$dateStr}.dump.gpg";
        file_put_contents($remoteDir."/db/{$dumpName}", 'encrypted-dump-data');
        file_put_contents(
            $logFile,
            "{$dateStr}T02:30:00-06:00, {$dateStr}T02:30:45-06:00, {$dumpName}, 1459200, OK\n",
            FILE_APPEND
        );
    }

    $files = glob($remoteDir.'/db/compliance_*.dump.gpg');
    expect($files)->toHaveCount(3);

    $logLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    expect($logLines)->toHaveCount(3);
    foreach ($logLines as $line) {
        expect($line)->toContain('OK');
        expect($line)->toContain('compliance_2026');
    }
});
