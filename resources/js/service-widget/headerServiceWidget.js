/**
 * Lazy-load OPS Service Widget body into Admin Header dropdown.
 * Laravel owns trigger + dropdown shell; React fills the host 100%.
 * No OPS-SERVER network until the user clicks Dịch vụ.
 */

const SCRIPT_ATTR = 'data-ops-service-widget-script';
const TOKEN_SKEW_MS = 60_000;

let scriptPromise = null;

/** @type {WeakMap<HTMLElement, { open: boolean, mounted: boolean, mounting: boolean, session: object|null, dropdown: HTMLElement|null }>} */
const rootState = new WeakMap();

const FRIENDLY = {
  server_offline: 'Không kết nối được máy chủ dịch vụ. Kiểm tra mạng rồi thử lại.',
  server_unconfigured: 'Chưa cấu hình địa chỉ OPS-SERVER.',
  secret_unconfigured: 'Máy chủ dịch vụ chưa sẵn sàng cấp phiên. Liên hệ quản trị viên.',
  not_enrolled: 'Thiết bị chưa Enrollment. Hoàn tất Quick Setup trước.',
  scope_mismatch: 'Phiên dịch vụ không khớp Installation hiện tại.',
  invalid_response: 'Phản hồi từ máy chủ dịch vụ không hợp lệ. Thử lại.',
  invalid_scope: 'Phiên dịch vụ thiếu quyền cần thiết.',
  token_expired: 'Phiên dịch vụ hết hạn. Thử lại để lấy phiên mới.',
  session_error: 'Không mở được Dịch vụ. Thử lại.',
  misconfigured: 'Cấu hình Dịch vụ chưa đầy đủ. Liên hệ quản trị viên.',
};

function state(root) {
  let s = rootState.get(root);
  if (!s) {
    s = { open: false, mounted: false, mounting: false, session: null, dropdown: null };
    rootState.set(root, s);
  }
  return s;
}

function csrfToken() {
  const meta = document.querySelector('meta[name="csrf-token"]');
  return meta?.getAttribute('content') || '';
}

function friendlyMessage(error) {
  const code = error?.code || 'session_error';
  if (FRIENDLY[code]) return FRIENDLY[code];
  const raw = String(error?.message || '');
  if (!raw || /exception|stack|\{|\}|access_token|expires_at/i.test(raw)) {
    return FRIENDLY.session_error;
  }
  return raw;
}

function tokenNeedsRefresh(session) {
  if (!session?.expiresAt) return true;
  const exp = Date.parse(session.expiresAt);
  if (!Number.isFinite(exp)) return true;
  return exp <= Date.now() + TOKEN_SKEW_MS;
}

function hostHasBody(host) {
  return Boolean(host?.shadowRoot?.querySelector('[data-ops-sw-root]'));
}

function cacheBustedUrl(url) {
  try {
    const resolved = new URL(url, window.location.origin);
    // Bust Nginx expires 7d on /vendor/*.js for import-test / local deploys.
    if (!resolved.searchParams.has('v')) {
      resolved.searchParams.set('v', 'sw-body-20261023');
    }
    return resolved.toString();
  } catch {
    return url;
  }
}

/** Prefer IPv4 loopback — Windows localhost→::1 often hangs while 127.0.0.1 works. */
function browserReachableApiBase(url) {
  try {
    const resolved = new URL(url);
    if (resolved.hostname === 'localhost') {
      resolved.hostname = '127.0.0.1';
    }
    return resolved.toString().replace(/\/$/, '');
  } catch {
    return String(url || '').replace(/\/$/, '');
  }
}

function getWidgetApi() {
  return window.OpsCommerceWidget || window.OpsServiceWidget || null;
}

function loadScriptOnce(url) {
  if (getWidgetApi()) {
    return Promise.resolve();
  }
  if (scriptPromise) {
    return scriptPromise;
  }

  const src = cacheBustedUrl(url);
  const existing = document.querySelector(`script[${SCRIPT_ATTR}]`);
  if (existing) {
    scriptPromise = new Promise((resolve, reject) => {
      existing.addEventListener('load', () => resolve());
      existing.addEventListener('error', () =>
        reject(Object.assign(new Error('Failed to load Service Widget bundle.'), { code: 'server_offline' })),
      );
    });
    return scriptPromise;
  }

  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.setAttribute(SCRIPT_ATTR, '1');
    script.onload = () => resolve();
    script.onerror = () => {
      scriptPromise = null;
      reject(Object.assign(new Error('Failed to load Service Widget bundle.'), { code: 'server_offline' }));
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
      const error = new Error(data?.error?.message || 'Session failed');
      error.code = data?.error?.code || 'session_error';
      throw error;
    }
    if (!data?.accessToken || !data?.installationId || !data?.apiBaseUrl || !data?.bundleUrl) {
      throw Object.assign(new Error('Incomplete session'), { code: 'invalid_response' });
    }
    return data;
  } catch (error) {
    if (error?.name === 'AbortError') {
      throw Object.assign(new Error('OPS-SERVER timed out.'), { code: 'server_offline' });
    }
    throw error;
  } finally {
    window.clearTimeout(timer);
  }
}

function getDropdown(root) {
  const s = state(root);
  if (s.dropdown instanceof HTMLElement && document.body.contains(s.dropdown)) {
    return s.dropdown;
  }
  const local = root.querySelector('[data-service-widget-dropdown]');
  if (local) {
    s.dropdown = local;
    return local;
  }
  const byId = document.getElementById('service-widget-dropdown');
  if (byId) {
    s.dropdown = byId;
  }
  return byId;
}

function getHost(root) {
  return (
    getDropdown(root)?.querySelector('[data-ops-service-widget-host]') ||
    root.querySelector('[data-ops-service-widget-host]') ||
    document.getElementById('ops-service-widget')
  );
}

/**
 * Move dropdown under document.body so Filament sticky topbar overflow-x:clip
 * cannot truncate it (position:fixed inside sticky is still clipped).
 */
function portalDropdown(root) {
  const dropdown = getDropdown(root);
  if (!dropdown) {
    return null;
  }
  if (dropdown.parentElement !== document.body) {
    document.body.appendChild(dropdown);
    dropdown.dataset.swPortaled = '1';
  }
  state(root).dropdown = dropdown;
  return dropdown;
}

/**
 * Pin the (portaled) dropdown to the trigger in the viewport.
 */
function positionDropdown(root) {
  const trigger = root.querySelector('[data-service-widget-trigger]');
  const dropdown = portalDropdown(root);
  if (!trigger || !dropdown || dropdown.hidden) {
    return;
  }

  const rect = trigger.getBoundingClientRect();
  const width = Math.min(440, Math.max(280, window.innerWidth - 24));
  let left = rect.right - width;
  if (left < 12) {
    left = 12;
  }
  if (left + width > window.innerWidth - 12) {
    left = Math.max(12, window.innerWidth - width - 12);
  }

  const maxHeight = Math.min(560, window.innerHeight - rect.bottom - 16);
  dropdown.style.position = 'fixed';
  dropdown.style.top = `${Math.round(rect.bottom + 8)}px`;
  dropdown.style.left = `${Math.round(left)}px`;
  dropdown.style.right = 'auto';
  dropdown.style.width = `${Math.round(width)}px`;
  dropdown.style.maxHeight = `${Math.max(180, Math.round(maxHeight))}px`;
  dropdown.style.zIndex = '80';
}

function setPanelState(root, panelState, message = '') {
  const dropdown = portalDropdown(root) || getDropdown(root);
  const loading = dropdown?.querySelector('[data-service-widget-loading]')
    || root.querySelector('[data-service-widget-loading]');
  const errorBox = dropdown?.querySelector('[data-service-widget-error]')
    || root.querySelector('[data-service-widget-error]');
  const status = dropdown?.querySelector('[data-service-widget-status]')
    || root.querySelector('[data-service-widget-status]');
  const host = dropdown?.querySelector('[data-ops-service-widget-host]')
    || root.querySelector('[data-ops-service-widget-host]');
  const trigger = root.querySelector('[data-service-widget-trigger]');
  const showError = panelState === 'error' || panelState === 'offline';
  const showHost = panelState === 'ready';

  if (dropdown) dropdown.hidden = panelState === 'closed';
  if (loading) loading.hidden = panelState !== 'loading';
  if (errorBox) {
    // Never show Laravel Retry alongside the React body.
    errorBox.hidden = !showError;
    errorBox.setAttribute('aria-hidden', showError ? 'false' : 'true');
  }
  if (status) status.textContent = showError ? message : '';
  if (host) {
    host.hidden = !showHost;
    host.setAttribute('aria-hidden', showHost ? 'false' : 'true');
  }
  if (trigger) {
    trigger.disabled = panelState === 'loading';
    trigger.setAttribute('aria-busy', panelState === 'loading' ? 'true' : 'false');
    trigger.setAttribute('aria-expanded', panelState === 'closed' ? 'false' : 'true');
  }
  root.dataset.swState = panelState;
  if (panelState !== 'closed') {
    positionDropdown(root);
  }
}

/**
 * Force the Shadow DOM chain to fill the Laravel dropdown width.
 * OPS bundle uses :host { all: initial } which can collapse % widths to intrinsic size.
 */
function applyHostFillLayout(host) {
  host.style.display = 'block';
  host.style.width = '100%';
  host.style.minWidth = '0';
  host.style.maxWidth = '100%';
  host.style.height = 'auto';
  host.style.maxHeight = '100%';
  host.style.boxSizing = 'border-box';

  const shadow = host.shadowRoot;
  if (!shadow) {
    return;
  }

  let style = shadow.querySelector('style[data-ops-sw-host-layout]');
  if (!style) {
    style = document.createElement('style');
    style.setAttribute('data-ops-sw-host-layout', '1');
    shadow.appendChild(style);
  }

  style.textContent = `
:host {
  display: block !important;
  width: 100% !important;
  min-width: 0 !important;
  max-width: 100% !important;
  height: auto !important;
  max-height: 100% !important;
  box-sizing: border-box !important;
}
[data-ops-sw-root],
.ops-sw {
  display: flex !important;
  flex-direction: column !important;
  width: 100% !important;
  min-width: 0 !important;
  max-width: 100% !important;
  height: auto !important;
  max-height: 100% !important;
  box-sizing: border-box !important;
}
.ops-sw__list {
  overflow-x: hidden !important;
  overflow-y: auto !important;
  min-width: 0 !important;
  flex: 1 1 auto !important;
}
`;
}

function mountBody(root, session) {
  const api = getWidgetApi();
  if (!api?.setAuthContext || !api?.mount) {
    throw Object.assign(new Error('OpsCommerceWidget API missing.'), { code: 'invalid_response' });
  }

  const host = getHost(root);
  if (!host) {
    throw Object.assign(new Error('Missing widget host.'), { code: 'misconfigured' });
  }

  const apiBaseUrl = browserReachableApiBase(session.apiBaseUrl);

  host.dataset.opsInstallationId = session.installationId;
  host.dataset.opsUseMock = 'false';
  host.dataset.opsApiBase = apiBaseUrl;
  host.dataset.opsLocale = 'vi';

  // Do not unmount on session refresh or API errors — remount reuses the React root.
  api.setAuthContext({
    mode: 'bearer',
    accessToken: session.accessToken,
    installationId: session.installationId,
  });
  api.mount({
    target: host,
    useMock: false,
    installationId: session.installationId,
    apiBaseUrl,
    apiTimeoutMs: session.apiTimeoutMs || 5000,
    locale: 'vi',
  });

  applyHostFillLayout(host);
  return host;
}

async function ensureMounted(root) {
  const s = state(root);
  let host = getHost(root);

  if (
    s.mounted &&
    s.session &&
    !tokenNeedsRefresh(s.session) &&
    hostHasBody(host) &&
    getWidgetApi()
  ) {
    return host;
  }

  const sessionUrl = root.dataset.sessionUrl;
  if (!sessionUrl) {
    throw Object.assign(new Error('Missing session endpoint.'), { code: 'misconfigured' });
  }

  setPanelState(root, 'loading');
  const session = await fetchSession(sessionUrl);
  await loadScriptOnce(session.bundleUrl);
  host = mountBody(root, session);

  s.session = session;
  s.mounted = true;
  return host;
}

function closeDropdown(root) {
  const s = state(root);
  s.open = false;
  // Hide only — keep React instance / auth / service list for reopen.
  setPanelState(root, 'closed');
}

async function openDropdown(root) {
  const s = state(root);
  if (s.mounting) {
    return;
  }

  s.open = true;
  s.mounting = true;
  try {
    await ensureMounted(root);
    // Respect close during in-flight session/script load.
    setPanelState(root, s.open ? 'ready' : 'closed');
  } catch (error) {
    if (!s.open) {
      setPanelState(root, 'closed');
      return;
    }
    const offline =
      error?.code === 'server_offline' ||
      error?.code === 'server_unconfigured' ||
      error?.code === 'secret_unconfigured' ||
      /unreachable|timed out|Failed to load/i.test(String(error?.message || ''));
    // Keep prior mount when session refresh fails — do not unmount React body.
    setPanelState(root, offline ? 'offline' : 'error', friendlyMessage(error));
  } finally {
    s.mounting = false;
  }
}

function retryOpen(root) {
  const s = state(root);
  // Force a fresh Laravel session (token exchange) without destroying the React tree first.
  s.session = null;
  void openDropdown(root);
}

function syncAfterNavigation(root) {
  const s = rootState.get(root);
  if (!s?.mounted) {
    return;
  }
  const host = getHost(root);
  if (!hostHasBody(host)) {
    // Livewire morph wiped Shadow DOM — remount on next open, never duplicate.
    s.mounted = false;
    s.session = null;
    s.open = false;
    s.dropdown = null;
  }
}

function bindRoot(root) {
  if (root.dataset.swBound === '1') {
    return;
  }
  root.dataset.swBound = '1';
  setPanelState(root, 'closed');

  root.querySelector('[data-service-widget-trigger]')?.addEventListener('click', (event) => {
    event.preventDefault();
    event.stopPropagation();
    const s = state(root);
    if (s.mounting) {
      return;
    }
    if (s.open) {
      closeDropdown(root);
      return;
    }
    void openDropdown(root);
  });

  root.querySelector('[data-service-widget-retry]')?.addEventListener('click', (event) => {
    event.preventDefault();
    retryOpen(root);
  });

  document.addEventListener('mousedown', (event) => {
    const s = state(root);
    if (!s.open) return;
    const dropdown = getDropdown(root);
    const path = typeof event.composedPath === 'function' ? event.composedPath() : [];
    if (
      path.includes(root) ||
      (dropdown && path.includes(dropdown)) ||
      (event.target instanceof Node && (root.contains(event.target) || dropdown?.contains(event.target)))
    ) {
      return;
    }
    closeDropdown(root);
  });

  window.addEventListener(
    'resize',
    () => {
      if (state(root).open) {
        positionDropdown(root);
      }
    },
    { passive: true },
  );

  window.addEventListener(
    'scroll',
    () => {
      if (state(root).open) {
        positionDropdown(root);
      }
    },
    { passive: true, capture: true },
  );
}

function boot() {
  document.querySelectorAll('[data-service-widget-header]').forEach((node) => {
    if (node instanceof HTMLElement) {
      syncAfterNavigation(node);
      bindRoot(node);
    }
  });
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}

document.addEventListener('livewire:navigated', boot);

export { boot as bootServiceWidgetHeader, friendlyMessage, tokenNeedsRefresh };
