<?php

namespace App\Services;

use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\RequiredDocument;
use Carbon\Carbon;

class RequiredDocumentStatusService
{
    public const STATUS_PROVIDED = 'provided';

    public const STATUS_MISSING = 'missing';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_EXPIRED = 'expired';

    /**
     * Determine the status of a single required document according to ADR-022.
     *
     * A required document R is satisfied when:
     * 1. A document D is linked to R.
     * 2. D.category == R.category.
     * 3. D.current_version.status == 'processed'.
     * 4. D.expiration_date is empty or > today in America/Mexico_City.
     *
     * @return array{
     *     status: string,
     *     badge: string,
     *     tooltip: string|null,
     *     is_satisfied: bool,
     *     document: Document|null
     * }
     */
    public function evaluateRequiredDocument(RequiredDocument $requiredDocument, ?Carbon $today = null): array
    {
        $today = $today ? $today->copy()->timezone('America/Mexico_City')->startOfDay() : Carbon::now('America/Mexico_City')->startOfDay();

        $document = $requiredDocument->relationLoaded('document')
            ? $requiredDocument->document
            : $requiredDocument->document()->with('currentVersion')->first();

        if (! $document || $document->is_archived) {
            return [
                'status' => self::STATUS_MISSING,
                'badge' => 'Missing Document',
                'tooltip' => null,
                'is_satisfied' => false,
                'document' => null,
            ];
        }

        // Category must match
        if ($document->category !== $requiredDocument->category) {
            return [
                'status' => self::STATUS_MISSING,
                'badge' => 'Missing Document',
                'tooltip' => null,
                'is_satisfied' => false,
                'document' => $document,
            ];
        }

        // Get current version
        $currentVersion = $document->relationLoaded('currentVersion')
            ? $document->currentVersion
            : $document->currentVersion()->first();

        if (! $currentVersion) {
            return [
                'status' => self::STATUS_MISSING,
                'badge' => 'Missing Document',
                'tooltip' => null,
                'is_satisfied' => false,
                'document' => $document,
            ];
        }

        $versionStatus = strtolower((string) $currentVersion->status);
        if (in_array($versionStatus, [DocumentVersion::STATUS_QUEUED, DocumentVersion::STATUS_PROCESSING], true)) {
            return [
                'status' => self::STATUS_PROCESSING,
                'badge' => 'Processing',
                'tooltip' => null,
                'is_satisfied' => false,
                'document' => $document,
            ];
        }

        if ($versionStatus !== DocumentVersion::STATUS_PROCESSED) {
            return [
                'status' => self::STATUS_MISSING,
                'badge' => 'Missing Document',
                'tooltip' => null,
                'is_satisfied' => false,
                'document' => $document,
            ];
        }

        // Expiration check
        if ($currentVersion->expiration_date !== null) {
            $expiration = Carbon::parse($currentVersion->expiration_date, 'America/Mexico_City')->startOfDay();
            if ($expiration->lte($today)) {
                return [
                    'status' => self::STATUS_EXPIRED,
                    'badge' => 'Missing Document',
                    'tooltip' => "The linked document expired on {$expiration->format('d/m/Y')}.",
                    'is_satisfied' => false,
                    'document' => $document,
                ];
            }
        }

        return [
            'status' => self::STATUS_PROVIDED,
            'badge' => 'Provided',
            'tooltip' => null,
            'is_satisfied' => true,
            'document' => $document,
        ];
    }

    public function isSatisfied(RequiredDocument $requiredDocument, ?Carbon $today = null): bool
    {
        return $this->evaluateRequiredDocument($requiredDocument, $today)['is_satisfied'];
    }

    public function hasMissingDocument(ComplianceRequirement $requirement, ?Carbon $today = null): bool
    {
        $requiredDocs = $requirement->relationLoaded('requiredDocuments')
            ? $requirement->requiredDocuments
            : $requirement->requiredDocuments()->with('document.currentVersion')->get();

        if ($requiredDocs->isEmpty()) {
            return false;
        }

        foreach ($requiredDocs as $doc) {
            if (! $this->isSatisfied($doc, $today)) {
                return true;
            }
        }

        return false;
    }

    public function missingDocumentsCount(ComplianceRequirement $requirement, ?Carbon $today = null): int
    {
        $requiredDocs = $requirement->relationLoaded('requiredDocuments')
            ? $requirement->requiredDocuments
            : $requirement->requiredDocuments()->with('document.currentVersion')->get();

        $count = 0;
        foreach ($requiredDocs as $doc) {
            if (! $this->isSatisfied($doc, $today)) {
                $count++;
            }
        }

        return $count;
    }
}
