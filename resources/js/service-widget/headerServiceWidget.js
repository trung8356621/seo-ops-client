/**
 * Lazy-load OPS Service Widget into Admin Header dropdown.
 * No OPS-SERVER network until the user clicks Dịch vụ.
 */

const SCRIPT_ATTR = 'data-ops-service-widget-script';
let scriptPromise = null;
let mounted = false;
let open = false;

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
};

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
      existing.addEventListener('error', () =>
        reject(Object.assign(new Error('Failed to load Service Widget bundle.'), { code: 'server_offline' })),
      );
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
    if (!data?.accessToken || !data?.installationId || !data?.apiBaseUrl) {
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

function setPanelState(root, state, message = '') {
  const dropdown = root.querySelector('[data-service-widget-dropdown]');
  const loading = root.querySelector('[data-service-widget-loading]');
  const errorBox = root.querySelector('[data-service-widget-error]');
  const status = root.querySelector('[data-service-widget-status]');
  const host = root.querySelector('[data-ops-service-widget-host]');
  const trigger = root.querySelector('[data-service-widget-trigger]');

  if (dropdown) dropdown.hidden = state === 'closed';
  if (loading) loading.hidden = state !== 'loading';
  if (errorBox) errorBox.hidden = state !== 'error' && state !== 'offline';
  if (status) status.textContent = message;
  if (host) host.hidden = state !== 'ready';
  if (trigger) {
    trigger.disabled = state === 'loading';
    trigger.setAttribute('aria-busy', state === 'loading' ? 'true' : 'false');
    trigger.setAttribute('aria-expanded', state === 'closed' ? 'false' : 'true');
  }
  root.dataset.swState = state;
}

function clickWidgetTrigger(host) {
  const trigger = host.shadowRoot?.querySelector('.ops-sw__trigger');
  trigger?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
}

async function ensureMounted(root) {
  if (mounted && window.OpsServiceWidget) {
    return root.querySelector('[data-ops-service-widget-host]');
  }

  const sessionUrl = root.dataset.sessionUrl;
  if (!sessionUrl) {
    throw Object.assign(new Error('Missing session endpoint.'), { code: 'misconfigured' });
  }

  setPanelState(root, 'loading');
  const session = await fetchSession(sessionUrl);
  await loadScriptOnce(session.bundleUrl);

  if (!window.OpsServiceWidget) {
    throw Object.assign(new Error('OpsServiceWidget API missing.'), { code: 'invalid_response' });
  }

  const host = root.querySelector('[data-ops-service-widget-host]');
  if (!host) {
    throw Object.assign(new Error('Missing widget host.'), { code: 'misconfigured' });
  }

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

  const hide = document.createElement('style');
  hide.setAttribute('data-ops-sw-header-hide-trigger', '1');
  hide.textContent =
    '.ops-sw__trigger{position:absolute!important;width:1px!important;height:1px!important;opacity:0!important;pointer-events:none!important;overflow:hidden!important;}.ops-sw__popover{position:static!important;width:100%!important;box-shadow:none!important;border:0!important;padding:0!important;}';
  host.shadowRoot?.appendChild(hide);

  mounted = true;
  setPanelState(root, 'ready');
  requestAnimationFrame(() => clickWidgetTrigger(host));
  return host;
}

function closeDropdown(root) {
  open = false;
  setPanelState(root, 'closed');
}

async function openDropdown(root) {
  open = true;
  try {
    await ensureMounted(root);
  } catch (error) {
    mounted = false;
    const offline =
      error?.code === 'server_offline' ||
      error?.code === 'server_unconfigured' ||
      /unreachable|timed out|Failed to load/i.test(String(error?.message || ''));
    setPanelState(root, offline ? 'offline' : 'error', friendlyMessage(error));
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
    if (open) {
      closeDropdown(root);
      return;
    }
    void openDropdown(root);
  });

  root.querySelector('[data-service-widget-retry]')?.addEventListener('click', (event) => {
    event.preventDefault();
    mounted = false;
    void openDropdown(root);
  });

  document.addEventListener('mousedown', (event) => {
    if (!open) return;
    if (event.target instanceof Node && !root.contains(event.target)) {
      closeDropdown(root);
    }
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

export { boot as bootServiceWidgetHeader, friendlyMessage };
