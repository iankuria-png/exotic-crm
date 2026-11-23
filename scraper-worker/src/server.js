import http from 'node:http';
import { pathToFileURL } from 'node:url';
import { authentic, validate, WorkerError } from './security.js';
import { createRenderer, launchBrowser } from './renderer.js';

export const VERSION = '1.0.0';
const BODY_LIMIT = 256 * 1024;

export function createWorker({ secret, browser, render = createRenderer(browser), concurrency = 2, logger = console.log }) {
  if (typeof secret !== 'string' || secret.length < 32) throw new Error('Set SCRAPER_RENDER_WORKER_SECRET to at least 32 characters.');
  if (!Number.isInteger(concurrency) || concurrency < 1 || concurrency > 16) throw new Error('Invalid MAX_CONCURRENCY (1–16).');
  let inflight = 0;
  const send = (response, status, body) => {
    if (response.destroyed) return;
    response.writeHead(status, { 'Content-Type': 'application/json', 'Cache-Control': 'no-store' });
    response.end(JSON.stringify(body));
  };
  const server = http.createServer(async (request, response) => {
    if (request.method === 'GET' && request.url === '/healthz') {
      send(response, 200, { ok: browser.isConnected(), version: VERSION, browser_version: browser.version(), inflight });
      return;
    }
    if (request.method !== 'POST' || request.url !== '/v1/render') {
      send(response, 404, { ok: false, error: 'bad_request' });
      return;
    }
    let admitted = false;
    let host = null;
    let result;
    let status = 500;
    const start = Date.now();
    try {
      const chunks = [];
      let size = 0;
      for await (const chunk of request.iterator({ destroyOnReturn: false })) {
        size += chunk.length;
        if (size > BODY_LIMIT) throw new WorkerError('bad_request', 413);
        chunks.push(chunk);
      }
      const raw = Buffer.concat(chunks);
      if (!authentic(raw, request.headers, secret)) throw new WorkerError('bad_request', 401);
      if (!/^application\/json(?:\s*;|$)/i.test(request.headers['content-type'] || '')) throw new WorkerError('bad_request');
      let input;
      try { input = JSON.parse(raw.toString('utf8')); } catch { throw new WorkerError('bad_request'); }
      input = validate(input);
      host = new URL(input.url).hostname;
      if (inflight >= concurrency) throw new WorkerError('bad_request', 429);
      inflight++;
      admitted = true;
      result = await render(input);
      status = 200;
      send(response, status, result);
    } catch (error) {
      request.resume();
      status = error instanceof WorkerError ? error.status : 502;
      send(response, status, { ok: false, error: error instanceof WorkerError ? error.code : 'navigation_failed' });
    } finally {
      if (admitted) inflight--;
      logger(JSON.stringify({ host, status, ms: Date.now() - start, blocked: result?.blocked?.type || null }));
    }
  });
  server.requestTimeout = 10000;
  server.headersTimeout = 10000;
  server.timeout = 60000;
  return server;
}

async function main() {
  try { process.loadEnvFile('.env'); } catch (error) { if (error.code !== 'ENOENT') throw error; }
  const secret = process.env.SCRAPER_RENDER_WORKER_SECRET;
  if (!secret || secret.length < 32) throw new Error('Set SCRAPER_RENDER_WORKER_SECRET to at least 32 characters.');
  const browser = await launchBrowser();
  let server;
  try {
    server = createWorker({
      secret, browser, concurrency: Number(process.env.MAX_CONCURRENCY || 2),
      render: createRenderer(browser, { blockImages: process.env.BLOCK_IMAGES !== 'false' }),
    });
    server.listen(Number(process.env.PORT || 3001), process.env.HOST || '127.0.0.1');
    await new Promise((resolve, reject) => { server.once('listening', resolve); server.once('error', reject); });
  } catch (error) { await browser.close(); throw error; }
  let closing = false;
  const shutdown = async () => {
    if (closing) return;
    closing = true;
    await new Promise((resolve) => server.close(resolve));
    await browser.close();
  };
  process.on('SIGINT', shutdown);
  process.on('SIGTERM', shutdown);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch(() => { console.error('Render worker startup failed; check secret, port and Chromium installation.'); process.exitCode = 1; });
}
