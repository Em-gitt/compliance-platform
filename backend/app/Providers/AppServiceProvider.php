<?php

namespace App\Providers;

use App\Enums\Role;
use App\Events\CriteriaUpdated;
use App\Events\OrganizationSaved;
use App\Jobs\EvaluateApplicability;
use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Observers\ComplianceRequirementObserver;
use App\Observers\DocumentObserver;
use App\Observers\DocumentVersionObserver;
use App\Observers\OrganizationObserver;
use App\Observers\RequiredDocumentObserver;
use App\Policies\AuditLogPolicy;
use App\Policies\ComplianceRequirementPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\OrganizationPolicy;
use App\Policies\UserPolicy;
use App\Services\Compliance\CriteriaValidator;
use App\Support\AssistantMessages;
use App\Support\OrganizationCatalogs;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CriteriaValidator::class, function ($app) {
            $catalogs = $app->make(OrganizationCatalogs::class);

            return new CriteriaValidator([
                'organization_type' => array_keys($catalogs->names('organization_types')),
                'industry' => array_keys($catalogs->names('industries')),
                'state' => array_keys($catalogs->names('mexican_states')),
                'business_activities' => array_keys($catalogs->names('business_activities')),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(User::class, UserPolicy::class);
        Gate::policy(ComplianceRequirement::class, ComplianceRequirementPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Organization::class, OrganizationPolicy::class);

        Gate::define('manage-users', fn ($user): bool => $user->role === Role::Administrator);
        Gate::define('manage-organization', fn ($user): bool => $user->role === Role::Administrator);
        Gate::define('manage-requirements', fn ($user): bool => in_array($user->role, [Role::Administrator, Role::ComplianceStaff], true));
        Gate::define('change-compliance-status', fn ($user): bool => $user->role === Role::ComplianceStaff);
        Gate::define('upload-documents', fn ($user): bool => in_array($user->role, [Role::Administrator, Role::ComplianceStaff], true));
        Gate::define('view-documents', fn ($user): bool => in_array($user->role, Role::cases(), true));
        Gate::define('view-dashboard', fn ($user): bool => in_array($user->role, [Role::Administrator, Role::ComplianceStaff], true));
        Gate::define('use-ai-assistant', fn ($user): bool => in_array($user->role, Role::cases(), true));

        // Registro de los Observers (Exactamente una sola vez)
        ComplianceRequirement::observe(ComplianceRequirementObserver::class);
        Organization::observe(OrganizationObserver::class);
        Document::observe(DocumentObserver::class);
        DocumentVersion::observe(DocumentVersionObserver::class);
        RequiredDocument::observe(RequiredDocumentObserver::class);

        // CMP-33: 20 questions per minute per user.
        RateLimiter::for('assistant-questions', fn (Request $request) => Limit::perMinute(20)
            ->by('assistant:'.$request->user()?->id)
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => AssistantMessages::TOO_MANY_QUESTIONS],
                429,
                $headers,
            )));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        try {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $pdo = DB::connection()->getPdo();
                if (method_exists($pdo, 'sqliteCreateFunction')) {
                    @$pdo->sqliteCreateFunction('unaccent', function ($str) {
                        if ($str === null) {
                            return null;
                        }
                        $t = \Transliterator::create('NFD; [:Nonspacing Mark:] Remove; NFC;');

                        return $t ? $t->transliterate($str) : (iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $str) ?: $str);
                    });
                }
            }
        } catch (\Throwable $e) {
        }

        // CMP-17: applicability evaluation (FR-007, ADR-021).
        // Organization changes can affect every requirement, so they go to the queue.
        Event::listen(OrganizationSaved::class, function (OrganizationSaved $event) {
            $job = new EvaluateApplicability(
                $event->organization->id,
                null,
                EvaluateApplicability::REASON_ORGANIZATION,
            );

            if (app()->environment('testing')) {
                dispatch_sync($job);
            } else {
                dispatch($job)->afterCommit();
            }
        });

        // Saving criteria evaluates one requirement right away, so the list is current when the toast appears.
        Event::listen(CriteriaUpdated::class, fn (CriteriaUpdated $event) => EvaluateApplicability::dispatchSync(
            $event->requirement->organization_id,
            $event->requirement->id,
            EvaluateApplicability::REASON_CRITERIA,
        ));
    }
}
