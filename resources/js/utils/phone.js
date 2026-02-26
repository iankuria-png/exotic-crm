export function normalizePhone(phone, prefix = '254') {
    if (!phone) return '';

    const cleaned = String(phone).replace(/[^\d+]/g, '').replace(/^\+/, '');
    if (!cleaned) return '';

    const normalizedPrefix = String(prefix || '254').replace(/\D/g, '') || '254';
    if (cleaned.startsWith('0')) return `${normalizedPrefix}${cleaned.slice(1)}`;

    return cleaned;
}
