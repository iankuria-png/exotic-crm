export class IdempotencyStore {
  constructor(ttlMs = 24 * 60 * 60 * 1000) {
    this.ttlMs = ttlMs;
    this.records = new Map();
  }

  get(key) {
    const record = this.records.get(key);
    if (!record) return null;
    if (record.expiresAt <= Date.now()) {
      this.records.delete(key);
      return null;
    }
    return record.value;
  }

  putIfAbsent(key, value) {
    const existing = this.get(key);
    if (existing) return { inserted: false, value: existing };

    const record = {
      value,
      expiresAt: Date.now() + this.ttlMs,
    };
    this.records.set(key, record);

    return { inserted: true, value };
  }

  update(key, patch) {
    const current = this.get(key);
    if (!current) return null;
    const value = { ...current, ...patch };
    this.records.set(key, {
      value,
      expiresAt: Date.now() + this.ttlMs,
    });
    return value;
  }

  prune() {
    for (const key of this.records.keys()) {
      this.get(key);
    }
  }
}
