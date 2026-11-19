<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\ComplianceRequirement;
use App\Models\DocumentVersion;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;

class GenerateAlertsCommand extends Command
{
    protected $signature = 'alerts:generate {--date= : The evaluation date in Y-m-d format}';

    protected $description = 'Generate in-application alerts for compliance deadlines, document expirations, and overdue requirements';

    public function handle(): int
    {
        $timezone = 'America/Mexico_City';
        $today = $this->option('date')
            ? Carbon::parse($this->option('date'), $timezone)->startOfDay()
            : Carbon::now($timezone)->startOfDay();

        $this->info("Generating alerts for date: {$today->toDateString()} ({$timezone})");

        $alertsCreated = 0;

        // 1. Requirement deadlines and overdue requirements
        $requirements = ComplianceRequirement::with('responsibleUser')
            ->where('applies', true)
            ->where('status', '!=', 'compliant')
            ->get();

        foreach ($requirements as $requirement) {
            if (! $requirement->responsibleUser) {
                continue;
            }

            $deadlineStr = is_a($requirement->deadline, CarbonInterface::class)
                ? $requirement->deadline->toDateString()
                : (string) $requirement->deadline;
            $deadline = Carbon::parse($deadlineStr, $timezone)->startOfDay();
            $daysDiff = (int) round($today->diffInDays($deadline, false));
            $userName = $requirement->responsibleUser->name;
            $formattedDeadline = $deadline->format('d/m/Y');
            $periods = array_map('intval', $requirement->notification_periods ?? [30, 15, 7, 1]);

            // Check if deadline is due in one of the configured notification periods
            if (in_array($daysDiff, $periods, true)) {
                $message = "Requirement \"{$requirement->name}\" is due in {$daysDiff} days ({$formattedDeadline}). Responsible: {$userName}.";

                $alert = Alert::firstOrCreate(
                    [
                        'type' => 'requirement_due',
                        'compliance_requirement_id' => $requirement->id,
                        'document_version_id' => null,
                        'period_days' => $daysDiff,
                    ],
                    [
                        'user_id' => $requirement->responsibleUser->id,
                        'message' => $message,
                        'due_date' => $deadline->toDateString(),
                        'created_at' => now(),
                    ]
                );

                if ($alert->wasRecentlyCreated) {
                    $alertsCreated++;
                }
            }

            // Check if requirement is overdue (day after deadline, diff = -1)
            if ($daysDiff === -1) {
                $message = "Requirement \"{$requirement->name}\" is overdue since {$formattedDeadline}. Responsible: {$userName}.";

                $alert = Alert::firstOrCreate(
                    [
                        'type' => 'requirement_overdue',
                        'compliance_requirement_id' => $requirement->id,
                        'document_version_id' => null,
                        'period_days' => 0,
                    ],
                    [
                        'user_id' => $requirement->responsibleUser->id,
                        'message' => $message,
                        'due_date' => $deadline->toDateString(),
                        'created_at' => now(),
                    ]
                );

                if ($alert->wasRecentlyCreated) {
                    $alertsCreated++;
                }
            }
        }

        // 2. Document expirations for current versions
        $documentVersions = DocumentVersion::with(['document', 'uploader'])
            ->where('is_current', true)
            ->where('status', DocumentVersion::STATUS_PROCESSED)
            ->whereNotNull('expiration_date')
            ->whereHas('document', fn ($q) => $q->where('is_archived', false))
            ->get();

        foreach ($documentVersions as $version) {
            $uploader = $version->uploader;
            if (! $uploader) {
                continue;
            }

            $expDateStr = is_a($version->expiration_date, CarbonInterface::class)
                ? $version->expiration_date->toDateString()
                : (string) $version->expiration_date;
            $expDate = Carbon::parse($expDateStr, $timezone)->startOfDay();
            $daysDiff = (int) round($today->diffInDays($expDate, false));
            $userName = $uploader->name;
            $formattedExpDate = $expDate->format('d/m/Y');
            $docName = $version->document->name;
            $versionLabel = $version->version_label;

            // Allowed notification periods per ADR-020: 30, 15, 7, 1
            if (in_array($daysDiff, [30, 15, 7, 1], true)) {
                $message = "Document \"{$docName}\" version {$versionLabel} expires in {$daysDiff} days ({$formattedExpDate}). Responsible: {$userName}.";

                $alert = Alert::firstOrCreate(
                    [
                        'type' => 'document_expiring',
                        'compliance_requirement_id' => null,
                        'document_version_id' => $version->id,
                        'period_days' => $daysDiff,
                    ],
                    [
                        'user_id' => $uploader->id,
                        'message' => $message,
                        'due_date' => $expDate->toDateString(),
                        'created_at' => now(),
                    ]
                );

                if ($alert->wasRecentlyCreated) {
                    $alertsCreated++;
                }
            }
        }

        $this->info("Completed. {$alertsCreated} alerts created.");

        return Command::SUCCESS;
    }
}
