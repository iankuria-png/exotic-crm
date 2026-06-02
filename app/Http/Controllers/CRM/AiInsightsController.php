<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\AiInteraction;
use App\Models\User;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiInsightsSettingsService;
use App\Services\Ai\AiQuestionRouter;
use App\Services\Ai\Exceptions\SqlValidationException;
use App\Services\Ai\ProjectIntelligenceService;
use App\Services\Ai\SqlSafetyValidator;
use App\Services\Seo\Exceptions\AllProvidersFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

/**
 * "Talk to Your Data" + "Project Intelligence" chat endpoint.
 *
 * Read / validate / summarize ONLY. This controller never performs a write of any
 * kind: it generates SELECT-only SQL (gated by SqlSafetyValidator), runs it on the
 * read-only connection, and summarizes; or it reads project status (commits/deploys)
 * and summarizes with citations. Any question expressing mutation intent (deploy,
 * rollback, PR/branch/issue creation, file edits, shell commands, queue actions) is
 * refused. Every model call is logged via AiGateway -> ai_interactions.
 */
class AiInsightsController extends Controller
{
    /** Patterns that signal write/mutation intent — always refused. */
    private const MUTATION_PATTERNS = [
        '/\b(deploy|redeploy|rollback|roll\s*back|revert|restart|reboot|hotfix)\b/i',
        '/\b(create|open|close|merge|delete|remove|cancel)\b.*\b(branch|pull\s*request|pr|issue|ticket|commit|tag|release|deployment)\b/i',
        '/\b(force[-\s]?push|git\s*push|\bpush\b)\b.*\b(branch|commit|main|production|remote)\b/i',
        '/\b(run|execute|trigger|dispatch|kick\s*off|queue|enqueue)\b.*\b(deploy|deployment|migration|queue|job|command|script|pipeline|workflow)\b/i',
        '/\b(edit|modify|change|write|update|patch|insert|drop|truncate)\b.*\b(file|config|database|table|record|row|setting|schema)\b/i',
        '/\b(ssh|shell|terminal|bash|sudo)\b/i',
    ];

    public function __construct(
        private readonly AiInsightsSettingsService $settings,
        private readonly AiQuestionRouter $router,
        private readonly SqlSafetyValidator $validator,
        private readonly ProjectIntelligenceService $project,
        private readonly AiGateway $gateway,
    ) {}

    public function ask(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->userAllowed($user)) {
            return response()->json(['message' => 'You do not have access to AI insights.'], 403);
        }

        if (!$this->settings->enabled()) {
            return response()->json(['message' => 'AI insights are currently disabled.'], 403);
        }

        $validated = $request->validate([
            'question' => ['required', 'string', 'min:3', 'max:1000'],
            'source'   => ['nullable', 'string', 'in:business_data,sales_data,project_status,hybrid'],
        ]);

        $question = trim($validated['question']);

        // Rate limit (per user, per minute).
        $rateKey = 'ai-insights:' . $user->id;
        $perMinute = $this->settings->rateLimitPerMinute();
        if (RateLimiter::tooManyAttempts($rateKey, $perMinute)) {
            return response()->json([
                'status'  => 'rate_limited',
                'message' => 'Too many questions this minute. Please wait a moment.',
                'retry_after' => RateLimiter::availableIn($rateKey),
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);

        // Daily cost cap (defense against runaway spend).
        if ($this->dailyCostExceeded()) {
            return response()->json([
                'status'  => 'cost_capped',
                'message' => 'The daily AI insights budget has been reached. Try again tomorrow.',
            ], 429);
        }

        // Refuse any write/mutation intent — this assistant is read-only.
        if ($this->looksLikeMutation($question)) {
            $this->logRefusal($user, $question);

            return response()->json([
                'status'  => 'refused',
                'source'  => 'guardrail',
                'answer'  => 'I can only read and summarize data and project status. I can\'t deploy, roll back, '
                    . 'change code or files, run commands, or modify GitHub. Ask me about your revenue, markets, '
                    . 'agent performance, or what has recently shipped instead.',
            ]);
        }

        $source = $this->router->route($question, $validated['source'] ?? null);

        if (!$this->settings->sourceEnabled($source)) {
            return response()->json([
                'status'  => 'source_disabled',
                'source'  => $source,
                'message' => 'That information source is turned off in AI settings.',
            ], 422);
        }

        $payload = [
            'status'        => 'ok',
            'source'        => $source,
            'question'      => $question,
            'reporting_currency' => $this->settings->reportingCurrency(),
            'answer'        => null,
            'rows'          => [],
            'columns'       => [],
            'column_meta'    => [],
            'row_count'     => 0,
            'generated_sql' => null,
            'chart'         => null,
            'project'       => null,
            'interaction_ids' => [],
        ];

        try {
            if ($this->router->usesSql($source)) {
                $this->answerFromData($user, $question, $source, $payload);
            }

            if ($this->router->usesProject($source)) {
                $this->answerFromProject($user, $question, $source, $payload);
            }
        } catch (SqlValidationException $e) {
            return response()->json([
                'status'  => 'invalid_sql',
                'source'  => $source,
                'reason'  => $e->reason,
                'message' => 'The generated query was rejected by the safety validator: ' . $e->getMessage(),
            ], 422);
        } catch (AllProvidersFailedException $e) {
            return response()->json([
                'status'  => 'provider_unavailable',
                'source'  => $source,
                'message' => 'The AI providers are unavailable right now. Please try again shortly.',
            ], 503);
        } catch (QueryException|\InvalidArgumentException $e) {
            Log::warning('ai.insights.source_unavailable', ['error' => $e->getMessage(), 'source' => $source]);

            return response()->json([
                'status'  => 'source_unavailable',
                'source'  => $source,
                'message' => 'The selected data source is unavailable right now.',
            ], 503);
        } catch (\Throwable $e) {
            Log::warning('ai.insights.ask_failed', ['error' => $e->getMessage(), 'source' => $source]);

            return response()->json([
                'status'  => 'error',
                'source'  => $source,
                'message' => 'Something went wrong answering that question.',
            ], 500);
        }

        if (!$this->settings->showGeneratedSql()) {
            $payload['generated_sql'] = null;
        }

        return response()->json($payload);
    }

    /**
     * Operator-facing health/config snapshot used to render the chat UI and its
     * empty/disabled states.
     */
    public function health(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$this->userAllowed($user)) {
            return response()->json(['message' => 'You do not have access to AI insights.'], 403);
        }

        $readConnection = (string) config('ai.insights.read_connection', 'mysql_readonly');

        return response()->json([
            'enabled'            => $this->settings->enabled(),
            'allowed'            => true,
            'is_ceo'             => (bool) ($user->is_ceo ?? false),
            'role'               => $user->role,
            'sources'            => [
                'business_data' => $this->settings->sourceEnabled('business_data'),
                'sales_data'    => $this->settings->sourceEnabled('sales_data'),
                'project_status'=> $this->settings->sourceEnabled('project_status') && $this->project->enabled(),
                'hybrid'        => $this->settings->sourceEnabled('hybrid'),
            ],
            'show_generated_sql' => $this->settings->showGeneratedSql(),
            'chart_suggestions'  => $this->settings->chartSuggestions(),
            'reporting_views'    => (array) config('ai.reporting_views', []),
            'rate_limit_per_minute' => $this->settings->rateLimitPerMinute(),
            'daily_cost_cap_usd' => $this->settings->dailyCostCapUsd(),
            'daily_cost_used_usd'=> $this->dailyCostUsed(),
            'reporting_currency' => $this->settings->reportingCurrency(),
            'read_connection'    => $readConnection,
            'project_intelligence' => [
                'enabled' => $this->project->enabled(),
                'commit_lookback' => $this->settings->projectCommitLookback(),
                'include_deployment_history' => $this->settings->includeDeploymentHistory(),
                'show_commit_urls' => $this->settings->showCommitUrls(),
            ],
            'scope'              => $this->resolveScope($user) === null ? 'org_wide' : 'market_scoped',
        ]);
    }

    // ---------------------------------------------------------------------
    // Data (NL -> SQL) path
    // ---------------------------------------------------------------------

    private function answerFromData(User $user, string $question, string $source, array &$payload): void
    {
        $scope = $this->resolveScope($user);

        $system = $this->sqlSystemPrompt($scope);
        $result = $this->gateway->generate('insights_sql', $system, $question, [
            'user_id'    => $user->id,
            'model'      => config('ai.providers.sql_model'),
            'max_tokens' => 600,
        ]);
        $payload['interaction_ids'][] = $result->interaction->id;

        $candidateSql = $this->extractSql($result->text());

        $validatedSql = $this->validator->validate($candidateSql, $scope);

        // Persist the (validated) SQL onto the interaction for the audit trail.
        $result->interaction->update(['generated_sql' => $validatedSql['sql']]);

        $rows = $this->runReadOnly($validatedSql['sql']);

        $rowArrays = array_map(fn ($r) => (array) $r, $rows);
        $columns   = $rowArrays === [] ? [] : array_keys($rowArrays[0]);

        $payload['generated_sql'] = $validatedSql['sql'];
        $payload['rows']          = $rowArrays;
        $payload['columns']       = $columns;
        $payload['column_meta']   = $this->columnMeta($columns);
        $payload['row_count']     = count($rowArrays);
        $payload['chart']         = $this->settings->chartSuggestions()
            ? $this->suggestChart($columns, $rowArrays)
            : null;

        $answer = $this->summarizeData($user, $question, $rowArrays, $columns);
        $payload['answer'] = $this->mergeAnswer($payload['answer'], $answer);
    }

    private function summarizeData(User $user, string $question, array $rows, array $columns): string
    {
        if ($rows === []) {
            return 'No matching records were found for that question.';
        }

        $preview = array_slice($this->formatRowsForSummary($rows, $columns), 0, 30);
        $currency = $this->settings->reportingCurrency();
        $system = 'You are a precise analytics summarizer for a sales CRM. You are given a question and the actual '
            . 'rows returned by a read-only SQL query against reporting views (already market-scoped and PII-free). '
            . 'Answer the question in 1-3 sentences using ONLY these rows. Cite concrete figures. Never invent data. '
            . "If the rows do not answer the question, say so plainly. All monetary values are {$currency}-normalized; "
            . "format money as {$currency} with exactly 2 decimal places.";

        $userMsg = "Question: {$question}\n\nColumns: " . implode(', ', $columns)
            . "\n\nRows (JSON):\n" . json_encode($preview, JSON_UNESCAPED_SLASHES);

        try {
            $result = $this->gateway->generate('insights_summary', $system, $userMsg, [
                'user_id'    => $user->id,
                'model'      => config('ai.providers.summary_model'),
                'max_tokens' => 400,
            ]);

            return trim($result->text()) !== '' ? trim($result->text()) : $this->templateDataAnswer($rows, $columns);
        } catch (\Throwable $e) {
            // Summary is best-effort; rows are already returned. Fall back to a
            // deterministic template so a provider failure never blanks the answer.
            return $this->templateDataAnswer($rows, $columns);
        }
    }

    private function templateDataAnswer(array $rows, array $columns): string
    {
        return sprintf(
            'Returned %d row%s across columns: %s.',
            count($rows),
            count($rows) === 1 ? '' : 's',
            implode(', ', $columns)
        );
    }

    private function formatRowsForSummary(array $rows, array $columns): array
    {
        $moneyColumns = array_filter($columns, fn ($column) => $this->isMoneyColumn((string) $column));
        $currency = $this->settings->reportingCurrency();

        return array_map(function (array $row) use ($moneyColumns, $currency) {
            foreach ($moneyColumns as $column) {
                if (array_key_exists($column, $row) && is_numeric($row[$column])) {
                    $row[$column] = $currency . ' ' . number_format((float) $row[$column], 2, '.', ',');
                }
            }

            return $row;
        }, $rows);
    }

    private function columnMeta(array $columns): array
    {
        $meta = [];

        foreach ($columns as $column) {
            $meta[$column] = [
                'type' => $this->isMoneyColumn($column) ? 'money' : 'text',
                'currency' => $this->isMoneyColumn($column) ? $this->settings->reportingCurrency() : null,
            ];
        }

        return $meta;
    }

    private function isMoneyColumn(string $column): bool
    {
        $name = strtolower($column);

        if (Str::endsWith($name, ['_id', '_count']) || in_array($name, ['id', 'count', 'payments_count'], true)) {
            return false;
        }

        return Str::contains($name, [
            'revenue',
            'amount',
            'total',
            'cost',
            'usd',
            'mrr',
            'arr',
            'ltv',
            'price',
            'payment',
        ]) && !Str::contains($name, ['count', 'payments_count']);
    }

    // ---------------------------------------------------------------------
    // Project Intelligence path
    // ---------------------------------------------------------------------

    private function answerFromProject(User $user, string $question, string $source, array &$payload): void
    {
        if (!$this->project->enabled()) {
            $payload['project'] = ['available' => false, 'notes' => ['Project intelligence is disabled.']];
            $payload['answer']  = $this->mergeAnswer(
                $payload['answer'],
                'Project status is currently unavailable.'
            );

            return;
        }

        $context = $this->project->context();
        $payload['project'] = $context;

        $system = 'You are a release/deployment analyst. Answer ONLY from the provided project evidence '
            . '(commits with SHAs, deploy status, deployment history). Cite specific commit SHAs and dates. '
            . 'Clearly distinguish: (a) explicit evidence, (b) reasonable inference (label it as inference), and '
            . '(c) insufficient evidence (say so — never guess). You cannot perform any action: you only report status.';

        $userMsg = "Question: {$question}\n\nPROJECT EVIDENCE:\n" . $this->project->evidenceText($context);

        try {
            $result = $this->gateway->generate('insights_project', $system, $userMsg, [
                'user_id'    => $user->id,
                'model'      => config('ai.providers.summary_model'),
                'max_tokens' => 500,
            ]);
            $payload['interaction_ids'][] = $result->interaction->id;
            $answer = trim($result->text());
        } catch (AllProvidersFailedException $e) {
            // Re-throw so ask() returns a clean 503 for the pure project_status path.
            if ($source === 'project_status') {
                throw $e;
            }
            $answer = '';
        }

        if ($answer === '') {
            $answer = $context['available']
                ? $this->templateProjectAnswer($context)
                : 'Project status is unavailable right now (' . implode(' ', $context['notes']) . ').';
        }

        $payload['answer'] = $this->mergeAnswer($payload['answer'], $answer);
    }

    private function templateProjectAnswer(array $context): string
    {
        $deployed = $context['deployed_version']['short_sha'] ?? 'unknown';
        $ahead    = $context['ahead_by'];

        return "Deployed version is {$deployed} on branch "
            . ($context['tracked_branch'] ?? 'unknown')
            . ", with {$ahead} commit(s) ahead awaiting deploy.";
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    private function userAllowed(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        if ((bool) ($user->is_ceo ?? false)) {
            return true;
        }

        return in_array($user->role, $this->settings->allowedRoles(), true);
    }

    /**
     * @return int[]|null null = org-wide (admin / CEO); array = restricted markets (sub-admin).
     */
    private function resolveScope(User $user): ?array
    {
        if ((bool) ($user->is_ceo ?? false) || $user->role === 'admin') {
            return null;
        }

        // sub_admin (and any other allowed non-admin) is restricted to their markets.
        return $user->assignedMarketIds();
    }

    private function looksLikeMutation(string $question): bool
    {
        foreach (self::MUTATION_PATTERNS as $pattern) {
            if (preg_match($pattern, $question)) {
                return true;
            }
        }

        return false;
    }

    private function logRefusal(User $user, string $question): void
    {
        try {
            AiInteraction::create([
                'feature'        => 'insights_refused',
                'user_id'        => $user->id,
                'prompt'         => mb_substr($question, 0, 1000),
                'prompt_hash'    => hash('sha256', $question),
                'result_summary' => 'Refused: write/mutation intent detected.',
                'status'         => 'refused',
                'provider'       => null,
                'input_tokens'   => 0,
                'output_tokens'  => 0,
                'est_cost_usd'   => 0,
            ]);
        } catch (\Throwable $e) {
            Log::info('ai.insights.refusal_log_failed', ['error' => $e->getMessage()]);
        }
    }

    private function extractSql(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            throw new SqlValidationException('The model returned an empty SQL payload.', 'invalid_json');
        }

        // The SQL generator must return JSON only: {"sql":"SELECT ..."}.
        // This keeps prose/markdown from accidentally reaching the SQL validator.
        try {
            $payload = json_decode($text, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SqlValidationException('The model returned invalid JSON for SQL generation.', 'invalid_json');
        }

        $sql = is_array($payload) ? ($payload['sql'] ?? null) : null;

        if (!is_string($sql) || trim($sql) === '') {
            throw new SqlValidationException('The model JSON payload did not contain a SQL statement.', 'invalid_json');
        }

        return trim($sql);
    }

    private function runReadOnly(string $sql): array
    {
        $connection = (string) config('ai.insights.read_connection', 'mysql_readonly');
        $db = DB::connection($connection);

        // Best-effort server-side statement timeout (MySQL only).
        if ($db->getDriverName() === 'mysql') {
            try {
                $ms = max(1000, $this->settings->sqlTimeoutSeconds() * 1000);
                $db->statement('SET SESSION max_execution_time = ' . (int) $ms);
            } catch (\Throwable $e) {
                // Non-fatal: validator + read-only grants remain the primary guards.
            }
        }

        return $db->select($sql);
    }

    private function suggestChart(array $columns, array $rows): ?array
    {
        if (count($columns) < 2 || $rows === []) {
            return null;
        }

        $first = $rows[0];

        // Find a numeric measure column and a dimension column.
        $numeric = null;
        $dimension = null;
        foreach ($columns as $col) {
            $val = $first[$col] ?? null;
            if ($numeric === null && is_numeric($val)) {
                $numeric = $col;
            } elseif ($dimension === null && !is_numeric($val)) {
                $dimension = $col;
            }
        }

        if ($numeric === null || $dimension === null) {
            return null;
        }

        $type = Str::contains(strtolower($dimension), ['date', 'day', 'month', 'week']) ? 'line' : 'bar';

        return ['type' => $type, 'x' => $dimension, 'y' => $numeric];
    }

    private function mergeAnswer(?string $existing, string $next): string
    {
        $next = trim($next);

        if ($existing === null || trim($existing) === '') {
            return $next;
        }

        return trim($existing) . "\n\n" . $next;
    }

    private function dailyCostUsed(): float
    {
        return (float) AiInteraction::query()
            ->where('feature', 'like', 'insights%')
            ->where('created_at', '>=', Carbon::now()->startOfDay())
            ->sum('est_cost_usd');
    }

    private function dailyCostExceeded(): bool
    {
        $cap = $this->settings->dailyCostCapUsd();

        if ($cap <= 0) {
            return false;
        }

        return $this->dailyCostUsed() >= $cap;
    }

    private function sqlSystemPrompt(?array $scope): string
    {
        $views = (array) config('ai.reporting_views', []);
        $maxLimit = $this->settings->maxRowLimit();
        $defaultLimit = $this->settings->defaultRowLimit();
        $currency = $this->settings->reportingCurrency();

        $schema = <<<SCHEMA
You translate a business question into a SINGLE read-only MySQL SELECT statement.

You may ONLY read these reporting views (all monetary amounts are {$currency}-normalized, all rows are PII-free):

- vw_payments_usd(payment_id, platform_id, market_country, source_currency, amount_original, amount_usd, status, payment_date)
- vw_market_revenue(platform_id, market_name, market_country, revenue_date, revenue_usd, payments_count)
- vw_agent_perf(agent_id, agent_role, platform_id, revenue_date, revenue_usd, payments_count)

HARD RULES (a safety validator will reject violations):
- Output ONLY valid JSON in this exact shape: {"sql":"SELECT ... LIMIT ..."}.
- The JSON sql value contains the SQL. No prose, no markdown fences, no comments.
- Exactly one statement. SELECT only. No semicolons except an optional trailing one.
- FROM/JOIN only the views above. No other tables. No subqueries against base tables.
- No INSERT/UPDATE/DELETE/DDL/SET/USE or any non-SELECT keyword.
- Always include a LIMIT (<= {$maxLimit}; default {$defaultLimit}).
- ALWAYS include platform_id in the SELECT list.
- Prefer *_usd amount columns for every money answer; do not use amount_original unless the user explicitly asks for source-currency raw values.
- Alias derived monetary totals with a *_usd suffix and ROUND monetary expressions to 2 decimals.
SCHEMA;

        if (is_array($scope)) {
            $schema .= "\n- The result will be additionally filtered by platform_id server-side; still include platform_id.";
        }

        $schema .= "\n\nAvailable views: " . implode(', ', $views) . '.';

        return $schema;
    }
}
