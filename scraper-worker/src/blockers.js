export function detectBlocked(html, status = 200, headers = {}) {
  if (headers['cf-mitigated'] === 'challenge'
      || /<title[^>]*>\s*(just a moment|attention required)/i.test(html)
      || /(?:id=["']challenge-(?:running|stage)|\/cdn-cgi\/challenge-platform|_cf_chl_opt)/i.test(html)) {
    return { type: 'cloudflare_challenge', detail: 'Cloudflare challenge detected; use human browser capture.' };
  }
  if (/(?:class=["'][^"']*(?:g-recaptcha|h-captcha)|challenges\.cloudflare\.com\/turnstile|verify (?:that )?you are (?:a )?human)/i.test(html)) {
    return { type: 'captcha', detail: 'Human verification detected; rendering stopped.' };
  }
  if (/<input\b[^>]*type\s*=\s*["']?password\b/i.test(html)
      && /(?:sign in|log in|login|authenticate)/i.test(html)) {
    return { type: 'login_wall', detail: 'A sign-in form blocks access.' };
  }
  if (status >= 400) return { type: 'http_error', detail: `The target returned HTTP ${status}.` };
  return null;
}
