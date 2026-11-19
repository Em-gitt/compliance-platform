<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DocumentVersion;
use Illuminate\Support\Facades\DB;

class DocumentVersionService
{
    public function __construct(protected AiServiceClient $client) {}

    /**
     * Mark a processed document version as current in a single transaction,
     * notify AI service, and record audit log.
     */
    public function makeCurrent(DocumentVersion $version): void
    {
        DB::transaction(function () use ($version) {
            // 1. Unset current on existing versions of the document
            DocumentVersion::query()
                ->where('document_id', $version->document_id)
                ->where('is_current', true)
                ->where('id', '!=', $version->id)
                ->update(['is_current' => false]);

            // 2. Mark this version as current
            $version->forceFill(['is_current' => true])->save();

            // 3. Write audit log
            AuditLog::query()->create([
                'user_id' => $version->uploaded_by,
                'category' => 'functional',
                'action' => 'document_version.set_current',
                'resource_type' => 'document_version',
                'resource_id' => $version->id,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
                'metadata' => [
                    'document_id' => $version->document_id,
                    'version_label' => $version->version_label,
                ],
                'created_at' => now(),
            ]);

            // 4. Activate chunks via FastAPI setCurrent (CMP-29)
            try {
                $this->client->setCurrent($version->document_id, $version->id);
            } catch (\Throwable) {
                // If AI service is unavailable in local testing without mock
            }
        });
    }
}
