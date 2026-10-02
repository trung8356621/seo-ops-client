import { EditorView } from 'codemirror';
import { HighlightStyle, syntaxHighlighting } from '@codemirror/language';
import { tags as t } from '@lezer/highlight';

export const baseDarkTheme = EditorView.theme({
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
    '.cm-panels': { backgroundColor: '#111827', color: '#f3f4f6', borderBottom: '1px solid #374151' },
    '.cm-search': { padding: '4px 8px' },
    '.cm-search input': { backgroundColor: '#1f2937', color: '#f9fafb', borderColor: '#4b5563', borderRadius: '0.25rem', padding: '2px 6px' },
    '.cm-search button': { backgroundColor: '#374151', color: '#f3f4f6', borderRadius: '0.25rem', padding: '2px 6px', margin: '0 2px' },
    '.cm-search label': { color: '#9ca3af' },
    '.cm-prompt-variable': {
        backgroundColor: 'rgba(245, 158, 11, 0.18)',
        borderBottom: '1px solid #f59e0b',
        borderRadius: '0.2rem',
        color: '#fcd34d',
    },
}, { dark: true });

export const darkHighlightStyle = HighlightStyle.define([
    // Property / Key: cyan / blue
    { tag: t.propertyName, color: '#38bdf8' },

    // String: green (neutral reading tone)
    { tag: [t.string, t.special(t.string)], color: '#4ade80' },

    // Number: violet
    { tag: [t.number, t.integer, t.float], color: '#c084fc' },

    // Boolean: amber / orange
    { tag: t.bool, color: '#fb923c' },

    // Null: muted violet / gray
    { tag: t.null, color: '#94a3b8' },

    // Punctuation & Brackets: neutral gray
    { tag: [t.punctuation, t.separator, t.bracket, t.brace, t.squareBracket, t.angleBracket], color: '#9ca3af' },

    // RED is reserved exclusively for syntax error / invalid tokens
    { tag: t.invalid, color: '#ef4444' },

    // Markdown & common syntax
    { tag: [t.heading, t.heading1, t.heading2, t.heading3, t.heading4], color: '#38bdf8', fontWeight: '600' },
    { tag: t.strong, color: '#f9fafb', fontWeight: 'bold' },
    { tag: t.emphasis, color: '#e5e7eb', fontStyle: 'italic' },
    { tag: t.monospace, color: '#c084fc' },
    { tag: [t.link, t.url], color: '#38bdf8', textDecoration: 'underline' },
    { tag: [t.quote, t.list], color: '#9ca3af' },
    { tag: [t.comment, t.lineComment, t.blockComment], color: '#6b7280' },
    { tag: [t.keyword, t.atom], color: '#fb923c' },
]);

export const codeEditorSyntaxHighlighting = syntaxHighlighting(darkHighlightStyle);
