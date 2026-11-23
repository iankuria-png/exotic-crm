import { chromium } from 'playwright';
import { createGuard, truncateHtml, WorkerError } from './security.js';
import { createProxy } from './proxy.js';
import { detectBlocked } from './blockers.js';

export async function launchBrowser() {
  return chromium.launch({
    headless: true,
    args: [
      '--proxy-bypass-list=<-loopback>', '--disable-quic',
      '--force-webrtc-ip-handling-policy=disable_non_proxied_udp',
    ],
  });
}

export function createRenderer(browser, { guard = createGuard(), connect, blockImages = true } = {}) {
  return async (input) => {
    const start = Date.now();
    let ssrfBlocked = false;
    let proxy;
    let context;
    let deadline;
    let expired = false;
    const render = async () => {
      await guard(input.url);
      if (expired) return;
      proxy = await createProxy(guard, { connect, onBlocked: () => { ssrfBlocked = true; } });
      if (expired) return;
      context = await browser.newContext({
        userAgent: input.user_agent,
        proxy: { server: proxy.url },
        serviceWorkers: 'block', acceptDownloads: false,
        viewport: { width: 1280, height: 900 },
      });
      if (expired) return;
      await context.addCookies(input.cookies);
      await context.routeWebSocket('**/*', (route) => route.close());
      let stopped = false;
      let navigationCount = 0;
      const origin = new URL(input.url).origin;
      await context.route('**/*', async (route) => {
        const request = route.request();
        if (stopped || expired) { await route.abort().catch(() => {}); return; }
        try {
          await guard(request.url());
          // Bound redirects and disallow popups or extra top-level page navigations.
          if (request.isNavigationRequest() && request.frame().parentFrame() === null) {
            navigationCount++;
            if (navigationCount > 6 || request.frame().page() !== page) {
              await route.abort();
              return;
            }
          }
          if (['media', 'font', ...(blockImages ? ['image'] : [])].includes(request.resourceType())) {
            await route.abort();
            return;
          }
          // Custom secrets stay on the initial origin, including through redirects.
          const headers = { ...request.headers() };
          if (new URL(request.url()).origin === origin) {
            for (const [key, value] of Object.entries(input.headers)) headers[key.toLowerCase()] = value;
          }
          headers['user-agent'] = input.user_agent;
          // Fulfill one hop at a time: Playwright's continue() header overrides
          // otherwise follow redirects, and redirect interception is not guaranteed.
          const reply = await route.fetch({
            headers, maxRedirects: 0, maxRetries: 0,
            timeout: Math.max(1, input.timeout_ms - (Date.now() - start)),
          });
          await route.fulfill({ response: reply });
        } catch (error) {
          if (error.code === 'ssrf_blocked') ssrfBlocked = true;
          await route.abort().catch(() => {});
        }
      });
      const page = await context.newPage();
      page.on('dialog', (dialog) => dialog.dismiss().catch(() => {}));
      context.on('page', (popup) => { if (popup !== page) popup.close().catch(() => {}); });
      page.setDefaultTimeout(input.timeout_ms);
      const response = await page.goto(input.url, { waitUntil: 'domcontentloaded', timeout: input.timeout_ms });
      const status = response?.status() || 0;
      const headers = response ? await response.allHeaders() : {};
      let blocked = null;
      let frozen = false;
      const inspect = async () => {
        if (ssrfBlocked) throw new WorkerError('ssrf_blocked', 403);
        blocked = detectBlocked(await page.content(), status, headers);
        if (blocked && !frozen) {
          stopped = true;
          frozen = true;
          const session = await context.newCDPSession(page);
          await session.send('Page.stopLoading');
          await session.send('Emulation.setScriptExecutionDisabled', { value: true });
          await session.detach();
        }
        return blocked;
      };
      // Poll during selector/load waits so challenge pages stop without retrying.
      const wait = async (operation) => {
        let complete = false;
        let failure;
        const pending = operation.then(() => { complete = true; }, (error) => { failure = error; complete = true; });
        while (!complete && !blocked) {
          await new Promise((resolve) => setTimeout(resolve, 100));
          if (expired) throw new WorkerError('timeout', 504);
          await inspect();
        }
        if (!blocked) { await pending; if (failure) throw failure; }
      };
      await inspect();
      if (!blocked && input.wait_until !== 'domcontentloaded') {
        await wait(page.waitForLoadState(input.wait_until));
      }
      if (!blocked && input.wait_for_selector) await wait(page.waitForSelector(input.wait_for_selector));
      for (let step = 0; step < input.scroll_steps && !blocked; step++) {
        await page.evaluate(() => window.scrollBy(0, window.innerHeight));
        await new Promise((resolve) => setTimeout(resolve, 250));
        await inspect();
      }
      await inspect();
      const content = truncateHtml(await page.content());
      const screenshot = input.screenshot ? (await page.screenshot({ type: 'jpeg', quality: 65 })).toString('base64') : null;
      return {
        ok: true, final_url: page.url(), status, title: await page.title(), ...content,
        elapsed_ms: Date.now() - start, blocked, screenshot_jpeg_base64: screenshot,
      };
    };
    // One wall-clock budget includes context setup, all waits, scrolling and capture.
    const pending = render();
    try {
      return await Promise.race([
        pending,
        new Promise((_, reject) => {
          deadline = setTimeout(() => {
            expired = true;
            reject(new WorkerError('timeout', 504));
          }, Math.max(1, input.timeout_ms - (Date.now() - start)));
        }),
      ]);
    } catch (error) {
      if (ssrfBlocked) throw new WorkerError('ssrf_blocked', 403);
      if (error instanceof WorkerError) throw error;
      throw new WorkerError(error.name === 'TimeoutError' ? 'timeout' : 'navigation_failed', error.name === 'TimeoutError' ? 504 : 502);
    } finally {
      expired = true;
      clearTimeout(deadline);
      await context?.close().catch(() => {});
      // Late setup must also close if creation raced the deadline.
      pending.finally(async () => {
        await context?.close().catch(() => {});
        await proxy?.close();
      }).catch(() => {});
      await proxy?.close();
    }
  };
}
