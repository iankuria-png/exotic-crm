<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Models\PaymentRecoveryCase;
use App\Services\MarketAuthorizationService;
use App\Services\PaymentAttributionService;
use App\Services\PaymentRecoveryEvidencePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaymentRecoveryCaseController extends Controller
{
    public function index(Request $request, MarketAuthorizationService $markets)
    {
        $v = $request->validate(['platform_id' => 'nullable|integer', 'state' => 'nullable|in:open,awaiting_identity,settlement_review,awaiting_fulfillment,effect_unknown,closed', 'reason' => 'nullable|string|max:80', 'assigned_to' => 'nullable|integer', 'per_page' => 'nullable|integer|min:1|max:100', 'export' => 'nullable|boolean']);
        $q = $markets->applyPlatformScope(PaymentRecoveryCase::query(), $request->user())->whereHas('payment', fn ($q) => $q->businessVisible()->subscriptionRevenue());
        foreach (['platform_id', 'state', 'assigned_to'] as $key) {
            if (isset($v[$key])) {
                $q->where($key, $v[$key]);
            }
        }
        if (! empty($v['reason'])) {
            $q->where('reason_code', $v['reason']);
        }
        $q->with(['payment:id,platform_id,client_id,amount,currency,status,deal_id,created_at', 'payment.attribution']);
        if ($request->boolean('export')) {
            return response()->streamDownload(function () use ($q) {
                $f = fopen('php://output', 'w');
                fputcsv($f, ['case', 'payment', 'market', 'state', 'reason', 'amount', 'currency', 'owner', 'opened_at']);
                foreach ($q->orderBy('id')->cursor() as $c) {
                    fputcsv($f, [$c->public_reference, $c->payment_id, $c->platform_id, $c->state, $c->reason_code, $c->payment?->amount, $c->payment?->currency, $c->assigned_to ?? $c->queue_key, $c->opened_at]);
                }fclose($f);
            }, 'payment-recovery.csv', ['Content-Type' => 'text/csv']);
        }
        $summary = PaymentRecoveryCase::whereIn('payment_recovery_cases.id', (clone $q)->select('payment_recovery_cases.id'))->where('payment_recovery_cases.state', '!=', 'closed')->join('payments', 'payments.id', '=', 'payment_recovery_cases.payment_id')->selectRaw('payment_recovery_cases.reason_code, payments.currency, COUNT(*) as receipts, SUM(payments.amount) as amount, MIN(payment_recovery_cases.opened_at) as oldest_at')->groupBy('payment_recovery_cases.reason_code', 'payments.currency')->get();
        $result = $q->orderBy('opened_at')->orderBy('id')->paginate($v['per_page'] ?? 50)->toArray();
        $result['summary'] = $summary;

        return response()->json($result);
    }

    public function update(Request $request, PaymentRecoveryCase $case, MarketAuthorizationService $markets)
    {
        abort_unless($markets->userCanAccessPlatform($request->user(), (int) $case->platform_id), 404);
        abort_unless(in_array($request->user()->role, ['admin', 'sub_admin'], true), 403);
        $v = $request->validate(['request_key' => 'required|uuid', 'version' => 'required|integer', 'action' => 'required|in:assign,reopen,record_disposition,verify_receipt', 'reason' => 'required|string|min:3|max:500', 'assigned_to' => 'nullable|integer|exists:users,id', 'disposition' => 'nullable|in:fulfilled,compensating_benefit_reconciled,refunded,reversed,invalid_receipt,no_cash_trial', 'deal_id' => 'nullable|integer', 'audit_id' => 'nullable|integer']);

        return DB::transaction(function () use ($case, $v, $request, $markets) {
            $case = PaymentRecoveryCase::whereKey($case->id)->lockForUpdate()->firstOrFail();
            $requestHash = hash('sha256', json_encode($v));
            $prior = DB::table('payment_recovery_actions')->where('case_id', $case->id)->where('request_key', $v['request_key'])->first();
            if ($prior) {
                abort_unless(hash_equals($prior->request_hash, $requestHash), 409, 'Request key already used for another action.');

                return response()->json(json_decode($prior->response_json, true));
            }
            abort_unless((int) $case->version === (int) $v['version'], 409, 'Recovery case changed. Refresh before saving.');
            $before = $case->toArray();
            $changes = ['version' => $case->version + 1];
            $p = $case->payment;
            if ($v['action'] === 'assign') {
                if (! empty($v['assigned_to'])) {
                    abort_unless($markets->userCanAccessPlatform(\App\Models\User::findOrFail($v['assigned_to']), (int) $case->platform_id), 422, 'Assignee cannot access this market.');
                }
                $changes['assigned_to'] = $v['assigned_to'] ?? null;
            } elseif ($v['action'] === 'verify_receipt') {
                app(PaymentRecoveryEvidencePolicy::class)->recordReviewedReceipt($p, (int) ($v['audit_id'] ?? 0));
                $changes += ['state' => 'open', 'closed_at' => null, 'disposition' => null, 'next_retry_at' => now()];
            } elseif ($v['action'] === 'reopen') {
                $changes += ['state' => 'open', 'closed_at' => null, 'disposition' => null, 'next_retry_at' => now()];
            } else {
                $disposition = $v['disposition'] ?? '';
                $evidence = null;
                if ($disposition === 'fulfilled') {
                    $evidence = app(PaymentRecoveryEvidencePolicy::class)->benefit($p);
                }
                if ($disposition === 'compensating_benefit_reconciled') {
                    $deal = \App\Models\Deal::whereKey($v['deal_id'] ?? 0)->where('platform_id', $p->platform_id)->where('client_id', $p->client_id)->whereNotNull('activated_at')->first();
                    if ($deal && $p->client_id && $deal->is_free_trial && (int) $deal->product_id === (int) $p->product_id && $deal->activated_at->gte($p->created_at) && $deal->expires_at && $deal->expires_at->gte($deal->activated_at->copy()->addDays(max(1, (int) data_get($p->payment_data, 'duration_days', 1))))) {
                        $evidence = ['deal_id' => $deal->id, 'activated_at' => $deal->activated_at, 'expires_at' => $deal->expires_at];
                    }
                }
                if ($disposition === 'refunded') {
                    $audit = \App\Models\AuditLog::whereKey($v['audit_id'] ?? 0)->where('platform_id', $p->platform_id)->where('entity_type', 'payment')->where('entity_id', $p->id)->where('action', 'payment_refund_verified')->first();
                    if ($audit && $audit->actor && in_array($audit->actor->role, ['admin', 'sub_admin'], true) && ! empty($audit->after_state['refund_reference']) && (float) ($audit->after_state['amount'] ?? 0) === (float) $p->amount && ($audit->after_state['currency'] ?? '') === $p->currency) {
                        $evidence = ['audit_id' => $audit->id, 'refund_reference' => $audit->after_state['refund_reference']];
                    }
                }
                if ($disposition === 'reversed' && $p->resolution_code === 'reversed') {
                    $evidence = ['resolution_code' => 'reversed'];
                }
                if ($disposition === 'invalid_receipt' && $p->resolution_code === 'invalid_reference') {
                    $evidence = ['resolution_code' => 'invalid_reference'];
                }
                if ($disposition === 'no_cash_trial' && (float) $p->amount === 0.0) {
                    $evidence = ['amount' => 0];
                }
                abort_unless($evidence, 422, 'A verified benefit or recorded resolution is required.');
                $changes += ['state' => 'closed', 'disposition' => $disposition, 'evidence_json' => $evidence, 'closed_at' => now(), 'next_retry_at' => null];
            }
            $case->update($changes);
            app(PaymentAttributionService::class)->audit($p, 'payment_recovery_'.$v['action'], $before, $changes, $v['reason'], $request->user()->id);
            $response = $case->fresh()->toArray();
            DB::table('payment_recovery_actions')->insert(['case_id' => $case->id, 'request_key' => $v['request_key'], 'request_hash' => $requestHash, 'response_json' => json_encode($response), 'created_at' => now(), 'updated_at' => now()]);

            return response()->json($response);
        });
    }
}
