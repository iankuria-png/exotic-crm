import crypto from 'node:crypto';

export function signBody(body, secret, timestamp = Math.floor(Date.now() / 1000)) {
  if (!secret) {
    throw new Error('HMAC secret is not configured');
  }

  const payload = `${timestamp}.${body}`;
  const digest = crypto.createHmac('sha256', secret).update(payload).digest('hex');

  return `t=${timestamp},v1=${digest}`;
}

export function verifySignature(body, header, secrets, skewSeconds = 300, now = Math.floor(Date.now() / 1000)) {
  if (!header || !Array.isArray(secrets) || secrets.filter(Boolean).length === 0) {
    return false;
  }

  const parts = Object.fromEntries(
    String(header)
      .split(',')
      .map((part) => part.trim().split('='))
      .filter((part) => part.length === 2)
  );

  const timestamp = Number(parts.t);
  const signature = parts.v1;
  if (!Number.isFinite(timestamp) || !signature || Math.abs(now - timestamp) > skewSeconds) {
    return false;
  }

  return secrets.filter(Boolean).some((secret) => {
    const expected = signBody(body, secret, timestamp).split('v1=')[1];
    if (expected.length !== signature.length) {
      return false;
    }
    return crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signature));
  });
}
