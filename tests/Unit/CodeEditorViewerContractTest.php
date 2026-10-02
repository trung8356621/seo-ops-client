<?php

declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

final class CodeEditorViewerContractTest extends TestCase
{
    public function test_create_code_viewer_module_exports_and_contracts(): void
    {
        $viewerJs = (string) file_get_contents(resource_path('js/admin/code-editor/createCodeViewer.js'));

        self::assertStringContainsString('export function createCodeViewer', $viewerJs);
        self::assertStringContainsString('export function isJsonContent', $viewerJs);
        self::assertStringContainsString('export function resolveViewerLanguage', $viewerJs);

        // Read-only and search configuration
        self::assertStringContainsString('EditorState.readOnly.of(true)', $viewerJs);
        self::assertStringContainsString('EditorView.editable.of(false)', $viewerJs);
        self::assertStringContainsString('basicSetup', $viewerJs);
        self::assertStringContainsString('search({ top: true })', $viewerJs);
        self::assertStringContainsString('openSearchPanel', $viewerJs);
        self::assertStringContainsString('wrapCompartment.of(wrap ? EditorView.lineWrapping : [])', $viewerJs);

        // Language resolution
        self::assertStringContainsString("language === 'auto'", $viewerJs);
        self::assertStringContainsString("language === 'json'", $viewerJs);
        self::assertStringContainsString("language === 'markdown'", $viewerJs);

        // Instance lifecycle and leak prevention
        self::assertStringContainsString('viewers.has(container)', $viewerJs);
        self::assertStringContainsString('viewers.get(container).destroy()', $viewerJs);
        self::assertStringContainsString('view.destroy()', $viewerJs);
    }

    public function test_code_editor_bundle_entry_registers_viewer_abstractions(): void
    {
        $indexJs = (string) file_get_contents(resource_path('js/admin/code-editor/index.js'));

        self::assertStringContainsString('createCodeViewer', $indexJs);
        self::assertStringContainsString('createCodeEditor', $indexJs);
        self::assertStringContainsString('window.createCodeViewer = createCodeViewer', $indexJs);
        self::assertStringContainsString('window.CodeEditor =', $indexJs);
    }

    public function test_code_editor_styles_include_code_viewer_surface(): void
    {
        $css = (string) file_get_contents(resource_path('js/admin/code-editor/codeEditor.css'));

        self::assertStringContainsString('.code-viewer-surface', $css);
        self::assertStringContainsString('.code-viewer-surface .cm-editor', $css);
        self::assertStringContainsString('.code-viewer-surface .cm-scroller', $css);
    }

    public function test_view_article_prompts_uses_shared_code_viewer_in_history_drawer(): void
    {
        $blade = (string) file_get_contents(
            dirname(__DIR__, 3).'/omnichannel-addons/seo-content-ai-compat/resources/views/filament/resources/article-resource/pages/view-article-prompts.blade.php'
        );

        // Shared build-code-editor asset bundle reuse via @once
        self::assertStringContainsString("@once", $blade);
        self::assertStringContainsString("@vite(['resources/js/admin/code-editor/index.js'], 'build-code-editor')", $blade);

        // Alpine wiring
        self::assertStringContainsString('window.createCodeViewer', $blade);
        self::assertStringContainsString("language: 'markdown'", $blade);
        self::assertStringContainsString("language: 'auto'", $blade);
        self::assertStringContainsString('renderViewers', $blade);
        self::assertStringContainsString('destroyViewers', $blade);
        self::assertStringContainsString('$cleanup', $blade);

        // Preserved Copy and Search buttons
        self::assertStringContainsString('copyText(drawerPrompt)', $blade);
        self::assertStringContainsString('copyText(drawerResult)', $blade);
        self::assertStringContainsString('openPromptSearch()', $blade);
        self::assertStringContainsString('openResultSearch()', $blade);

        // Surfaces replace raw pre in columns
        self::assertStringContainsString('x-ref="promptSurface"', $blade);
        self::assertStringContainsString('x-ref="resultSurface"', $blade);
        self::assertStringContainsString('code-viewer-surface', $blade);
        self::assertStringNotContainsString('<pre class="min-h-0 flex-1 overflow-auto whitespace-pre-wrap', $blade);
    }

    public function test_shared_codemirror_theme_neutral_palette_and_reuse(): void
    {
        $themeJs = (string) file_get_contents(resource_path('js/admin/code-editor/theme.js'));
        $editorJs = (string) file_get_contents(resource_path('js/admin/code-editor/createCodeEditor.js'));
        $viewerJs = (string) file_get_contents(resource_path('js/admin/code-editor/createCodeViewer.js'));

        // Property / key: cyan / blue (#38bdf8)
        self::assertStringContainsString("tag: t.propertyName, color: '#38bdf8'", $themeJs);

        // String: green (#4ade80)
        self::assertStringContainsString("color: '#4ade80'", $themeJs);

        // Number: violet (#c084fc)
        self::assertStringContainsString("color: '#c084fc'", $themeJs);

        // Boolean: amber / orange (#fb923c)
        self::assertStringContainsString("tag: t.bool, color: '#fb923c'", $themeJs);

        // Null: muted violet / gray (#94a3b8)
        self::assertStringContainsString("tag: t.null, color: '#94a3b8'", $themeJs);

        // Punctuation / brackets: neutral gray (#9ca3af)
        self::assertStringContainsString("color: '#9ca3af'", $themeJs);

        // Line numbers: muted gray (#6b7280)
        self::assertStringContainsString("color: '#6b7280'", $themeJs);

        // Red is strictly for invalid / syntax error
        self::assertStringContainsString("tag: t.invalid, color: '#ef4444'", $themeJs);

        // Both editor and viewer reuse baseDarkTheme and codeEditorSyntaxHighlighting
        self::assertStringContainsString("from './theme.js'", $editorJs);
        self::assertStringContainsString("baseDarkTheme", $editorJs);
        self::assertStringContainsString("codeEditorSyntaxHighlighting", $editorJs);

        self::assertStringContainsString("from './theme.js'", $viewerJs);
        self::assertStringContainsString("baseDarkTheme", $viewerJs);
        self::assertStringContainsString("codeEditorSyntaxHighlighting", $viewerJs);
    }
}
