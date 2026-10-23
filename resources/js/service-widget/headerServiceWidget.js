/**
 * Lazy-load OPS Service Widget into Admin Header.
 * No OPS-SERVER network until the user clicks Dịch vụ.
 */

const SCRIPT_ATTR = 'data-ops-service-widget-script';
let scriptPromise = null;
let mounted = false;

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta?.getAttribute('content') || '';
}

function loadScriptOnce(url) {
  if (window.OpsServiceWidget) {
    return Promise.resolve();
  }
  if (scriptPromise) {
    return scriptPromise;
  }

  const existing = document.querySelector(`script[${SCRIPT_ATTR}]`);
  if (existing) {
    scriptPromise = new Promise((resolve, reject) => {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', () => reject(new Error('Failed to load Service Widget bundle.')));
    });
    return scriptPromise;
  }

  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = url;
    script.async = true;
    script.setAttribute(SCRIPT_ATTR, '1');
    script.onload = () => resolve();
    script.onerror = () => {
      scriptPromise = null;
      reject(new Error('Failed to load Service Widget bundle.'));
    };
    document.head.appendChild(script);
  });

  return scriptPromise;
}

async function fetchSession(sessionUrl) {
  const controller = new AbortController();
  const timer = window.setTimeout(() => controller.abort(), 8000);
  try {
    const response = await fetch(sessionUrl, {
      method: 'POST',
      headers: {
        Accept: 'application/json',
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        'X-Requested-With': 'XMLHttpRequest',
      },
      credentials: 'same-origin',
      body: JSON.stringify({}),
      signal: controller.signal,
    });

    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
      const message = data?.error?.message || `Session failed (${response.status})`;
      const error = new Error(message);
      error.code = data?.error?.code || 'session_error';
      throw error;
    }
    return data;
  } catch (error) {
    if (error?.name === 'AbortError') {
      const timeout = new Error('OPS-SERVER timed out.');
      timeout.code = 'server_offline';
      throw timeout;
    }
    throw error;
  } finally {
    window.clearTimeout(timer);
  }
}

function setUiState(root, state, message = '') {
  const status = root.querySelector('[data-service-widget-status]');
  const retry = root.querySelector('[data-service-widget-retry]');
  if (status) {
    status.textContent = message;
    status.hidden = !message;
    status.dataset.state = state;
  }
  if (retry) {
    retry.hidden = !(state === 'offline' || state === 'error');
  }
  root.dataset.swState = state;
  const trigger = root.querySelector('[data-service-widget-trigger]');
  if (trigger) {
    trigger.disabled = state === 'loading';
    trigger.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
  }
}

function ensureHost(root) {
  let host = root.querySelector('[data-ops-service-widget-host]');
  if (!host) {
    host = document.createElement('div');
    host.setAttribute('data-ops-service-widget-host', '1');
    host.id = 'ops-service-widget';
    root.appendChild(host);
  }
  return host;
}

function clickWidgetTrigger(host) {
  const trigger = host.shadowRoot?.querySelector('.ops-sw__trigger');
  trigger?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
}

async function ensureMounted(root) {
  if (mounted && window.OpsServiceWidget) {
    return ensureHost(root);
  }

  const sessionUrl = root.dataset.sessionUrl;
  if (!sessionUrl) {
    throw Object.assign(new Error('Missing session endpoint.'), { code: 'misconfigured' });
  }

  setUiState(root, 'loading', 'Đang tải…');
  const session = await fetchSession(sessionUrl);
  await loadScriptOnce(session.bundleUrl);

  if (!window.OpsServiceWidget) {
    throw new Error('OpsServiceWidget API missing after bundle load.');
  }

  const host = ensureHost(root);
  // Remount replaces the React root on the same host — never create a second host.
  window.OpsServiceWidget.unmount(host);
  window.OpsServiceWidget.setAuthContext({
    mode: 'bearer',
    accessToken: session.accessToken,
    installationId: session.installationId,
  });
  window.OpsServiceWidget.mount({
    target: host,
    useMock: false,
    apiBaseUrl: session.apiBaseUrl,
    apiTimeoutMs: session.apiTimeoutMs || 5000,
    locale: 'vi',
  });

  // Header owns the visible CTA; hide the widget's inner trigger (Shadow DOM).
  const hide = document.createElement('style');
  hide.setAttribute('data-ops-sw-header-hide-trigger', '1');
  hide.textContent =
    '.ops-sw__trigger{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;overflow:hidden!important;}';
  host.shadowRoot?.appendChild(hide);

  mounted = true;
  root.classList.add('service-widget-header--mounted');
  setUiState(root, 'ready', '');
  return host;
}

async function onTriggerClick(root) {
  try {
    const host = await ensureMounted(root);
    // Toggle popover via the mounted widget trigger.
    requestAnimationFrame(() => clickWidgetTrigger(host));
  } catch (error) {
    mounted = false;
    const offline =
      error?.code === 'server_offline' ||
      error?.code === 'server_unconfigured' ||
      /unreachable|timed out|Failed to load/i.test(String(error?.message || ''));
    setUiState(
      root,
      offline ? 'offline' : 'error',
      error?.message || 'Không kết nối được OPS-SERVER.',
    );
  }
}

function bindRoot(root) {
  if (root.dataset.swBound === '1') {
    return;
  }
  root.dataset.swBound = '1';

  root.querySelector('[data-service-widget-trigger]')?.addEventListener('click', (event) => {
    event.preventDefault();
    void onTriggerClick(root);
  });

  root.querySelector('[data-service-widget-retry]')?.addEventListener('click', (event) => {
    event.preventDefault();
    void onTriggerClick(root);
  });
}

function boot() {
  document.querySelectorAll('[data-service-widget-header]').forEach((node) => {
    if (node instanceof HTMLElement) {
      bindRoot(node);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}

export { boot as bootServiceWidgetHeader };
