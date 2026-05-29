export class SenderQueue {
  constructor(senderId) {
    this.senderId = senderId;
    this.items = [];
    this.inFlight = false;
    this.processed = 0;
    this.lastSendAt = null;
  }

  enqueue(item, handler) {
    this.items.push({ item, handler });
    this.drain();
  }

  depth() {
    return this.items.length;
  }

  drainFailed(errorCode = 'sender_banned') {
    const drained = this.items.splice(0);
    return drained.map(({ item }) => ({
      ...item,
      status: 'failed',
      error_code: errorCode,
    }));
  }

  async drain() {
    if (this.inFlight || this.items.length === 0) {
      return;
    }

    this.inFlight = true;
    const { item, handler } = this.items.shift();

    try {
      await handler(item);
      this.processed += 1;
      this.lastSendAt = new Date().toISOString();
    } finally {
      this.inFlight = false;
      this.drain();
    }
  }
}

export class SenderQueueRegistry {
  constructor() {
    this.queues = new Map();
  }

  queueFor(senderId) {
    const key = String(senderId);
    if (!this.queues.has(key)) {
      this.queues.set(key, new SenderQueue(key));
    }
    return this.queues.get(key);
  }

  metrics() {
    return Array.from(this.queues.values()).map((queue) => ({
      sender_id: Number(queue.senderId),
      queue_depth: queue.depth(),
      in_flight: queue.inFlight ? 1 : 0,
      processed: queue.processed,
      last_send_at: queue.lastSendAt,
    }));
  }
}
