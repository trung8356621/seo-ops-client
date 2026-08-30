import React, { useCallback, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
// Same shared editor stylesheet as Article Editor (toolbar / TipTap / insert controls).
import '../../../addons/content/resources/css/article-editor.css';
import HelpContentEditor from './HelpContentEditor';

/**
 * Help Admin TipTap host — lazy Vite entry only on Help edit/create pages.
 * Reuses Article BlockFormatToolbar + coreModule; never mounts SeoArticleEditor.
 */

function findLivewireComponent() {
    const el = document.getElementById('help-admin-editor-root');
    if (!el || !window.Livewire) {
        return null;
    }
    try {
        const id = el.closest('[wire\\:id]')?.getAttribute('wire:id');
        return id ? window.Livewire.find(id) : null;
    } catch {
        return null;
    }
}

function HelpAdminEditorApp({ initialHtml }) {
    const [html, setHtml] = useState(initialHtml || '<p></p>');
    const syncTimer = React.useRef(null);

    const pushToLivewire = useCallback((nextHtml) => {
        window.clearTimeout(syncTimer.current);
        syncTimer.current = window.setTimeout(() => {
            const wire = findLivewireComponent();
            if (wire && typeof wire.call === 'function') {
                wire.call('updateEditorHtml', nextHtml);
            }
        }, 200);
    }, []);

    useEffect(() => {
        const handler = (event) => {
            const next = event?.detail?.html;
            if (typeof next !== 'string') {
                return;
            }
            setHtml(next || '<p></p>');
        };
        window.addEventListener('help-admin-load-html', handler);
        return () => window.removeEventListener('help-admin-load-html', handler);
    }, []);

    return (
        <HelpContentEditor
            html={html}
            onChange={(next) => {
                setHtml(next);
                pushToLivewire(next);
            }}
        />
    );
}

function mount() {
    const rootEl = document.getElementById('help-admin-editor-root');
    if (!rootEl || rootEl.dataset.helpAdminMounted === '1') {
        return;
    }
    rootEl.dataset.helpAdminMounted = '1';
    let initialHtml = '<p></p>';
    try {
        initialHtml = JSON.parse(rootEl.getAttribute('data-initial-html') || '""') || '<p></p>';
    } catch {
        initialHtml = '<p></p>';
    }
    createRoot(rootEl).render(<HelpAdminEditorApp initialHtml={initialHtml} />);
}

function remountFromLivewire() {
    const rootEl = document.getElementById('help-admin-editor-root');
    if (!rootEl) {
        return;
    }
    if (rootEl.dataset.helpAdminMounted === '1' && rootEl.childElementCount > 0) {
        return;
    }
    delete rootEl.dataset.helpAdminMounted;
    mount();
}

document.addEventListener('DOMContentLoaded', mount);
document.addEventListener('livewire:navigated', remountFromLivewire);
document.addEventListener('livewire:init', () => {
    window.Livewire?.hook?.('morph.updated', () => {
        window.requestAnimationFrame(remountFromLivewire);
    });
    window.Livewire?.on?.('help-admin-load-html', (payload) => {
        const html = Array.isArray(payload) ? payload[0]?.html : payload?.html;
        window.dispatchEvent(new CustomEvent('help-admin-load-html', { detail: { html: html || '<p></p>' } }));
    });
});
