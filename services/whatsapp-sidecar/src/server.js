import http from 'node:http';
import { randomUUID } from 'node:crypto';
import { IdempotencyStore } from './idempotency-store.js';
import { LaravelClient } from './laravel-client.js';
import { SenderQueueRegistry } from './sender-queue.js';
import { verifySignature } from './hmac.js';

const config = {
  port: Number(process.env.PORT || process.env.WHATSAPP_SIDECAR_PORT || 4080),
  laravelUrl: process.env.LARAVEL_BASE_URL || process.env.CRM_BASE_URL || '',
  inboundSecret: process.env.WHATSAPP_SIDECAR_HMAC_SECRET || '',
  inboundPreviousSecret: process.env.WHATSAPP_SIDECAR_HMAC_SECRET_PREVIOUS || '',
  outboundSecret: process.env.WHATSAPP_SIDECAR_LARAVEL_HMAC_SECRET || process.env.WHATSAPP_SIDECAR_HMAC_SECRET || '',
  clockSkewSeconds: Number(process.env.WHATSAPP_SIDECAR_CLOCK_SKEW_SECONDS || 300),
  idempotencyTtlMs: Number(process.env.WHATSAPP_SIDECAR_IDEMPOTENCY_TTL_MS || 24 * 60 * 60 * 1000),
};

const queues = new SenderQueueRegistry();
const idempotency = new IdempotencyStore(config.idempotencyTtlMs);
const laravel = new LaravelClient({ baseUrl: config.laravelUrl, secret: config.outboundSecret });

const state = {
  startedAt: new Date().toISOString(),
  restore: {
    state: 'not_started',
    started_at: null,
    completed_at: null,
    senders_expected: 0,
    senders_resolved: 0,
    failures: 0,
  },
  senders: new Map(),
  authState: new Map(),
};

function json(res, status, payload) {
  const body = JSON.stringify(payload);
  res.writeHead(status, {
    'Content-Type': 'application/json',
    'Content-Length': Buffer.byteLength(body),
  });
  res.end(body);
}

async function readBody(req) {
  const chunks = [];
  for await (const chunk of req) {
    chunks.push(chunk);
  }
  return Buffer.concat(chunks).toString('utf8');
}

function requireHmac(req, body) {
  return verifySignature(
    body,
    req.headers['x-signature'],
    [config.inboundSecret, config.inboundPreviousSecret],
    config.clockSkewSeconds
  );
}

async function handleMessages(req, res, body) {
  if (!requireHmac(req, body)) {
    return json(res, 401, { message: 'Invalid signature.' });
  }

  if (state.restore.state === 'restoring') {
    res.setHeader('Retry-After', '15');
    return json(res, 503, { message: 'Sidecar is restoring sessions.', restore: state.restore });
  }

  const payload = JSON.parse(body || '{}');
  const senderId = Number(payload.senderId);
  const attemptUuid = String(payload.attemptUuid || req.headers['idempotency-key'] || '');
  const to = String(payload.to || '');
  const message = String(payload.body || '');

  if (!senderId || !attemptUuid || !to || !message) {
    return json(res, 422, { message: 'senderId, attemptUuid, to, and body are required.' });
  }

  const cached = idempotency.putIfAbsent(attemptUuid, {
    status: 'in_flight',
    attemptUuid,
    senderId,
    acceptedAt: new Date().toISOString(),
  });

  if (!cached.inserted) {
    return json(res, 202, cached.value);
  }

  queues.queueFor(senderId).enqueue(
    {
      senderId,
      attemptUuid,
      to,
      message,
      mediaUrl: payload.mediaUrl || null,
      messageType: payload.messageType || 'transactional',
    },
    async (item) => {
      const providerMessageId = `baileys.local.${randomUUID()}`;
      idempotency.update(item.attemptUuid, {
        status: 'sent',
        messageId: providerMessageId,
        sentAt: new Date().toISOString(),
      });

      await laravel.post('/api/crm/messaging/webhook/baileys', {
        event: 'message.status',
        event_id: `message.status:${item.attemptUuid}:sent`,
        sender_id: item.senderId,
        attempt_uuid: item.attemptUuid,
        message_id: providerMessageId,
        to: item.to,
        status: 'sent',
      });
    }
  );

  return json(res, 202, cached.value);
}

async function handleRestore(req, res, body) {
  if (!requireHmac(req, body)) {
    return json(res, 401, { message: 'Invalid signature.' });
  }

  state.restore = {
    state: 'restoring',
    started_at: new Date().toISOString(),
    completed_at: null,
    senders_expected: 0,
    senders_resolved: 0,
    failures: 0,
  };

  return json(res, 202, state.restore);
}

function handleHealth(res) {
  const healthy = state.restore.state !== 'restoring';
  json(res, healthy ? 200 : 503, {
    ok: healthy,
    service: 'exotic-whatsapp-sidecar',
    started_at: state.startedAt,
    clock: new Date().toISOString(),
    restore: state.restore,
  });
}

function handleMetrics(res) {
  json(res, 200, {
    restore: state.restore,
    queues: queues.metrics(),
    auth_state_count: state.authState.size,
  });
}

export function createServer() {
  return http.createServer(async (req, res) => {
    try {
      if (req.method === 'GET' && req.url === '/healthz') {
        return handleHealth(res);
      }

      if (req.method === 'GET' && req.url === '/metrics') {
        return handleMetrics(res);
      }

      const body = await readBody(req);

      if (req.method === 'POST' && req.url === '/messages') {
        return handleMessages(req, res, body);
      }

      if (req.method === 'POST' && req.url === '/restore-window') {
        return handleRestore(req, res, body);
      }

      return json(res, 404, { message: 'Not found.' });
    } catch (error) {
      return json(res, 500, { message: error.message || 'Sidecar error.' });
    }
  });
}

if (process.argv[1] === new URL(import.meta.url).pathname) {
  createServer().listen(config.port, () => {
    console.log(`Exotic WhatsApp sidecar listening on :${config.port}`);
  });
}
