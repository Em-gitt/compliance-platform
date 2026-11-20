<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class VerifyRestoreCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:verify-restore
                            {--snapshot= : Path to production snapshot JSON file to compare row counts against}
                            {--database= : Database connection to verify}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify restored database integrity and row counts against production snapshot';

    /**
     * Core tables to verify.
     *
     * @var array<string>
     */
    protected array $coreTables = [
        'organizations',
        'users',
        'documents',
        'document_versions',
        'compliance_requirements',
        'audit_logs',
        'alerts',
    ];

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $connection = $this->option('database') ?: config('database.default');
        $this->info("Verifying restored database on connection: [{$connection}]");

        $snapshotData = null;
        $snapshotPath = $this->option('snapshot');
        if ($snapshotPath) {
            if (! file_exists($snapshotPath)) {
                $this->error("Snapshot file not found: {$snapshotPath}");

                return 1;
            }

            $content = file_get_contents($snapshotPath);
            $snapshotData = json_decode($content, true);
            if (! is_array($snapshotData)) {
                $this->error("Invalid JSON format in snapshot file: {$snapshotPath}");

                return 1;
            }
        }

        $rows = [];
        $hasMismatch = false;

        foreach ($this->coreTables as $table) {
            if (! Schema::connection($connection)->hasTable($table)) {
                $this->error("Table [{$table}] does not exist in restored database!");
                $hasMismatch = true;
                $rows[] = [$table, 'MISSING', 'N/A', 'FAILED'];

                continue;
            }

            $currentCount = DB::connection($connection)->table($table)->count();

            if ($snapshotData !== null && array_key_exists($table, $snapshotData)) {
                $expectedCount = (int) $snapshotData[$table];
                $matches = ($currentCount === $expectedCount);
                if (! $matches) {
                    $hasMismatch = true;
                }
                $rows[] = [$table, $currentCount, $expectedCount, $matches ? 'MATCH' : 'MISMATCH'];
            } else {
                $rows[] = [$table, $currentCount, 'N/A', 'OK'];
            }
        }

        $headers = ['Table', 'Restored Rows', 'Snapshot Expected', 'Status'];
        $this->table($headers, $rows);

        if ($hasMismatch) {
            $this->error('Restore verification FAILED: row count or table schema mismatches detected.');

            return 1;
        }

        $this->info('Restore verification PASSED: all tables and row counts verified successfully.');

        return 0;
    }
}
