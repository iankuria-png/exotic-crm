import { signBody } from './hmac.js';

export class LaravelClient {
  constructor({ baseUrl, secret }) {
    this.baseUrl = baseUrl ? baseUrl.replace(/\/+$/, '') : '';
    this.secret = secret;
  }

  configured() {
    return Boolean(this.baseUrl && this.secret);
  }

  async post(path, payload) {
    if (!this.configured()) {
      return { ok: false, status: 0, body: { message: 'Laravel webhook client is not configured.' } };
    }

    const body = JSON.stringify(payload);
    const response = await fetch(`${this.baseUrl}${path}`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-Signature': signBody(body, this.secret),
      },
      body,
    });

    let data = null;
    try {
      data = await response.json();
    } catch {
      data = { message: await response.text() };
    }

    return { ok: response.ok, status: response.status, body: data };
  }
}
