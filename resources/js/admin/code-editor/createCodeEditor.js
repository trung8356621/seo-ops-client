import { basicSetup, EditorView } from 'codemirror';
import { Compartment, EditorState } from '@codemirror/state';

const editorTheme = EditorView.theme({
    '&': {
        backgroundColor: '#030712',
        color: '#f3f4f6',
        fontSize: '0.875rem',
        minHeight: 'var(--code-editor-min-height, 20rem)',
        maxHeight: '70vh',
    },
    '&.cm-focused': { outline: 'none' },
    '.cm-scroller': {
        fontFamily: 'ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace',
        lineHeight: '1.5rem',
        overflow: 'auto',
    },
    '.cm-gutters': {
        backgroundColor: '#111827',
        borderRight: '1px solid #374151',
        color: '#6b7280',
    },
    '.cm-activeLine, .cm-activeLineGutter': { backgroundColor: '#111827' },
    '.cm-selectionBackground, &.cm-focused .cm-selectionBackground': { backgroundColor: '#374151 !important' },
    '.cm-content': { caretColor: '#fff', padding: '0.75rem 0' },
    '.cm-line': { padding: '0 1rem' },
    '.cm-tooltip': { backgroundColor: '#111827', borderColor: '#4b5563' },
    '.cm-panels': { backgroundColor: '#111827', color: '#f3f4f6' },
    '.cm-prompt-variable': {
        backgroundColor: 'rgba(245, 158, 11, 0.18)',
        borderBottom: '1px solid #f59e0b',
        borderRadius: '0.2rem',
        color: '#fcd34d',
    },
}, { dark: true });

export function createCodeEditor({ parent, value, language, extensions = [], disabled = false, onChange }) {
    const editable = new Compartment();
    const view = new EditorView({
        parent,
        state: EditorState.create({
            doc: value ?? '',
            extensions: [
                basicSetup,
                EditorView.lineWrapping,
                editorTheme,
                language,
                ...extensions,
                editable.of(EditorView.editable.of(! disabled)),
                EditorView.updateListener.of((update) => {
                    if (update.docChanged) onChange(update.state.doc.toString());
                }),
            ],
        }),
    });

    return {
        view,
        focus: () => view.focus(),
        getValue: () => view.state.doc.toString(),
        setValue(nextValue) {
            const next = nextValue ?? '';
            if (next === view.state.doc.toString()) return;
            view.dispatch({ changes: { from: 0, to: view.state.doc.length, insert: next } });
        },
        setDisabled(nextDisabled) {
            view.dispatch({ effects: editable.reconfigure(EditorView.editable.of(! nextDisabled)) });
        },
        destroy: () => view.destroy(),
    };
}

export function replaceEditorDocument(editor, value) {
    editor.view.dispatch({
        changes: { from: 0, to: editor.view.state.doc.length, insert: value },
        selection: { anchor: 0 },
        scrollIntoView: true,
    });
}
