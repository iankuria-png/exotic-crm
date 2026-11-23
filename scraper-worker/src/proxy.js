import http from 'node:http';
import net from 'node:net';
import { once } from 'node:events';

// Private to the worker process. Validate again at connection time and connect to
// that exact numeric IP: Chromium never resolves the destination a second time.
export async function createProxy(guard, { connect = (options) => net.connect(options), onBlocked = () => {} } = {}) {
  const sockets = new Set();
  let closed = false;
  const track = (socket) => {
    sockets.add(socket);
    socket.on('error', () => {});
    socket.once('close', () => sockets.delete(socket));
    socket.setTimeout(45000, () => socket.destroy());
    if (closed) socket.destroy();
    return socket;
  };
  const agent = new http.Agent({ keepAlive: false });
  agent.createConnection = (options) => track(connect({ host: options.host, port: options.port }));
  const reject = (error) => { if (error.code === 'ssrf_blocked') onBlocked(); };
  const server = http.createServer(async (request, response) => {
    let upstream;
    try {
      const { url, address } = await guard(request.url);
      if (closed || response.destroyed) return;
      if (url.protocol !== 'http:') throw new Error('CONNECT required');
      const headers = { ...request.headers, host: url.host, connection: 'close' };
      for (const key of Object.keys(headers)) if (key.startsWith('proxy-')) delete headers[key];
      upstream = http.request({
        hostname: address, port: Number(url.port || 80), method: request.method,
        path: url.pathname + url.search, headers, agent,
      }, (reply) => {
        response.writeHead(reply.statusCode, reply.headers);
        reply.pipe(response);
      });
      upstream.on('error', () => {
        if (!response.headersSent) response.writeHead(502);
        response.end();
      });
      upstream.setTimeout(45000, () => upstream.destroy());
      response.on('close', () => upstream.destroy());
      request.pipe(upstream);
    } catch (error) {
      reject(error);
      response.writeHead(error.code === 'ssrf_blocked' ? 403 : 502).end();
    }
  });
  server.on('connection', track);
  server.on('connect', async (request, client, head) => {
    try {
      const { url, address } = await guard(`https://${request.url}`);
      if (closed || client.destroyed) return;
      const upstream = track(connect({ host: address, port: Number(url.port || 443) }));
      client.once('close', () => upstream.destroy());
      upstream.once('error', () => client.destroy());
      await once(upstream, 'connect');
      if (client.destroyed) { upstream.destroy(); return; }
      client.write('HTTP/1.1 200 Connection Established\r\n\r\n');
      if (head.length) upstream.write(head);
      client.pipe(upstream).pipe(client);
    } catch (error) {
      reject(error);
      if (!client.destroyed) client.end('HTTP/1.1 403 Forbidden\r\nConnection: close\r\n\r\n');
    }
  });
  server.on('upgrade', (request, socket) => socket.destroy());
  server.listen(0, '127.0.0.1');
  await once(server, 'listening');
  return {
    url: `http://127.0.0.1:${server.address().port}`,
    async close() {
      if (closed) return;
      closed = true;
      agent.destroy();
      for (const socket of sockets) socket.destroy();
      await new Promise((resolve) => server.close(resolve));
    },
  };
}
