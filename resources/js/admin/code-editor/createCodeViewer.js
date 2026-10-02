import { basicSetup, EditorView } from 'codemirror';
import { Compartment, EditorState } from '@codemirror/state';
import { search, openSearchPanel } from '@codemirror/search';
import { json } from '@codemirror/lang-json';
import { markdown } from '@codemirror/lang-markdown';
import { baseDarkTheme, codeEditorSyntaxHighlighting } from './theme.js';

const viewers = new WeakMap();

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
        baseDarkTheme,
        codeEditorSyntaxHighlighting,
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
