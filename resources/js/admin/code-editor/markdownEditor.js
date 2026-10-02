import { markdown } from '@codemirror/lang-markdown';
import { Decoration, ViewPlugin } from '@codemirror/view';
import { RangeSetBuilder } from '@codemirror/state';
import { createCodeEditor } from './createCodeEditor.js';

const variableMark = Decoration.mark({ class: 'cm-prompt-variable' });

function variableDecorations(view) {
    const ranges = new RangeSetBuilder();
    const pattern = /{{[A-Za-z_][A-Za-z0-9_]*}}/g;
    for (const { from, to } of view.visibleRanges) {
        const text = view.state.doc.sliceString(from, to);
        for (const match of text.matchAll(pattern)) {
            ranges.add(from + match.index, from + match.index + match[0].length, variableMark);
        }
    }
    return ranges.finish();
}

const promptVariableHighlights = ViewPlugin.fromClass(class {
    constructor(view) {
        this.decorations = variableDecorations(view);
    }

    update(update) {
        if (update.docChanged || update.viewportChanged) this.decorations = variableDecorations(update.view);
    }
}, { decorations: (plugin) => plugin.decorations });

export function mountMarkdownEditor(root, bridge) {
    const editor = createCodeEditor({
        parent: root.querySelector('[data-code-editor-surface]'),
        value: bridge.get(),
        language: markdown(),
        extensions: [promptVariableHighlights],
        disabled: bridge.disabled,
        onChange: bridge.set,
    });

    root.querySelector('[data-code-editor-copy]').addEventListener('click', () => bridge.copy(editor.getValue(), editor));
    return editor;
}
