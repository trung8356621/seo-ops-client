import './codeEditor.css';
import { mountJsonEditor } from './jsonEditor.js';
import { mountMarkdownEditor } from './markdownEditor.js';

const editors = new WeakMap();

function copyText(text, editor) {
    const fallback = () => {
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.opacity = '0';
        document.body.append(textarea);
        textarea.select();
        document.execCommand('copy');
        textarea.remove();
        editor.focus();
    };
    if (navigator.clipboard?.writeText) navigator.clipboard.writeText(text).catch(fallback);
    else fallback();
}

function mount(root) {
    if (editors.has(root) || ! window.Alpine) return;
    const data = Alpine.$data(root);
    if (! data || typeof data.state === 'undefined') return;

    let syncingFromEditor = false;
    const wireElement = root.closest('[wire\\:id]');
    const wire = wireElement ? Livewire.find(wireElement.getAttribute('wire:id')) : null;
    const bridge = {
        disabled: root.dataset.disabled === 'true',
        get: () => data.state ?? '',
        set(value) {
            syncingFromEditor = true;
            data.state = value;
            queueMicrotask(() => { syncingFromEditor = false; });
        },
        call: (...args) => wire.call(...args),
        copy: copyText,
    };
    const editor = root.dataset.language === 'markdown'
        ? mountMarkdownEditor(root, bridge)
        : mountJsonEditor(root, bridge);
    editors.set(root, editor);

    Alpine.effect(() => {
        const state = data.state ?? '';
        if (! syncingFromEditor) editor.setValue(state);
    });
}

function scan() {
    document.querySelectorAll('[data-code-editor]').forEach((root) => mount(root));
}

document.addEventListener('alpine:initialized', scan);
document.addEventListener('livewire:navigated', scan);
document.addEventListener('DOMContentLoaded', scan);
new MutationObserver(scan).observe(document.documentElement, { childList: true, subtree: true });
scan();
