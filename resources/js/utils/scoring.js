import { normalizePhone } from './phone';

export function candidateScore(payment, candidate) {
    let score = 45;
    const phonePrefix = payment?.platform?.phone_prefix || candidate?.platform?.phone_prefix || '254';
    const paymentPhone = normalizePhone(payment?.phone, phonePrefix);
    const candidatePhone = normalizePhone(candidate?.phone_normalized, phonePrefix);

    if (paymentPhone && candidatePhone && paymentPhone === candidatePhone) {
        score = 85;
    }

    if (candidate?.profile_status === 'publish') {
        score += 8;
    }

    if (candidate?.verified) {
        score += 7;
    }

    return Math.min(99, score);
}

export function scoreTone(score) {
    if (score >= 85) return 'high';
    if (score >= 65) return 'medium';
    return 'low';
}

export function toneClasses(tone) {
    if (tone === 'high') {
        return 'bg-emerald-50 text-emerald-700 ring-emerald-200';
    }
    if (tone === 'medium') {
        return 'bg-amber-50 text-amber-700 ring-amber-200';
    }
    return 'bg-slate-100 text-slate-600 ring-slate-200';
}
