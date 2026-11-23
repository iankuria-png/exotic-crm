import { createHmac, timingSafeEqual } from 'node:crypto';
import { lookup } from 'node:dns/promises';
import net from 'node:net';
import ipaddr from 'ipaddr.js';

export const BOT = 'ExoticCRMLeadBot/1.0 (+https://crm.exotic-online.com)';
export const HTML_LIMIT = 6 * 1024 * 1024;

export class WorkerError extends Error {
  constructor(code, status = 400) {
    super(code);
    this.code = code;
    this.status = status;
  }
}

export function authentic(raw, headers, secret, now = Date.now()) {
  const timestamp = headers['x-scraper-timestamp'];
  const signature = headers['x-scraper-signature'];
  if (typeof timestamp !== 'string' || !/^\d{1,12}$/.test(timestamp)
      || Math.abs(now / 1000 - Number(timestamp)) > 300
      || typeof signature !== 'string' || !/^[a-f\d]{64}$/i.test(signature)) return false;
  const expected = createHmac('sha256', secret).update(timestamp + '\n').update(raw).digest();
  return timingSafeEqual(expected, Buffer.from(signature, 'hex'));
}

export function publicIp(address) {
  try {
    const ip = ipaddr.process(address);
    if (ip.toString() === '168.63.129.16') return false; // Azure platform/metadata virtual IP.
    // ipaddr excludes private, loopback, CGNAT, link-local, multicast, metadata,
    // documentation/reserved and IPv6 transition/tunnel ranges from unicast.
    return ip.range() === 'unicast'
      && (ip.kind() !== 'ipv6' || ip.match(ipaddr.parse('2000::'), 3));
  } catch {
    return false;
  }
}

export function targetUrl(value) {
  let url;
  try { url = new URL(value); } catch { throw new WorkerError('bad_request'); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
    throw new WorkerError('ssrf_blocked', 403);
  }
  return url;
}

export function createGuard(resolve = (host) => lookup(host, { all: true, verbatim: true })) {
  return async (value) => {
    const url = targetUrl(value);
    const host = url.hostname.replace(/^\[|\]$/g, '');
    if (host.includes('%')) throw new WorkerError('ssrf_blocked', 403);
    let timer;
    let answers;
    try {
      answers = net.isIP(host) ? [{ address: host }] : await Promise.race([
        resolve(host),
        new Promise((_, reject) => {
          timer = setTimeout(() => reject(new WorkerError('navigation_failed', 502)), 5000);
        }),
      ]);
    } catch (error) {
      if (error instanceof WorkerError) throw error;
      throw new WorkerError('navigation_failed', 502);
    } finally { clearTimeout(timer); }
    if (!answers.length || answers.some(({ address }) => !publicIp(address))) {
      throw new WorkerError('ssrf_blocked', 403);
    }
    return { url, address: answers[0].address };
  };
}

export function validate(input) {
  if (!input || typeof input !== 'object' || Array.isArray(input) || typeof input.url !== 'string') {
    throw new WorkerError('bad_request');
  }
  const url = targetUrl(input.url);
  const body = {
    wait_until: 'domcontentloaded', wait_for_selector: null, timeout_ms: 30000,
    scroll_steps: 0, headers: {}, cookies: [], user_agent: BOT, screenshot: false, ...input,
  };
  if (!['domcontentloaded', 'load', 'networkidle'].includes(body.wait_until)
      || !Number.isInteger(body.timeout_ms) || body.timeout_ms < 1 || body.timeout_ms > 45000
      || !Number.isInteger(body.scroll_steps) || body.scroll_steps < 0 || body.scroll_steps > 5
      || (body.wait_for_selector !== null && (typeof body.wait_for_selector !== 'string' || body.wait_for_selector.length > 1024))
      || typeof body.user_agent !== 'string' || !body.user_agent.trim() || body.user_agent.length > 512
      || /[\r\n]/.test(body.user_agent) || typeof body.screenshot !== 'boolean'
      || !body.headers || typeof body.headers !== 'object' || Array.isArray(body.headers)
      || !Array.isArray(body.cookies) || body.cookies.length > 200) throw new WorkerError('bad_request');
  for (const [name, value] of Object.entries(body.headers)) {
    if (!/^[!#$%&'*+.^_`|~\da-z-]+$/i.test(name) || typeof value !== 'string' || /[\r\n]/.test(value)
        || /^(host|connection|content-length|transfer-encoding|upgrade|user-agent|proxy-.*|sec-.*)$/i.test(name)) {
      throw new WorkerError('bad_request');
    }
  }
  const host = url.hostname.replace(/^\[|\]$/g, '').toLowerCase();
  body.cookies = body.cookies.map((cookie) => {
    if (!cookie || typeof cookie.name !== 'string' || !cookie.name
        || typeof cookie.value !== 'string' || /[\r\n;]/.test(cookie.name + cookie.value)
        || (cookie.domain !== undefined && typeof cookie.domain !== 'string')) throw new WorkerError('bad_request');
    const domain = (cookie.domain || host).toLowerCase();
    if (domain.replace(/^\./, '') !== host) throw new WorkerError('bad_request');
    return { name: cookie.name, value: cookie.value, domain, path: '/' };
  });
  return body;
}

export function truncateHtml(html, limit = HTML_LIMIT) {
  const data = Buffer.from(html);
  const truncated = data.length > limit;
  let end = Math.min(data.length, limit);
  // Do not split a UTF-8 sequence or introduce a replacement character over the cap.
  if (truncated) while (end > 0 && (data[end] & 0xc0) === 0x80) end--;
  const result = data.subarray(0, end).toString('utf8');
  return { html: result, bytes: Buffer.byteLength(result), truncated };
}
