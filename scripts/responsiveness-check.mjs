import { spawn } from 'node:child_process';
import { existsSync, mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const TARGET_URL = process.argv[2] || 'http://127.0.0.1:8789/';
const CHROME_CANDIDATES = [
  process.env.CHROME_PATH,
  'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
  'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
  'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
].filter(Boolean);

const VIEWPORTS = [
  { name: 'small-mobile', width: 360, height: 800, mobile: true, scale: 2 },
  { name: 'iphone', width: 390, height: 844, mobile: true, scale: 2 },
  { name: 'large-mobile', width: 430, height: 932, mobile: true, scale: 2 },
  { name: 'tablet', width: 768, height: 1024, mobile: true, scale: 2 },
  { name: 'small-laptop', width: 1024, height: 768, mobile: false, scale: 1 },
  { name: 'desktop', width: 1280, height: 800, mobile: false, scale: 1 },
  { name: 'wide', width: 1440, height: 900, mobile: false, scale: 1 },
];

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function findChrome() {
  const found = CHROME_CANDIDATES.find((path) => existsSync(path));

  if (!found) {
    throw new Error('Chrome or Edge was not found. Set CHROME_PATH to run this audit.');
  }

  return found;
}

async function fetchJson(url, timeoutMs = 10000) {
  const deadline = Date.now() + timeoutMs;
  let lastError;

  while (Date.now() < deadline) {
    try {
      const response = await fetch(url);
      if (response.ok) return await response.json();
      lastError = new Error(`${response.status} ${response.statusText}`);
    } catch (error) {
      lastError = error;
    }

    await sleep(120);
  }

  throw lastError || new Error(`Timed out fetching ${url}`);
}

class CdpClient {
  constructor(socket) {
    this.socket = socket;
    this.nextId = 1;
    this.pending = new Map();
    this.waiters = [];

    socket.addEventListener('message', (event) => {
      const message = JSON.parse(event.data);

      if (message.id && this.pending.has(message.id)) {
        const { resolve, reject } = this.pending.get(message.id);
        this.pending.delete(message.id);

        if (message.error) reject(new Error(message.error.message || JSON.stringify(message.error)));
        else resolve(message.result || {});
        return;
      }

      this.waiters = this.waiters.filter((waiter) => {
        if (waiter.method === message.method && (!waiter.sessionId || waiter.sessionId === message.sessionId)) {
          clearTimeout(waiter.timer);
          waiter.resolve(message);
          return false;
        }

        return true;
      });
    });
  }

  send(method, params = {}, sessionId = undefined) {
    const id = this.nextId++;
    const payload = { id, method, params };
    if (sessionId) payload.sessionId = sessionId;

    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      this.socket.send(JSON.stringify(payload));
    });
  }

  waitFor(method, sessionId, timeoutMs = 10000) {
    return new Promise((resolve, reject) => {
      const timer = setTimeout(() => {
        this.waiters = this.waiters.filter((waiter) => waiter.resolve !== resolve);
        reject(new Error(`Timed out waiting for ${method}`));
      }, timeoutMs);

      this.waiters.push({ method, sessionId, resolve, reject, timer });
    });
  }

  close() {
    this.socket.close();
  }
}

async function connectChrome() {
  const chromePath = findChrome();
  const port = 9300 + Math.floor(Math.random() * 500);
  const profileDir = mkdtempSync(join(tmpdir(), 'mdp-chrome-'));
  const chrome = spawn(chromePath, [
    '--headless=new',
    `--remote-debugging-port=${port}`,
    `--user-data-dir=${profileDir}`,
    '--disable-gpu',
    '--disable-extensions',
    '--no-first-run',
    '--no-default-browser-check',
    'about:blank',
  ], {
    stdio: 'ignore',
    windowsHide: true,
  });

  const version = await fetchJson(`http://127.0.0.1:${port}/json/version`);
  const socket = new WebSocket(version.webSocketDebuggerUrl);
  await new Promise((resolve, reject) => {
    socket.addEventListener('open', resolve, { once: true });
    socket.addEventListener('error', reject, { once: true });
  });

  const cdp = new CdpClient(socket);
  const target = await cdp.send('Target.createTarget', { url: 'about:blank' });
  const attached = await cdp.send('Target.attachToTarget', { targetId: target.targetId, flatten: true });

  const cleanup = () => {
    try { cdp.close(); } catch {}
    try { chrome.kill(); } catch {}
    try { rmSync(profileDir, { recursive: true, force: true }); } catch {}
  };

  return { cdp, sessionId: attached.sessionId, cleanup };
}

async function evaluate(cdp, sessionId, expression) {
  const result = await cdp.send('Runtime.evaluate', {
    expression,
    awaitPromise: true,
    returnByValue: true,
  }, sessionId);

  if (result.exceptionDetails) {
    throw new Error(result.exceptionDetails.text || 'Runtime evaluation failed');
  }

  return result.result?.value;
}

async function loadViewport(cdp, sessionId, viewport) {
  await cdp.send('Emulation.setDeviceMetricsOverride', {
    width: viewport.width,
    height: viewport.height,
    deviceScaleFactor: viewport.scale,
    mobile: viewport.mobile,
  }, sessionId);
  await cdp.send('Page.enable', {}, sessionId);
  await cdp.send('Runtime.enable', {}, sessionId);
  await cdp.send('Network.enable', {}, sessionId);

  const loadEvent = cdp.waitFor('Page.loadEventFired', sessionId, 15000).catch(() => null);
  await cdp.send('Page.navigate', { url: `${TARGET_URL}${TARGET_URL.includes('?') ? '&' : '?'}audit=${viewport.name}` }, sessionId);
  await loadEvent;
  await evaluate(cdp, sessionId, `new Promise((resolve) => {
    const done = () => setTimeout(resolve, 650);
    if (document.readyState === 'complete') done();
    else window.addEventListener('load', done, { once: true });
  })`);
}

const layoutProbe = `(() => {
  const cards = [...document.querySelectorAll('[data-product-card]')];
  const images = [...document.querySelectorAll('.product-image img')];
  const visibleCards = cards.filter((card) => !card.hidden);
  const offenders = [...document.body.querySelectorAll('*')]
    .filter((el) => {
      if (el.hidden || getComputedStyle(el).display === 'none') return false;
      const rect = el.getBoundingClientRect();
      if (rect.width < 1 || rect.height < 1) return false;
      return rect.left < -2 || rect.right > window.innerWidth + 2;
    })
    .slice(0, 12)
    .map((el) => {
      const rect = el.getBoundingClientRect();
      return {
        tag: el.tagName.toLowerCase(),
        id: el.id || '',
        className: String(el.className || '').slice(0, 90),
        left: Math.round(rect.left),
        right: Math.round(rect.right),
        width: Math.round(rect.width),
      };
    });
  const sampleCards = cards.slice(0, 12).map((card) => {
    const image = card.querySelector('.product-image');
    const img = card.querySelector('img');
    const title = card.querySelector('h3');
    const footer = card.querySelector('.product-footer');
    const imageRect = image.getBoundingClientRect();
    const imgRect = img.getBoundingClientRect();
    const titleRect = title.getBoundingClientRect();
    const footerRect = footer.getBoundingClientRect();

    return {
      id: card.id,
      overflow: card.scrollWidth > card.clientWidth + 1,
      imgWidth: Math.round(imgRect.width),
      imgHeight: Math.round(imgRect.height),
      mediaWidth: Math.round(imageRect.width),
      mediaHeight: Math.round(imageRect.height),
      mediaTitleGap: Math.round(titleRect.top - imageRect.bottom),
      footerVisible: footerRect.top < window.innerHeight || footerRect.bottom <= document.documentElement.scrollHeight,
    };
  });
  const firstRowTop = cards[0]?.getBoundingClientRect().top ?? 0;
  const columns = new Set(cards
    .filter((card) => Math.abs(card.getBoundingClientRect().top - firstRowTop) < 5)
    .map((card) => Math.round(card.getBoundingClientRect().left))).size;
  const categorySelect = document.querySelector('[data-category]');

  return {
    url: location.href,
    viewport: { width: window.innerWidth, height: window.innerHeight },
    documentScrollWidth: document.documentElement.scrollWidth,
    horizontalOverflow: document.documentElement.scrollWidth > window.innerWidth + 2,
    offenders,
    products: cards.length,
    visibleProducts: visibleCards.length,
    productColumns: columns,
    logoImages: images.filter((img) => img.getAttribute('src')?.includes('/product-logos/')).length,
    nonLogoImages: images.filter((img) => !img.getAttribute('src')?.includes('/product-logos/')).map((img) => img.getAttribute('src')).slice(0, 3),
    unloadedImages: images.filter((img) => !img.complete || img.naturalWidth === 0).map((img) => img.getAttribute('src')).slice(0, 3),
    categoryOptions: categorySelect ? categorySelect.options.length - 1 : 0,
    topbarHeight: Math.round(document.querySelector('.topbar')?.getBoundingClientRect().height || 0),
    cartButtonVisible: !!document.querySelector('[data-open-cart]')?.offsetParent,
    oldLimitCopy: document.body.innerText.includes('enterprise scaling to 200,000 users'),
    sampleCards,
  };
})()`;

const filterProbe = `(() => {
  const select = document.querySelector('[data-category]');
  if (!select) return { ok: false, message: 'Category select missing' };
  select.value = 'AI Tools';
  select.dispatchEvent(new Event('change', { bubbles: true }));
  const visible = [...document.querySelectorAll('[data-product-card]')]
    .filter((card) => !card.hidden)
    .map((card) => card.id);
  return {
    ok: visible.length > 0 && visible.every((id) => document.getElementById(id).dataset.category === 'AI Tools'),
    visibleCount: visible.length,
    sample: visible.slice(0, 5),
  };
})()`;

const cartSetupProbe = `(() => {
  localStorage.setItem('mdp_cart_v2', JSON.stringify([
    { id: 'openai-ecosystem', quantity: 1 },
    { id: 'google-maps-api-platform', quantity: 1, users: 400 },
    { id: 'mnotify-sms-gateway-prepaid-bulk', quantity: 1, users: 200000 },
    { id: 'stripe-billing-connector', quantity: 2 }
  ]));
  location.reload();
})()`;

const cartProbe = `(() => {
  document.querySelector('[data-open-cart]')?.click();
  const drawer = document.querySelector('[data-cart-drawer]');
  const panel = drawer?.querySelector('.cart-panel');
  const panelRect = panel?.getBoundingClientRect();
  const userInputs = [...document.querySelectorAll('[data-users-input]')];
  const drawerLines = drawer ? drawer.querySelectorAll('.cart-line').length : 0;
  const drawerCheckoutLink = drawer?.querySelector('[data-go-checkout]');

  return {
    open: drawer?.classList.contains('is-open') || false,
    panelWidth: panelRect ? Math.round(panelRect.width) : 0,
    viewportWidth: window.innerWidth,
    panelFitsViewport: panelRect ? panelRect.left >= -2 && panelRect.right <= window.innerWidth + 2 : false,
    drawerOverflow: panel ? panel.scrollWidth > panel.clientWidth + 1 : false,
    drawerLines,
    drawerLineLogos: drawer ? drawer.querySelectorAll('.cart-line .line-logo').length : 0,
    drawerCheckoutLink: !!drawerCheckoutLink,
    cartLinesTotal: document.querySelectorAll('.cart-line').length,
    userInputs: userInputs.length,
    total: document.querySelector('[data-cart-total]')?.textContent?.trim() || '',
    checkoutButtonVisible: !!document.querySelector('#checkout [data-pay-button]')?.offsetParent,
  };
})()`;

const checkoutProbe = `(() => {
  document.querySelector('[data-cart-drawer]')?.classList.remove('is-open');
  document.body.style.overflow = '';
  document.querySelector('#checkout')?.scrollIntoView({ block: 'start' });
  const section = document.querySelector('#checkout');
  const rect = section?.getBoundingClientRect();
  const checkoutLines = section ? section.querySelectorAll('.cart-line').length : 0;
  const form = section?.querySelector('[data-checkout-form]');
  const total = section?.querySelector('[data-cart-total]')?.textContent?.trim() || '';
  const inputs = section ? [...section.querySelectorAll('input')].map((input) => ({ name: input.name, type: input.type })) : [];

  return {
    exists: !!section,
    top: rect ? Math.round(rect.top) : null,
    width: rect ? Math.round(rect.width) : null,
    fitsViewport: rect ? rect.left >= -2 && rect.right <= window.innerWidth + 2 : false,
    checkoutLines,
    checkoutLineLogos: section ? section.querySelectorAll('.cart-line .line-logo').length : 0,
    total,
    hasForm: !!form,
    hasName: inputs.some((input) => input.name === 'name'),
    hasEmail: inputs.some((input) => input.name === 'email'),
    hasTerms: inputs.some((input) => input.name === 'monthly_terms'),
    payButton: !!section?.querySelector('[data-pay-button]'),
    userInputs: section ? section.querySelectorAll('[data-users-input]').length : 0,
  };
})()`;

const dynamicPricingProbe = `(() => {
  const input = document.querySelector('#checkout [data-users-input="mnotify-sms-gateway-prepaid-bulk"]');
  if (!input) return { ok: false, message: 'mNotify usage input missing' };

  input.value = '300000';
  input.dispatchEvent(new Event('input', { bubbles: true }));

  const section = document.querySelector('#checkout');
  const line = [...section.querySelectorAll('.cart-line')]
    .find((node) => node.textContent.includes('mNotify SMS Gateway'));
  const total = section.querySelector('[data-cart-total]')?.textContent?.trim() || '';
  const lineText = line?.textContent?.replace(/\\s+/g, ' ').trim() || '';

  return {
    ok: total === '$1,385.00' && lineText.includes('300,000 users') && lineText.includes('$750.00'),
    total,
    lineText,
  };
})()`;

const warmLazyImagesProbe = `new Promise(async (resolve) => {
  const step = Math.max(240, Math.floor(window.innerHeight * 0.72));
  for (let y = 0; y <= document.documentElement.scrollHeight; y += step) {
    window.scrollTo(0, y);
    await new Promise((done) => setTimeout(done, 90));
  }
  window.scrollTo(0, 0);
  await Promise.all([...document.images].map((img) => {
    if (img.complete && img.naturalWidth > 0) return Promise.resolve();
    return new Promise((done) => {
      img.addEventListener('load', done, { once: true });
      img.addEventListener('error', done, { once: true });
      setTimeout(done, 2500);
    });
  }));
  setTimeout(resolve, 250);
})`;

async function main() {
  const { cdp, sessionId, cleanup } = await connectChrome();
  const results = [];

  try {
    for (const viewport of VIEWPORTS) {
      await loadViewport(cdp, sessionId, viewport);
      await evaluate(cdp, sessionId, warmLazyImagesProbe);
      const layout = await evaluate(cdp, sessionId, layoutProbe);
      const filter = await evaluate(cdp, sessionId, filterProbe);
      await evaluate(cdp, sessionId, cartSetupProbe);
      await cdp.waitFor('Page.loadEventFired', sessionId, 15000).catch(() => null);
      await sleep(650);
      const cart = await evaluate(cdp, sessionId, cartProbe);
      const checkout = await evaluate(cdp, sessionId, checkoutProbe);
      const dynamicPricing = await evaluate(cdp, sessionId, dynamicPricingProbe);

      results.push({
        viewport,
        layout,
        filter,
        cart,
        checkout,
        dynamicPricing,
        pass: !layout.horizontalOverflow
          && layout.offenders.length === 0
          && layout.products === 58
          && layout.logoImages === 58
          && layout.unloadedImages.length === 0
          && layout.categoryOptions >= 20
          && filter.ok
          && cart.open
          && cart.panelFitsViewport
          && !cart.drawerOverflow
          && cart.drawerLines === 4
          && cart.drawerLineLogos === 4
          && cart.drawerCheckoutLink
          && checkout.exists
          && checkout.fitsViewport
          && checkout.checkoutLines === 4
          && checkout.checkoutLineLogos === 4
          && checkout.hasForm
          && checkout.hasName
          && checkout.hasEmail
          && checkout.hasTerms
          && checkout.payButton
          && checkout.userInputs === 2
          && dynamicPricing.ok,
      });
    }
  } finally {
    cleanup();
  }

  const failed = results.filter((result) => !result.pass);
  console.log(JSON.stringify({ ok: failed.length === 0, failed: failed.map((item) => item.viewport.name), results }, null, 2));

  if (failed.length > 0) {
    process.exitCode = 1;
  }
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
