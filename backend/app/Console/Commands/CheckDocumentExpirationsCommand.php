<?php

namespace App\Console\Commands;

use App\Models\ComplianceRequirement;
use App\Services\RequiredDocumentStatusService;
use Illuminate\Console\Command;

class CheckDocumentExpirationsCommand extends Command
{
    protected $signature = 'compliance:check-expirations';

    protected $description = 'Recalculate required document statuses for expired documents daily at 00:05';

    public function handle(RequiredDocumentStatusService $statusService): int
    {
        $this->info('Evaluating required documents expiration statuses...');

        $requirements = ComplianceRequirement::with(['requiredDocuments.document.currentVersion'])->get();
        $totalEvaluated = 0;

        foreach ($requirements as $requirement) {
            foreach ($requirement->requiredDocuments as $requiredDoc) {
                $statusService->evaluateRequiredDocument($requiredDoc);
                $totalEvaluated++;
            }
        }

        $this->info("Successfully evaluated {$totalEvaluated} required documents.");

        return Command::SUCCESS;
    }
}
