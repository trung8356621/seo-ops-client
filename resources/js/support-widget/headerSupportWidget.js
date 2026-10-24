/**
 * Lazy-load OPS Support Widget body into Admin Header dropdown.
 * Laravel owns trigger + dropdown; React fills the host. No OPS network until click.
 */

const SCRIPT_ATTR = 'data-ops-support-widget-script';
const TOKEN_SKEW_MS = 60_000;

let scriptPromise = null;

/** @type {WeakMap<HTMLElement, { open: boolean, mounted: boolean, mounting: boolean, session: object|null, dropdown: HTMLElement|null }>} */
const rootState = new WeakMap();

const FRIENDLY = {
  server_offline: 'Hỗ trợ tạm thời không khả dụng. Kiểm tra mạng rồi thử lại.',
  server_unconfigured: 'Chưa cấu hình địa chỉ OPS-SERVER.',
  secret_unconfigured: 'Máy chủ hỗ trợ chưa sẵn sàng. Liên hệ quản trị viên.',
  not_enrolled: 'Thiết bị chưa Enrollment. Hoàn tất Quick Setup trước.',
  scope_mismatch: 'Phiên hỗ trợ không khớp Installation hiện tại.',
  invalid_response: 'Phản hồi từ máy chủ hỗ trợ không hợp lệ. Thử lại.',
  invalid_scope: 'Phiên hỗ trợ thiếu quyền cần thiết.',
  token_expired: 'Phiên hỗ trợ hết hạn. Thử lại.',
  session_error: 'Không mở được hỗ trợ. Thử lại.',
  misconfigured: 'Cấu hình hỗ trợ chưa đầy đủ.',
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
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
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

function getWidgetApi() {
  return window.OpsSupportWidget || null;
}

function cacheBustedUrl(url) {
  try {
    const resolved = new URL(url, window.location.origin);
    if (!resolved.searchParams.has('v')) {
      resolved.searchParams.set('v', 'st-body-20261024');
    }
    return resolved.toString();
  } catch {
    return url;
  }
}

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

function loadScriptOnce(url) {
  if (getWidgetApi()) return Promise.resolve();
  if (scriptPromise) return scriptPromise;

  const src = cacheBustedUrl(url);
  scriptPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.setAttribute(SCRIPT_ATTR, '1');
    script.onload = () => resolve();
    script.onerror = () =>
      reject(Object.assign(new Error('Failed to load Support Widget bundle.'), { code: 'server_offline' }));
    document.head.appendChild(script);
  });
  return scriptPromise;
}

async function fetchSession(sessionUrl) {
  const res = await fetch(sessionUrl, {
    method: 'POST',
    headers: {
      Accept: 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
      'X-Requested-With': 'XMLHttpRequest',
    },
    credentials: 'same-origin',
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) {
    throw Object.assign(new Error(data?.error?.message || `HTTP ${res.status}`), {
      code: data?.error?.code || 'session_error',
    });
  }
  return data;
}

function getDropdown(root) {
  return root.querySelector('[data-support-widget-dropdown]');
}

function getHost(root) {
  const dropdown = state(root).dropdown || getDropdown(root);
  return dropdown?.querySelector('[data-ops-support-widget-host]')
    || root.querySelector('[data-ops-support-widget-host]');
}

function portalDropdown(root) {
  const dropdown = getDropdown(root);
  if (!dropdown) return null;
  if (dropdown.parentElement !== document.body) {
    document.body.appendChild(dropdown);
    dropdown.dataset.stPortaled = '1';
  }
  state(root).dropdown = dropdown;
  return dropdown;
}

function positionDropdown(root) {
  const trigger = root.querySelector('[data-support-widget-trigger]');
  const dropdown = portalDropdown(root);
  if (!trigger || !dropdown || dropdown.hidden) return;

  const rect = trigger.getBoundingClientRect();
  const width = Math.min(420, Math.max(280, window.innerWidth - 24));
  let left = rect.right - width;
  if (left < 12) left = 12;
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
  const loading = dropdown?.querySelector('[data-support-widget-loading]');
  const errorBox = dropdown?.querySelector('[data-support-widget-error]');
  const status = dropdown?.querySelector('[data-support-widget-status]');
  const host = dropdown?.querySelector('[data-ops-support-widget-host]');
  const trigger = root.querySelector('[data-support-widget-trigger]');
  const showError = panelState === 'error' || panelState === 'offline';
  const showHost = panelState === 'ready';

  if (dropdown) dropdown.hidden = panelState === 'closed';
  if (loading) loading.hidden = panelState !== 'loading';
  if (errorBox) errorBox.hidden = !showError;
  if (status) status.textContent = showError ? message : '';
  if (host) host.hidden = !showHost;
  if (trigger) {
    trigger.setAttribute('aria-expanded', panelState === 'closed' ? 'false' : 'true');
  }
  if (panelState !== 'closed') positionDropdown(root);
}

function mountBody(root, session) {
  const api = getWidgetApi();
  if (!api?.setAuthContext || !api?.mount) {
    throw Object.assign(new Error('OpsSupportWidget API missing.'), { code: 'invalid_response' });
  }
  const host = getHost(root);
  if (!host) {
    throw Object.assign(new Error('Missing widget host.'), { code: 'misconfigured' });
  }

  const apiBaseUrl = browserReachableApiBase(session.apiBaseUrl);
  host.dataset.opsInstallationId = session.installationId;
  host.dataset.opsApiBase = apiBaseUrl;
  host.dataset.opsLocale = 'vi';
  host.dataset.opsSenderName = session.senderName || '';
  host.dataset.opsSenderEmail = session.senderEmail || '';
  host.dataset.opsPageUrl = session.pageUrl || window.location.href;

  api.setAuthContext({
    mode: 'bearer',
    accessToken: session.accessToken,
    installationId: session.installationId,
  });
  api.mount({
    target: host,
    installationId: session.installationId,
    apiBaseUrl,
    apiTimeoutMs: session.apiTimeoutMs || 8000,
    locale: 'vi',
    senderName: session.senderName || '',
    senderEmail: session.senderEmail || '',
    pageUrl: session.pageUrl || window.location.href,
  });
  return host;
}

async function ensureMounted(root) {
  const s = state(root);
  let host = getHost(root);
  if (s.mounted && s.session && !tokenNeedsRefresh(s.session) && getWidgetApi() && host?.shadowRoot) {
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
  setPanelState(root, 'ready');
  return host;
}

async function openPanel(root) {
  const s = state(root);
  if (s.mounting) return;
  s.open = true;
  s.mounting = true;
  try {
    await ensureMounted(root);
  } catch (error) {
    const code = error?.code || 'session_error';
    setPanelState(root, code === 'server_offline' ? 'offline' : 'error', friendlyMessage(error));
  } finally {
    s.mounting = false;
  }
}

function closePanel(root) {
  const s = state(root);
  s.open = false;
  setPanelState(root, 'closed');
}

function bootRoot(root) {
  if (root.dataset.stBound === '1') return;
  root.dataset.stBound = '1';

  const trigger = root.querySelector('[data-support-widget-trigger]');
  const retry = () => void openPanel(root);

  trigger?.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    const s = state(root);
    if (s.open) {
      closePanel(root);
      return;
    }
    void openPanel(root);
  });

  root.querySelector('[data-support-widget-retry]')?.addEventListener('click', (e) => {
    e.preventDefault();
    retry();
  });

  document.addEventListener('click', (e) => {
    const s = state(root);
    if (!s.open) return;
    const dropdown = s.dropdown || getDropdown(root);
    if (root.contains(e.target) || dropdown?.contains(e.target)) return;
    closePanel(root);
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && state(root).open) closePanel(root);
  });

  window.addEventListener('resize', () => {
    if (state(root).open) positionDropdown(root);
  });
}

function bootAll() {
  document.querySelectorAll('[data-support-widget-header]').forEach((el) => bootRoot(el));
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', bootAll);
} else {
  bootAll();
}
document.addEventListener('livewire:navigated', bootAll);
