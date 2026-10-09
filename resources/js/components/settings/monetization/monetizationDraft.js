export const draftFields = ['enabled', 'rollout_mode', 'premium_access_environment', 'currency', 'activation_kill_switch', 'checkout_kill_switch', 'test_client_ids', 'offer_policy', 'surface_policy', 'checkout_policy', 'delivery_policy', 'prices', 'expiry_video_policy', 'free_pass_policy', 'teaser_policy'];
export function editableSettings(form) { return Object.fromEntries(draftFields.map(key => [key, form[key]])); }
export function marketForm(s, automation = {}) {
    return { ...s, premium_access_environment: s.premium_access_environment || 'sandbox', offer_policy: Object.fromEntries(Object.entries(s.offer_policy_json).map(([key, value]) => [key, typeof value === 'boolean' ? value : Number(value)])), surface_policy: s.surface_policy_json, checkout_policy: s.checkout_policy_json, delivery_policy: s.delivery_policy_json, expiry_video_policy: automation?.expiry, free_pass_policy: automation?.free_pass, teaser_policy: automation?.teaser, prices: s.prices.length ? s.prices : [{ duration_key: '2_weeks', duration_label: '2 Weeks', price: '300', subsidy_mode: 'percentage', subsidy_value: '20', is_active: true }, { duration_key: '1_month', duration_label: '1 Month', price: '500', subsidy_mode: 'fixed', subsidy_value: '100', is_active: true }] };
}
export function readDraft(key) {
    try { const value = JSON.parse(sessionStorage.getItem(key)); return value && Date.now() - value.at < 86400000 ? value : null; } catch { return null; }
}
export function writeDraft(key, form, reason, outcome = '') {
    try { sessionStorage.setItem(key, JSON.stringify({ revision: form.config_revision, form: editableSettings(form), reason, outcome, at: Date.now() })); return true; } catch { return false; }
}
