import { json, jsonParseLinter } from '@codemirror/lang-json';
import { lintGutter, linter } from '@codemirror/lint';
import { createCodeEditor, replaceEditorDocument } from './createCodeEditor.js';

function jsonErrorLocation(error, raw) {
    const match = String(error.message || '').match(/position\s+(\d+)/i);
    if (! match) return error.message || 'Không thể phân tích JSON';
    const position = Number(match[1]);
    const before = raw.slice(0, position);
    return `dòng ${before.split('\n').length}, cột ${position - before.lastIndexOf('\n')}: ${error.message}`;
}

export function mountJsonEditor(root, bridge) {
    let validationTimer;
    let validationRequest = 0;
    const status = root.querySelector('[data-code-editor-status]');
    const editor = createCodeEditor({
        parent: root.querySelector('[data-code-editor-surface]'),
        value: bridge.get(),
        language: json(),
        extensions: [lintGutter(), linter(jsonParseLinter())],
        disabled: bridge.disabled,
        onChange(value) {
            bridge.set(value);
            validate(value);
        },
    });

    function show(kind, message) {
        status.dataset.status = kind;
        status.textContent = message;
        status.className = `code-editor-status code-editor-status--${kind}`;
    }

    function validate(raw, validateSchema = true) {
        clearTimeout(validationTimer);
        if (raw.trim() === '') {
            show('empty', 'Dán hoặc nhập Industry Context JSON.');
            return false;
        }

        try {
            JSON.parse(raw);
        } catch (error) {
            ++validationRequest;
            show('error', `✕ JSON không hợp lệ — ${jsonErrorLocation(error, raw)}`);
            return false;
        }

        if (! validateSchema) return true;
        show('pending', '✓ Cú pháp JSON hợp lệ · Đang kiểm tra Industry Context...');
        const request = ++validationRequest;
        validationTimer = setTimeout(async () => {
            const result = await bridge.call('validateIndustryContextJson', raw, root.dataset.schemaType);
            if (request !== validationRequest || raw !== editor.getValue()) return;
            show(result.valid ? 'valid' : 'warning', result.valid
                ? '✓ Industry Context hợp lệ'
                : `⚠ JSON đúng cú pháp nhưng không đúng Industry Context Schema — ${result.message}`);
        }, 350);
        return true;
    }

    root.querySelector('[data-code-editor-copy]').addEventListener('click', () => bridge.copy(editor.getValue(), editor));
    root.querySelector('[data-code-editor-format]').addEventListener('click', () => {
        const raw = editor.getValue();
        if (! validate(raw, false)) {
            editor.focus();
            return;
        }
        replaceEditorDocument(editor, JSON.stringify(JSON.parse(raw), null, 2));
        validate(editor.getValue());
    });
    validate(editor.getValue());

    return editor;
}
