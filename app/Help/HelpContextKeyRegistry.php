<?php

declare(strict_types=1);

namespace App\Help;

/**
 * Product-semantic Help context keys owned by seo-ops-client.
 * Not route/Livewire/Blade implementation paths.
 */
final class HelpContextKeyRegistry
{
    /**
     * @return list<array{key: string, group: string, label: string}>
     */
    public static function all(): array
    {
        return [
            [
                'key' => 'domain.website_type',
                'group' => 'websites-domains',
                'label' => 'Website Type',
            ],
            [
                'key' => 'domain.platform',
                'group' => 'websites-domains',
                'label' => 'Platform',
            ],
            [
                'key' => 'domain.api_access',
                'group' => 'websites-domains',
                'label' => 'API Access',
            ],
            [
                'key' => 'domain.sync.force_full',
                'group' => 'websites-domains',
                'label' => 'Force Full Sync',
            ],
            [
                'key' => 'content_project.item.tone',
                'group' => 'content-planning',
                'label' => 'Content Tone',
            ],
            [
                'key' => 'content_project.item.content_length',
                'group' => 'content-planning',
                'label' => 'Content Length',
            ],
            [
                'key' => 'content_project.item.generation_mode',
                'group' => 'content-planning',
                'label' => 'Generation Mode',
            ],
            [
                'key' => 'keyword.focus_article',
                'group' => 'keywords-topics',
                'label' => 'Focus Article',
            ],
            [
                'key' => 'topic.lock',
                'group' => 'keywords-topics',
                'label' => 'Topic Lock',
            ],
            [
                'key' => 'topic.form_keyword',
                'group' => 'keywords-topics',
                'label' => 'Form Keyword',
            ],

            // Article Editor — main widgets
            [
                'key' => 'article_editor.widget.google_preview',
                'group' => 'article-editor',
                'label' => 'Google Preview',
            ],
            [
                'key' => 'article_editor.widget.outline',
                'group' => 'article-editor',
                'label' => 'Outline',
            ],
            [
                'key' => 'article_editor.widget.find_replace',
                'group' => 'article-editor',
                'label' => 'Find & Replace',
            ],
            [
                'key' => 'article_editor.widget.editor_toolbar',
                'group' => 'article-editor',
                'label' => 'Editor Toolbar',
            ],
            [
                'key' => 'article_editor.widget.section_blocks',
                'group' => 'article-editor',
                'label' => 'Section Blocks',
            ],
            [
                'key' => 'article_editor.widget.html_mode',
                'group' => 'article-editor',
                'label' => 'HTML Mode',
            ],

            // Article Editor — panels
            [
                'key' => 'article_editor.panel.seo',
                'group' => 'article-editor',
                'label' => 'SEO Panel',
            ],
            [
                'key' => 'article_editor.panel.images',
                'group' => 'article-editor',
                'label' => 'Images Panel',
            ],
            [
                'key' => 'article_editor.panel.featured',
                'group' => 'article-editor',
                'label' => 'Featured Panel',
            ],
            [
                'key' => 'article_editor.panel.links',
                'group' => 'article-editor',
                'label' => 'Links Panel',
            ],
            [
                'key' => 'article_editor.panel.cta',
                'group' => 'article-editor',
                'label' => 'CTA Panel',
            ],
            [
                'key' => 'article_editor.panel.vocabulary',
                'group' => 'article-editor',
                'label' => 'Vocabulary Panel',
            ],
            [
                'key' => 'article_editor.panel.publishing',
                'group' => 'article-editor',
                'label' => 'Publishing Panel',
            ],

            // Article Editor — SEO widgets
            [
                'key' => 'article_editor.widget.live_score',
                'group' => 'article-editor',
                'label' => 'Live SEO Score',
            ],
            [
                'key' => 'article_editor.widget.focus_keyword',
                'group' => 'article-editor',
                'label' => 'Focus Keyword',
            ],
            [
                'key' => 'article_editor.widget.title_length',
                'group' => 'article-editor',
                'label' => 'Title Length',
            ],
            [
                'key' => 'article_editor.widget.image_count',
                'group' => 'article-editor',
                'label' => 'Image Count',
            ],
            [
                'key' => 'article_editor.widget.external_links',
                'group' => 'article-editor',
                'label' => 'External Links',
            ],
            [
                'key' => 'article_editor.widget.featured_snippet_table',
                'group' => 'article-editor',
                'label' => 'Featured Snippet / Table',
            ],

            // Settings / Workflows
            [
                'key' => 'settings.workflow.overview',
                'group' => 'settings',
                'label' => 'Workflow Settings',
            ],
            [
                'key' => 'settings.workflow.task_workflows',
                'group' => 'settings',
                'label' => 'Task Workflows',
            ],
            [
                'key' => 'settings.workflow.prompt_hooks',
                'group' => 'settings',
                'label' => 'Prompt Hooks',
            ],
            [
                'key' => 'settings.workflow.prompt_binding',
                'group' => 'settings',
                'label' => 'Prompt Binding',
            ],
            [
                'key' => 'settings.workflow.prompt_selector',
                'group' => 'settings',
                'label' => 'Chọn Prompt',
            ],
            [
                'key' => 'settings.workflow.default_guidance',
                'group' => 'settings',
                'label' => 'Default Guidance',
            ],
            [
                'key' => 'settings.workflow.editor_media',
                'group' => 'settings',
                'label' => 'Editor Media Sources',
            ],
            [
                'key' => 'settings.workflow.product_gallery',
                'group' => 'settings',
                'label' => 'Product Gallery Source',
            ],
            [
                'key' => 'settings.workflow.workflow_extract',
                'group' => 'settings',
                'label' => 'Workflow Media Extract',
            ],
            [
                'key' => 'settings.scoring.rules',
                'group' => 'settings',
                'label' => 'SEO Scoring Rules',
            ],
            [
                'key' => 'settings.editor.history_autosave',
                'group' => 'settings',
                'label' => 'Editor History & Autosave',
            ],
            [
                'key' => 'settings.editor.wiki_trust',
                'group' => 'settings',
                'label' => 'Wiki Trust Domains',
            ],
            [
                'key' => 'settings.editor.faq_catch',
                'group' => 'settings',
                'label' => 'FAQ Catch Keywords',
            ],
            [
                'key' => 'settings.general.datetime',
                'group' => 'settings',
                'label' => 'Date & Time',
            ],
            [
                'key' => 'settings.general.content_language',
                'group' => 'settings',
                'label' => 'Default Content Language',
            ],
            [
                'key' => 'settings.general.team_chat',
                'group' => 'settings',
                'label' => 'Team Chat Uploads',
            ],
            [
                'key' => 'settings.ai.typography_validation',
                'group' => 'settings',
                'label' => 'Typography Validation',
            ],
            [
                'key' => 'settings.keywords.cta_blacklist',
                'group' => 'settings',
                'label' => 'CTA Keyword Blacklist',
            ],
            [
                'key' => 'domain.global_cta',
                'group' => 'websites-domains',
                'label' => 'Global CTA Contacts',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_map(
            static fn (array $row): string => $row['key'],
            self::all(),
        ));
    }

    public static function has(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }
}
