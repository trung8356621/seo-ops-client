import React, { useEffect, useMemo, useRef, useState } from 'react';
import { useEditor, EditorContent } from '@tiptap/react';
import { createEditorRuntime } from '@content-addon/editor/runtime/createEditorRuntime';
import { coreModule } from '@content-addon/editor/modules/core';
import BlockFormatToolbar from '@content-addon/components/BlockFormatToolbar';
import { safeFaqEditorHtml } from '@content-addon/components/FaqAnswerEditor';

/**
 * Help content editor — same TipTap engine / BlockFormatToolbar as Article,
 * preset = coreModule only (no SEO / AI / FAQ / media modules).
 * Does NOT mount SeoArticleEditor.
 */

const HELP_EDITOR_PROPS = Object.freeze({
    attributes: Object.freeze({
        class: 'prose prose-slate max-w-none dark:prose-invert min-h-[320px] focus:outline-none tiptap-editor-content help-content-editor',
    }),
});

function createHelpEditorRuntime() {
    return createEditorRuntime({
        modules: [coreModule],
        context: {
            session: { writable: true, status: 'active' },
        },
        failFast: false,
        mode: 'production',
    });
}

function normalizeHelpHtml(html) {
    const raw = String(html ?? '').trim();
    return raw !== '' ? raw : '<p></p>';
}

/**
 * @param {{
 *   html?: string,
 *   onChange?: (html: string) => void,
 * }} props
 */
export default function HelpContentEditor({ html = '<p></p>', onChange }) {
    const onChangeRef = useRef(onChange);
    onChangeRef.current = onChange;
    const [htmlModalOpen, setHtmlModalOpen] = useState(false);
    const [htmlDraft, setHtmlDraft] = useState('');

    const runtime = useMemo(() => createHelpEditorRuntime(), []);
    const documentExtensions = useMemo(
        () => runtime.getDocumentExtensions(),
        [runtime],
    );

    useEffect(() => {
        window.__STANDALONE_EDITOR_WRITABLE__ = true;
        return () => {
            window.__STANDALONE_EDITOR_WRITABLE__ = false;
            try {
                runtime.destroy?.();
            } catch {
                // ignore
            }
        };
    }, [runtime]);

    const editor = useEditor({
        extensions: documentExtensions,
        content: normalizeHelpHtml(html),
        editorProps: HELP_EDITOR_PROPS,
        onUpdate: ({ editor: ed }) => {
            const next = safeFaqEditorHtml(ed);
            if (next == null) {
                return;
            }
            onChangeRef.current?.(next);
        },
    }, [documentExtensions]);

    useEffect(() => {
        if (!editor || editor.isDestroyed || !editor.view) {
            return;
        }
        const next = normalizeHelpHtml(html);
        const current = safeFaqEditorHtml(editor);
        if (current == null || current === next) {
            return;
        }
        try {
            editor.commands.setContent(next, false);
        } catch {
            // ignore remount races
        }
    }, [html, editor]);

    useEffect(() => () => {
        if (editor && !editor.isDestroyed) {
            try {
                editor.destroy();
            } catch {
                // ignore
            }
        }
    }, [editor]);

    const openHtmlInspector = () => {
        const current = safeFaqEditorHtml(editor);
        setHtmlDraft(current ?? '');
        setHtmlModalOpen(true);
    };

    const applyHtmlInspector = () => {
        if (!editor || editor.isDestroyed) {
            return;
        }
        try {
            editor.commands.setContent(normalizeHelpHtml(htmlDraft), false);
            onChangeRef.current?.(safeFaqEditorHtml(editor) ?? htmlDraft);
        } catch {
            // ignore
        }
        setHtmlModalOpen(false);
    };

    const insertImageByUrl = () => {
        if (!editor || editor.isDestroyed) {
            return;
        }
        const url = window.prompt('Image URL (Help repo images/… or CDN)');
        if (!url) {
            return;
        }
        editor.chain().focus().setImage({ src: url.trim() }).run();
    };

    if (!editor || editor.isDestroyed) {
        return <div className="p-3 text-sm text-gray-500 animate-pulse">Đang tải editor…</div>;
    }

    return (
        <div className="seo-faq-answer-wrap help-content-editor-wrap border border-gray-200 dark:border-gray-700 rounded-md bg-white dark:bg-gray-900 overflow-hidden">
            <BlockFormatToolbar
                editor={editor}
                canDelete={false}
                runtime={runtime}
                showFaqExtract={false}
                onViewHtml={openHtmlInspector}
                onEditLink={({ from, to }) => {
                    const prev = editor.getAttributes('link').href || '';
                    const url = window.prompt('URL', prev);
                    if (url === null) {
                        return;
                    }
                    if (url === '') {
                        editor.chain().focus().setTextSelection({ from, to }).extendMarkRange('link').unsetLink().run();
                        return;
                    }
                    editor.chain().focus().setTextSelection({ from, to }).extendMarkRange('link').setLink({ href: url }).run();
                }}
            />
            <div className="seo-toolbar-row seo-toolbar-row--insert px-2 py-1.5">
                <button
                    type="button"
                    className="seo-insert-toolbar-btn"
                    onMouseDown={(e) => e.preventDefault()}
                    onClick={insertImageByUrl}
                >
                    <span className="seo-insert-toolbar-btn__label">Image URL</span>
                </button>
            </div>
            <div className="px-3 py-2">
                <EditorContent editor={editor} />
            </div>

            {htmlModalOpen ? (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
                    <div className="w-full max-w-3xl rounded-lg bg-white dark:bg-gray-900 shadow-xl border border-gray-200 dark:border-gray-700 p-4 space-y-3">
                        <div className="text-sm font-medium">HTML</div>
                        <textarea
                            className="w-full h-64 font-mono text-xs rounded border border-gray-300 dark:border-gray-600 dark:bg-gray-800 p-2"
                            value={htmlDraft}
                            onChange={(e) => setHtmlDraft(e.target.value)}
                        />
                        <div className="flex justify-end gap-2">
                            <button type="button" className="px-3 py-1.5 text-sm rounded border" onClick={() => setHtmlModalOpen(false)}>
                                Cancel
                            </button>
                            <button type="button" className="px-3 py-1.5 text-sm rounded bg-amber-500 text-white" onClick={applyHtmlInspector}>
                                Apply
                            </button>
                        </div>
                    </div>
                </div>
            ) : null}
        </div>
    );
}

export { createHelpEditorRuntime };
