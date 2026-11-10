#!/usr/bin/env python3
"""Aggregate the fixed registered SELECT-only snapshot. Never connects to production."""
import collections
import datetime as dt
from decimal import Decimal
import hashlib
import json
from pathlib import Path
import sys

source = Path(sys.argv[1])
j = json.loads(source.read_text())
minor = lambda value: int(Decimal(str(value)) * 100)
when = lambda value: dt.datetime.fromisoformat(value.replace('Z', '+00:00')).replace(tzinfo=dt.timezone.utc)
now = when(j['generated_utc'])
start = when(j['account']['policy']['enabled_from'])
receipts = {r['id']: r for r in j['receipts']}
deals = {d['id']: d for d in j['deals']}
prices = {p['price_id']: p for p in j['catalogue']}
events = collections.defaultdict(list)
for e in j['activation_events']:
    if e['actor_id'] and e['content'].get('execution') != 'automatic' and e['content'].get('payment_id'):
        events[e['content']['payment_id']].append(e)
sales = {s['receipt_id']: s for s in j['sales_sessions']}
sale_items = collections.defaultdict(list)
for item in j['sales_items']: sale_items[item['receipt_id']].append(item)
def single_confirmed_sale(rid, payment_id):
    sale = sales.get(rid)
    items = sale_items[rid]
    return bool(sale and sale['state'] == 'confirmed' and sale['confirmed_at'] and sale['remainder_minor'] == 0 and sale['allocated_minor'] == sale['principal_minor'] and len(items) == 1 and items[0]['payment_id'] == payment_id and items[0]['state'] == 'confirmed' and items[0]['verified_at'] and items[0]['amount_minor'] == sale['principal_minor'])
labels, rejects = [], []
for p in j['payments']:
    d = deals.get(p['deal_id'])
    for rid in p['receipt_ids']:
        r = receipts[rid]
        reasons = []
        if p['platform_id'] != 6: reasons.append('outside_primary_market')
        if r['has_conflicts'] or r['funds_state'] not in ['observed', 'statement_verified']: reasons.append('receipt_conflict_or_funds')
        if minor(p['amount']) != r['principal_minor'] or p['currency'] != r['currency']: reasons.append('receipt_payment_amount_or_currency_mismatch')
        if p['status'] not in ['completed', 'expired'] or p['test_mode'] or p['record_classification'] not in [None, 'live'] or p['provider_environment'] == 'sandbox': reasons.append('not_live_successful_cash')
        if ((p['manual_payment_bundle_id'] or p['reference_sequence']) and not single_confirmed_sale(rid, p['id'])) or p['has_other_collection_owner']: reasons.append('split_or_other_owner')
        if not d or d['payment_id'] != p['id'] or d['client_id'] != p['client_id'] or d['platform_id'] != p['platform_id'] or not d['activated_at'] or d['is_free_trial']: reasons.append('deal_binding_or_activation')
        if not any(e['platform_id'] == p['platform_id'] and e['entity_id'] == p['deal_id'] for e in events[p['id']]): reasons.append('no_exact_staff_activation_event')
        if reasons:
            rejects.append({'receipt_id': rid, 'reasons': reasons})
            continue
        labels.append({'receipt_id': rid, 'client_id': p['client_id'], 'payer_group': r['payer_group'], 'amount_minor': r['principal_minor'], 'currency': r['currency'], 'tier': d['plan_type'], 'duration_days': d['duration_days'], 'price_id': d['product_price_id']})
assert len(labels) == len({l['receipt_id'] for l in labels}), 'Receipt has multiple claimed payments: manual review required'
amounts = []
for amount in sorted({l['amount_minor'] for l in labels}):
    group = [l for l in labels if l['amount_minor'] == amount]
    counts = collections.Counter((l['tier'], l['duration_days'], l['price_id']) for l in group)
    amounts.append({'amount_minor': amount, 'receipts': len(group), 'clients': len({l['client_id'] for l in group}), 'packages': [{'tier': t, 'days': d, 'price_id': p, 'count': n} for (t, d, p), n in counts.most_common()]})
payers = collections.defaultdict(list)
for l in labels:
    if l['payer_group']: payers[l['payer_group']].append(l)
client_groups = collections.defaultdict(list)
for l in labels: client_groups[l['client_id'], l['currency'], l['amount_minor']].append(l)

# Context only: these older CRM rows have staff activation evidence, but usually lack wallet provenance.
history_events = collections.defaultdict(list)
for e in j['history_staff_events']:
    if e['actor_id'] and e['content'].get('execution') != 'automatic' and e['content'].get('payment_id'):
        history_events[e['content']['payment_id']].append(e)
history = []
for h in j['cohort_history']:
    if h['status'] not in ['completed', 'expired'] or h['record_classification'] not in [None, 'live'] or h['provider_environment'] == 'sandbox' or h['test_mode'] or h['has_other_collection_owner'] or h['manual_payment_bundle_id'] or h['reference_sequence']: continue
    if h['deal_payment_id'] != h['id'] or h['deal_client_id'] != h['client_id'] or h['deal_platform_id'] != 6 or not h['activated_at'] or h['is_free_trial'] or minor(h['amount']) != minor(h['deal_amount']) or h['currency'] != h['deal_currency'] or h['id'] not in history_events: continue
    exact_events = [e for e in history_events[h['id']] if e['platform_id'] == 6 and e['entity_id'] == h['deal_id']]
    if not exact_events: continue
    paid_at = min(when(e['content'].get('activated_at') or e['created_at']) for e in exact_events)
    if paid_at > now: continue
    history.append(dict(h, evidence_at=paid_at))
window = [h for h in history if h['evidence_at'] >= now-dt.timedelta(days=90)]
pairs = collections.defaultdict(list)
for h in window: pairs[h['client_id'], h['currency'], minor(h['amount'])].append(h)
repeated = [g for g in pairs.values() if len(g) >= 2]
strict_groups = []
for g in pairs.values():
    if len(g) < 3 or len({(h['plan_type'], h['duration_days'], h['product_price_id']) for h in g}) != 1: continue
    last = max(g, key=lambda h: h['evidence_at'])
    if last['evidence_at'] < now-dt.timedelta(days=45): continue
    price = prices.get(last['product_price_id'])
    if not price or not price['price_active'] or not price['product_active'] or price['is_archived'] or price['platform_id'] != 6 or price['tier'] not in ['basic','premium','vip'] or price['tier'] != last['plan_type'] or price['duration_days'] != last['duration_days'] or minor(price['price']) != minor(last['amount']) or price['currency'] != last['currency']: continue
    if any(h['discount_approved_by'] or h['discount_source'] or (h['discount_percentage'] is not None and Decimal(h['discount_percentage']) != 0) or (h['original_amount'] is not None and minor(h['original_amount']) != minor(h['amount'])) for h in g): continue
    strict_groups.append(g)
pilot = [r for r in receipts.values() if when(r['created_at']) >= start and not r['historical_only']]
reason_counts = collections.Counter()
for r in pilot:
    holds = [reason for reason in r['saved_reasons'] if reason != 'reviewed_payer_and_purchase_authority']
    reason_counts[holds[-1] if holds else 'no_saved_hold_staff_activated'] += 1
payments_by_receipt = {rid: p for p in j['payments'] for rid in p['receipt_ids']}
report = {
    'as_of_utc': j['generated_utc'], 'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(),
    'scope': {'captured_receipts': len(receipts), 'linked_cash_rows': len(j['payments']), 'exact_staff_confirmed_receipts': len(labels), 'unique_clients': len({l['client_id'] for l in labels}), 'receipt_sales_sessions': len(j['sales_sessions']), 'excluded': rejects},
    'amounts': amounts,
    'captured_repeat_client_amount_groups': sum(len(g)>1 for g in client_groups.values()),
    'payer_evidence': {'reviewed_links': len(j.get('payer_links', [])), 'confirmed_receipts_missing_payer': sum(l['payer_group'] is None for l in labels), 'known_payer_groups': len(payers), 'groups_with_multiple_clients': sum(len({l['client_id'] for l in g}) > 1 for g in payers.values()), 'conflicting_groups_receipts': sum(len(g) for g in payers.values() if len({l['client_id'] for l in g}) > 1)},
    'broader_history': {'scope': j['cohort_scope'], 'rows': len(j['cohort_history']), 'staff_verified_bound_paid_rows': len(history), 'last_90_day_rows': len(window), 'last_90_day_client_amount_groups': len(pairs), 'repeat_groups': len(repeated), 'repeat_semantic_consistent': sum(len({(h['plan_type'],h['duration_days']) for h in g}) == 1 for g in repeated), 'repeat_exact_price_consistent': sum(len({(h['plan_type'],h['duration_days'],h['product_price_id']) for h in g}) == 1 for g in repeated), 'strict_3_purchase_90_day_45_day_current_price_groups': len(strict_groups), 'strict_groups_with_all_explicit_wallet_provenance': sum(all(h['collection_account_verified'] for h in g) for g in strict_groups), 'strict_groups_receipts': sum(len(g) for g in strict_groups)},
    'pilot': {'new_receipts': len(pilot), 'automatic_allocations': len([a for a in j.get('allocations', []) if a['decision_source']=='automatic' and when(a['created_at'])>=start]), 'saved_holds': dict(reason_counts), 'rows': [{'receipt_id':r['id'],'amount_minor':r['principal_minor'],'saved_reasons':r['saved_reasons'],'saved_matching':r['saved_matching'],'ingest_latency_seconds':(when(r['first_ingested_at'])-when(r['provider_at'])).total_seconds(), 'staff_cash_now_linked':r['id'] in payments_by_receipt} for r in pilot]},
    'processing_backlog': j['outbox_details'],
    'current_policy': j['account']['policy'], 'subsidiary_trial_policy': j['subsidiary_trial_policy']
}
Path(sys.argv[2]).write_text(json.dumps(report, indent=2)+'\n')
print(json.dumps({k:v for k,v in report.items() if k not in ['current_policy','pilot','processing_backlog']},indent=2))
