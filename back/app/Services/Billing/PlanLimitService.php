<?php

namespace App\Services\Billing;

use App\Enums\CompanyMembershipStatus;
use App\Exceptions\Billing\PlanLimitExceededException;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\Invoice;
use Illuminate\Support\Carbon;

/**
 * Hard caps per plan (tope duro, not notify/auto-charge - see pricing table).
 * A company with no plan_id (legacy, pre-plans) is treated as unlimited.
 */
class PlanLimitService
{
    public function assertCanCreateDocument(Company $company): void
    {
        if (! $company->is_active) {
            throw new PlanLimitExceededException(
                'company_inactive',
                'Esta empresa esta desactivada. Contacta a soporte.',
                [],
            );
        }

        $limit = $company->plan?->monthly_document_limit;

        if ($limit === null) {
            return;
        }

        $used = Invoice::query()
            ->where('company_id', $company->id)
            ->whereBetween('issue_date', [
                Carbon::now()->startOfMonth()->toDateString(),
                Carbon::now()->endOfMonth()->toDateString(),
            ])
            ->count();

        if ($used >= $limit) {
            throw new PlanLimitExceededException(
                'plan_document_limit_reached',
                "Alcanzaste el limite de {$limit} documentos/mes de tu plan.",
                ['limit' => $limit, 'used' => $used],
            );
        }
    }

    public function assertCanInviteMember(Company $company): void
    {
        $limit = $company->plan?->max_users;

        if ($limit === null) {
            return;
        }

        $activeMembers = $company->users()
            ->wherePivot('status', CompanyMembershipStatus::Active->value)
            ->count();

        $pendingInvitations = CompanyInvitation::query()
            ->where('company_id', $company->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();

        $used = $activeMembers + $pendingInvitations;

        if ($used >= $limit) {
            throw new PlanLimitExceededException(
                'plan_user_limit_reached',
                "Alcanzaste el limite de {$limit} usuarios de tu plan.",
                ['limit' => $limit, 'used' => $used],
            );
        }
    }
}
