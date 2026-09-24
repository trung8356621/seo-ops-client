/**
 * Global Support Ticket composer (Admin / SEO / Seeding topbar).
 * Ctrl+V paste + multi-file upload; clears only after successful DB submit.
 */
(function () {
    const ROOT_ID = 'global-header-ticket-root';

    function csrf() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function escapeAttr(s) {
        return escapeHtml(s).replace(/'/g, '&#39;');
    }

    async function boot() {
        const root = document.getElementById(ROOT_ID);
        if (!root || root.dataset.mounted === '1') {
            return;
        }
        root.dataset.mounted = '1';

        let props = {};
        try {
            props = JSON.parse(root.getAttribute('data-props') || '{}');
        } catch (_) {
            props = {};
        }

        const i18n = props.i18n || {};
        const state = {
            title: '',
            body: '',
            files: [],
            submitting: false,
            notice: '',
            noticeTone: 'info',
        };

        function render() {
            const pending = state.files.map((f, i) => `
              <li class="support-ticket-header__file">
                <span class="support-ticket-header__file-name" title="${escapeAttr(f.name)}">${escapeHtml(f.name)}</span>
                <button type="button" class="support-ticket-header__file-remove" data-remove-file="${i}" aria-label="${escapeAttr(i18n.remove || 'Remove')}">×</button>
              </li>`).join('');

            const noticeClass = state.noticeTone === 'error'
                ? 'support-ticket-header__notice is-error'
                : (state.noticeTone === 'success' ? 'support-ticket-header__notice is-success' : 'support-ticket-header__notice');

            root.innerHTML = `
              <form class="support-ticket-header__form" data-role="form">
                ${state.notice ? `<div class="${noticeClass}" role="status">${escapeHtml(state.notice)}</div>` : ''}
                <div class="support-ticket-header__field">
                  <label class="support-ticket-header__label" for="global-header-ticket-title">${escapeHtml(i18n.title || 'Title')}</label>
                  <input
                    id="global-header-ticket-title"
                    required
                    maxlength="200"
                    class="support-ticket-header__input"
                    data-role="title"
                    value="${escapeHtml(state.title)}"
                    placeholder="${escapeAttr(i18n.titlePlaceholder || '')}"
                    autocomplete="off"
                  />
                </div>
                <div class="support-ticket-header__field">
                  <label class="support-ticket-header__label" for="global-header-ticket-body">${escapeHtml(i18n.content || 'Content')}</label>
                  <textarea
                    id="global-header-ticket-body"
                    required
                    maxlength="10000"
                    rows="6"
                    class="support-ticket-header__textarea"
                    data-role="body"
                    placeholder="${escapeAttr(i18n.contentPlaceholder || '')}"
                  >${escapeHtml(state.body)}</textarea>
                </div>
                <div class="support-ticket-header__attachments" data-role="attachments">
                  <ul class="support-ticket-header__file-list">${pending || ''}</ul>
                </div>
                <div class="support-ticket-header__actions">
                  <input type="file" multiple accept="${escapeAttr(props.accept || 'image/*,.pdf')}" class="hidden" data-role="file" />
                  <button type="button" class="support-ticket-header__btn support-ticket-header__btn--secondary" data-role="attach">
                    ${escapeHtml(i18n.attach || 'Attach')}
                  </button>
                  <button type="submit" class="support-ticket-header__btn support-ticket-header__btn--primary" ${state.submitting ? 'disabled' : ''}>
                    ${escapeHtml(state.submitting ? (i18n.submitting || 'Sending…') : (i18n.submit || 'Send'))}
                  </button>
                </div>
              </form>`;

            const form = root.querySelector('[data-role="form"]');
            form?.addEventListener('submit', onSubmit);

            const titleEl = root.querySelector('[data-role="title"]');
            titleEl?.addEventListener('input', (e) => {
                state.title = e.target.value;
            });

            const bodyEl = root.querySelector('[data-role="body"]');
            bodyEl?.addEventListener('input', (e) => {
                state.body = e.target.value;
            });
            bodyEl?.addEventListener('paste', onPaste);

            root.querySelector('[data-role="attach"]')?.addEventListener('click', () => {
                root.querySelector('[data-role="file"]')?.click();
            });
            root.querySelector('[data-role="file"]')?.addEventListener('change', (e) => {
                const picked = Array.from(e.target.files || []);
                addFiles(picked);
                e.target.value = '';
            });
            root.querySelectorAll('[data-remove-file]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const idx = Number(btn.getAttribute('data-remove-file'));
                    if (!Number.isNaN(idx)) {
                        state.files.splice(idx, 1);
                        render();
                    }
                });
            });
        }

        function addFiles(files) {
            const maxBytes = Number(props.maxFileSizeBytes) || (5 * 1024 * 1024);
            const next = [...state.files];
            for (const file of files) {
                if (next.length >= 5) {
                    break;
                }
                if (file.size > maxBytes) {
                    state.notice = i18n.fileTooLarge || 'File exceeds size limit.';
                    state.noticeTone = 'error';
                    continue;
                }
                next.push(file);
            }
            state.files = next;
            render();
        }

        function onPaste(e) {
            const items = Array.from(e.clipboardData?.items || []);
            const imageFiles = [];
            items.forEach((item) => {
                if (item.kind === 'file' && String(item.type || '').startsWith('image/')) {
                    const file = item.getAsFile();
                    if (file) {
                        const ext = (file.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                        imageFiles.push(new File([file], `paste-${Date.now()}.${ext}`, { type: file.type }));
                    }
                }
            });
            if (imageFiles.length > 0) {
                e.preventDefault();
                addFiles(imageFiles);
            }
        }

        async function onSubmit(e) {
            e.preventDefault();
            if (state.submitting) {
                return;
            }
            state.submitting = true;
            state.notice = '';
            state.noticeTone = 'info';
            render();
            try {
                const form = new FormData();
                form.append('title', state.title);
                form.append('body', state.body);
                form.append('page_url', props.pageUrl || window.location.href);
                if (props.connectionHash) {
                    form.append('connection_hash', props.connectionHash);
                }
                if (props.routeName) {
                    form.append('route_name', props.routeName);
                }
                if (props.service) {
                    form.append('service', props.service);
                }
                state.files.forEach((file) => {
                    form.append('files[]', file);
                });
                const res = await fetch(props.storeUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': props.csrfToken || csrf(),
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                    body: form,
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    throw new Error(data.message || ('HTTP ' + res.status));
                }
                state.notice = data.message || i18n.submitted || 'Ticket submitted successfully.';
                state.noticeTone = 'success';
                state.title = '';
                state.body = '';
                state.files = [];
                render();
                window.dispatchEvent(new CustomEvent('support-ticket:submitted', {
                    detail: { ticket: data.ticket || null },
                }));
            } catch (err) {
                state.notice = err.message || i18n.submitFailed || 'Could not submit — please try again.';
                state.noticeTone = 'error';
                render();
            } finally {
                state.submitting = false;
                render();
            }
        }

        render();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
    document.addEventListener('livewire:navigated', () => {
        const root = document.getElementById(ROOT_ID);
        if (root) {
            delete root.dataset.mounted;
        }
        boot();
    });
})();
