import React from 'react';
import { Link } from 'react-router-dom';

const explanations = {
    multiple_exact_package_matches: 'More than one package matches. Staff must choose the purchased service.',
    multiple_purchase_candidates: 'More than one purchase matches. Staff must choose the intended purchase.',
    no_exact_catalogue_package_match: 'No configured package matches this amount. Review the agreed service.',
    approved_default_package_unavailable: 'The approved automatic package is unavailable. Review the service.',
    automatic_default_package_approval_required: 'This payment predates approval of the automatic package. Review it manually.',
    automatic_catalogue_matching_policy_required: 'An approved package policy is required for this payment.',
    automatic_activation_paused: 'Automatic activation is paused. Staff can review and activate this payment.',
    receipt_outside_automatic_age: 'This payment is older than the automatic window. Staff review is required.',
    receipt_outside_automation_epoch: 'This payment predates the current automatic policy. Staff review is required.',
    historical_receipt_no_dispatch: 'Historical evidence is for reconciliation. It cannot activate a subscription.',
    receiving_device_offline_or_unbound: 'The receiving phone needs attention before automatic activation can run.',
    automatic_account_acceptance_required: 'Automatic activation is awaiting the remaining acceptance checks.',
    exact_site_writer_acceptance_required: 'Site activation and correction checks must be accepted first.',
    account_entitlement_writes_disabled: 'Automatic activation has not been released for this account.',
    receipt_requires_finance_review: 'The payment evidence needs Finance review.',
    receiving_account_not_ready: 'The receiving account needs verification.',
    account_has_uncertain_operation: 'Another activation needs site confirmation before this account can continue.',
    profile_or_package_requires_review: 'Confirm the profile and package before activation.',
};
const explain = reason => explanations[reason] || 'A required check needs staff review. See the matching evidence below.';
const progressStates = ['ready_auto', 'dispatching', 'sales_prepared', 'sales_activating'];

export default function CollectionPaymentJourney({ row, assessment, loading, failed, onRetry }) {
    const recorded = row.usage?.state === 'already_recorded';
    const checking = ['uncertain', 'correcting'].includes(row.decision_state) || ['uncertain', 'correcting'].includes(row.allocation?.state);
    const confirmed = !recorded && !checking && (row.sale?.state === 'confirmed' || row.allocation?.effects?.some(effect =>
        !effect.superseded_at && ['primary_paid', 'target_paid', 'verification_only'].includes(effect.kind) && effect.state === 'succeeded'));
    const processing = progressStates.includes(row.decision_state);
    const profile = assessment?.profile;
    const purchase = row.purchase || assessment?.package?.purchase;
    const selected = !!row.purchase || !!row.sale;
    const packageMatched = !!purchase || assessment?.package?.state === 'matched';
    const finalLabel = recorded ? 'Already recorded' : confirmed ? 'Site confirmed' : checking ? 'Checking confirmation' : processing ? 'Activation processing' : 'Not activated';
    const stages = [
        { label: 'SMS received', detail: row.account_label, done: true },
        { label: selected ? 'Profile selected' : profile ? 'Client matched' : 'Client needs review', detail: profile ? `${profile.name} · ${profile.score} / 100` : selected ? 'Saved purchase' : `Needs a unique score above ${assessment?.threshold ?? 80}`, done: !!profile || selected },
        { label: selected ? 'Package selected' : packageMatched ? 'Package matched' : 'Package needs review', detail: purchase ? `${purchase.tier?.toUpperCase()} · ${purchase.duration_days} days` : 'Confirm the purchased service', done: packageMatched },
        { label: finalLabel, detail: confirmed ? 'Subscription verified on the site' : recorded ? 'No new activation' : checking ? 'Do not activate again' : processing ? 'Waiting for the site' : 'No new activation confirmed', done: confirmed || recorded },
    ];
    return <section aria-label="Payment progress" className="rounded-lg border border-slate-200 bg-slate-50 p-4">
        <h3 className="text-sm font-semibold text-slate-900">Payment progress</h3>
        <ol className="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">{stages.map((stage, index) => <li key={index} className="min-w-0">
            <div className="flex items-center gap-2"><span aria-hidden="true" className={`flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-xs font-semibold ${stage.done ? 'bg-teal-700 text-white' : 'border border-slate-300 bg-white text-slate-600'}`}>{stage.done ? '✓' : index + 1}</span><span className="text-xs font-semibold text-slate-900">{stage.label}</span></div>
            <p className="mt-2 break-words text-xs text-slate-600">{stage.detail}</p>
        </li>)}</ol>
        {loading && <p role="status" className="mt-4 text-sm text-slate-600">Assessing client and package matches…</p>}
        {failed && <p role="alert" className="mt-4 text-sm text-rose-800">The automatic assessment is unavailable. <button type="button" className="underline" onClick={onRetry}>Retry assessment</button></p>}
        {!recorded && !confirmed && !processing && !checking && assessment && <div className="mt-4 border-t border-slate-200 pt-3 text-sm text-slate-700">
            <p>{assessment.activation?.reason ? explain(assessment.activation.reason) : 'Matching is advisory here. The worker still checks identity, service and payment eligibility before activating.'}</p>
            {assessment.package?.state === 'needs_review' && <p className="mt-2">{explain(assessment.package.reason)}</p>}
            {purchase?.approved_default && <p className="mt-2 text-xs text-slate-600">Uses the approved automatic package for this amount.</p>}
            {profile && <Link to={`/clients/${profile.id}`} target="_blank" rel="noopener noreferrer" className="mt-2 inline-block font-medium text-teal-800 underline">Open {profile.name}’s profile</Link>}
        </div>}
    </section>;
}
