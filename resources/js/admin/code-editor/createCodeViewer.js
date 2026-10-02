import { basicSetup, EditorView } from 'codemirror';
import { Compartment, EditorState } from '@codemirror/state';
import { search, openSearchPanel } from '@codemirror/search';
import { json } from '@codemirror/lang-json';
import { markdown } from '@codemirror/lang-markdown';

const viewers = new WeakMap();

const viewerTheme = EditorView.theme({
    '&': {
        height: '100%',
        maxHeight: '100%',
        backgroundColor: '#030712',
        color: '#f3f4f6',
        fontSize: '0.8125rem',
    },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': {
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
        lineHeight: '1.45rem',
        overflow: 'auto',
    },
    '.cm-gutters': {
        backgroundColor: '#111827',
        borderRight: '1px solid #374151',
        color: '#6b7280',
    },
    '.cm-activeLine, .cm-activeLineGutter': { backgroundColor: '#111827' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground': { backgroundColor: '#374151 !important' },
    '.cm-content': { padding: '0.5rem 0' },
    '.cm-line': { padding: '0 0.75rem' },
    '.cm-tooltip': { backgroundColor: '#111827', borderColor: '#4b5563' },
    '.cm-panels': { backgroundColor: '#111827', color: '#f3f4f6', borderBottom: '1px solid #374151' },
    '.cm-search': { padding: '4px 8px' },
    '.cm-search input': { backgroundColor: '#1f2937', color: '#f9fafb', borderColor: '#4b5563', borderRadius: '0.25rem', padding: '2px 6px' },
    '.cm-search button': { backgroundColor: '#374151', color: '#f3f4f6', borderRadius: '0.25rem', padding: '2px 6px', margin: '0 2px' },
    '.cm-search label': { color: '#9ca3af' },
}, { dark: true });

export function isJsonContent(value) {
    if (typeof value !== 'string') return false;
    const trimmed = value.trim();
    if (trimmed.length < 2) return false;
    const first = trimmed[0];
    const last = trimmed[trimmed.length - 1];
    if ((first === '{' && last === '}') || (first === '[' && last === ']')) {
        try {
            const parsed = JSON.parse(trimmed);
            return typeof parsed === 'object' && parsed !== null;
        } catch {
            return false;
        }
    }
    return false;
}

export function resolveViewerLanguage(language, value = '') {
    if (language === 'auto') {
        return isJsonContent(value) ? json() : markdown();
    }
    if (language === 'json') return json();
    if (language === 'markdown') return markdown();
    if (language === 'plain' || language === 'text' || ! language) return [];
    if (typeof language === 'function') return language();
    return language;
}

export function createCodeViewer({
    parent,
    value = '',
    language = 'markdown',
    wrap = true,
    extensions = [],
} = {}) {
    const container = parent || document.createElement('div');

    if (viewers.has(container)) {
        try {
            viewers.get(container).destroy();
        } catch {
            // ignore if already destroyed
        }
        viewers.delete(container);
    }

    const languageCompartment = new Compartment();
    const wrapCompartment = new Compartment();

    const stateExtensions = [
        basicSetup,
        search({ top: true }),
        EditorState.readOnly.of(true),
        EditorView.editable.of(false),
        viewerTheme,
        wrapCompartment.of(wrap ? EditorView.lineWrapping : []),
        languageCompartment.of(resolveViewerLanguage(language, value)),
        ...extensions,
    ];

    const view = new EditorView({
        parent: container,
        state: EditorState.create({
            doc: String(value ?? ''),
            extensions: stateExtensions,
        }),
    });

    const viewer = {
        view,
        dom: view.dom,
        parent: container,
        getValue: () => view.state.doc.toString(),
        setValue(nextValue, nextLanguage) {
            const next = String(nextValue ?? '');
            const effects = [];
            const targetLang = nextLanguage !== undefined ? nextLanguage : language;
            if (targetLang === 'auto' || nextLanguage !== undefined) {
                effects.push(languageCompartment.reconfigure(resolveViewerLanguage(targetLang, next)));
            }
            view.dispatch({
                changes: { from: 0, to: view.state.doc.length, insert: next },
                effects,
            });
            requestAnimationFrame(() => view.requestMeasure());
        },
        setLanguage(nextLanguage) {
            view.dispatch({
                effects: languageCompartment.reconfigure(resolveViewerLanguage(nextLanguage, view.state.doc.toString())),
            });
        },
        setWrap(nextWrap) {
            view.dispatch({
                effects: wrapCompartment.reconfigure(nextWrap ? EditorView.lineWrapping : []),
            });
        },
        openSearch() {
            view.focus();
            openSearchPanel(view);
        },
        focus: () => view.focus(),
        destroy() {
            viewers.delete(container);
            view.destroy();
        },
    };

    viewers.set(container, viewer);
    requestAnimationFrame(() => view.requestMeasure());

    return viewer;
}
