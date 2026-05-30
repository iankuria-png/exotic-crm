<?php

namespace App\Services\Ai;

/**
 * Deterministic source router for "Talk to Your Data".
 *
 * Decides which knowledge source a question targets:
 *  - business_data   : revenue / markets / subscriptions (vw_payments_usd, vw_market_revenue)
 *  - sales_data      : agent / rep performance (vw_agent_perf)
 *  - project_status  : commits / deploys / releases (read-only GitHub + deploy status)
 *  - hybrid          : the question spans data + project status
 *
 * Routing is intentionally rule-based (not model-driven) so it is predictable,
 * cheap, and testable. The caller still enforces source enablement and refuses
 * any write-intent question before this runs.
 */
class AiQuestionRouter
{
    private const PROJECT_TERMS = [
        'commit', 'deploy', 'deployment', 'release', 'rollback', 'branch',
        'merge', 'pull request', ' pr ', 'sha', 'shipped', 'ship ', 'version',
        'changelog', 'github', 'production build', 'last build', 'pushed',
    ];

    private const SALES_TERMS = [
        'agent', 'rep ', 'reps', 'salesperson', 'sales person', 'sales rep',
        'who closed', 'top performer', 'performer', 'commission', 'staff',
        'team member', 'closer',
    ];

    private const BUSINESS_TERMS = [
        'revenue', 'sales total', 'income', 'earnings', 'market', 'country',
        'subscription', 'subscriber', 'payment', 'paid', 'currency', 'mrr',
        'growth', 'renewal', 'churn', 'how much', 'total earned',
    ];

    /**
     * @return 'business_data'|'sales_data'|'project_status'|'hybrid'
     */
    public function route(string $question, ?string $forced = null): string
    {
        if ($forced !== null && in_array($forced, ['business_data', 'sales_data', 'project_status', 'hybrid'], true)) {
            return $forced;
        }

        $q = ' ' . mb_strtolower(trim($question)) . ' ';

        $isProject  = $this->matchesAny($q, self::PROJECT_TERMS);
        $isSales    = $this->matchesAny($q, self::SALES_TERMS);
        $isBusiness = $this->matchesAny($q, self::BUSINESS_TERMS);

        $isData = $isSales || $isBusiness;

        if ($isProject && $isData) {
            return 'hybrid';
        }

        if ($isProject) {
            return 'project_status';
        }

        if ($isSales) {
            return 'sales_data';
        }

        // Default data questions (incl. ambiguous ones) to business_data.
        return 'business_data';
    }

    /**
     * True when a routed source needs SQL generation against reporting views.
     */
    public function usesSql(string $source): bool
    {
        return in_array($source, ['business_data', 'sales_data', 'hybrid'], true);
    }

    /**
     * True when a routed source needs project-intelligence context.
     */
    public function usesProject(string $source): bool
    {
        return in_array($source, ['project_status', 'hybrid'], true);
    }

    private function matchesAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
