import { basicSetup, EditorView } from 'codemirror';
import { Compartment, EditorState } from '@codemirror/state';
import { baseDarkTheme, codeEditorSyntaxHighlighting } from './theme.js';

export function createCodeEditor({ parent, value, language, extensions = [], disabled = false, onChange }) {
    const editable = new Compartment();
    const view = new EditorView({
        parent,
        state: EditorState.create({
            doc: value ?? '',
            extensions: [
                basicSetup,
                EditorView.lineWrapping,
                baseDarkTheme,
                codeEditorSyntaxHighlighting,
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
