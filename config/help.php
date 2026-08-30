<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public Help repository (seo-ops-help)
    |--------------------------------------------------------------------------
    |
    | Runtime reads public raw content (no token).
    | Admin publish uses GitHub Contents API with HELP_GITHUB_TOKEN (server-side only).
    |
    | `groups` = canonical Help Group Registry (labels / prefixes).
    | Default sort_order here; runtime/Admin override via Help repo groups.json
    | (cached). Admin + Global Help share the same order source.
    | context_prefix = context key prefix for new topics.
    |
    */

    'github' => [
        'owner' => env('HELP_GITHUB_OWNER', ''),
        'repo' => env('HELP_GITHUB_REPO', 'seo-ops-help'),
        'branch' => env('HELP_GITHUB_BRANCH', 'main'),
        'token' => env('HELP_GITHUB_TOKEN', ''),
        'user_agent' => env('HELP_GITHUB_USER_AGENT', 'seo-ops-client-help'),
    ],

    'cache' => [
        'path' => storage_path('app/help-cache'),
        'check_ttl_seconds' => (int) env('HELP_CACHE_CHECK_TTL', 3600),
    ],

    /*
    | Local sibling repo (seo-ops-help) via junction: .local/help-repo
    | Run: scripts/link-help-repo.ps1 (Windows) or scripts/link-help-repo.sh
    | enabled: null = auto when APP_ENV=local and path exists
    */
    'local' => [
        'enabled' => env('HELP_LOCAL_REPO'),
        'path' => env('HELP_LOCAL_REPO_PATH', base_path('.local/help-repo')),
    ],

    'groups' => [
        'getting-started' => [
            'id' => 'getting-started',
            'title' => 'Getting Started',
            'modalTitle' => 'Getting Started',
            'sort_order' => 10,
            'context_prefix' => 'getting_started',
        ],
        'overview' => [
            'id' => 'overview',
            'title' => 'Tổng quan',
            'modalTitle' => 'Hướng dẫn hệ thống',
            'sort_order' => 20,
            'context_prefix' => 'overview',
        ],
        'dashboard' => [
            'id' => 'dashboard',
            'title' => 'Dashboard',
            'modalTitle' => 'Hướng dẫn Dashboard',
            'sort_order' => 30,
            'context_prefix' => 'dashboard',
        ],
        'websites-domains' => [
            'id' => 'websites-domains',
            'title' => 'Websites & Domains',
            'modalTitle' => 'Websites & Domains',
            'sort_order' => 40,
            'context_prefix' => 'domain',
        ],
        'content-planning' => [
            'id' => 'content-planning',
            'title' => 'Content Planning',
            'modalTitle' => 'Content Planning',
            'sort_order' => 50,
            'context_prefix' => 'content_project',
        ],
        'articles' => [
            'id' => 'articles',
            'title' => 'Quản lý bài viết',
            'modalTitle' => 'Hướng dẫn quản lý bài viết',
            'sort_order' => 60,
            'context_prefix' => 'article',
        ],
        'article-editor' => [
            'id' => 'article-editor',
            'title' => 'Article Editor',
            'modalTitle' => 'Hướng dẫn Article Editor',
            'sort_order' => 70,
            'context_prefix' => 'article_editor',
        ],
        'writing-posts' => [
            'id' => 'writing-posts',
            'title' => 'Writing & Posts',
            'modalTitle' => 'Writing & Posts',
            'sort_order' => 80,
            'context_prefix' => 'writing',
        ],
        'keywords-topics' => [
            'id' => 'keywords-topics',
            'title' => 'Keywords & Topics',
            'modalTitle' => 'Keywords & Topics',
            'sort_order' => 90,
            'context_prefix' => 'topic',
        ],
        'seo' => [
            'id' => 'seo',
            'title' => 'SEO & Keywords',
            'modalTitle' => 'Hướng dẫn SEO',
            'sort_order' => 100,
            'context_prefix' => 'seo',
        ],
        'seo-indexing' => [
            'id' => 'seo-indexing',
            'title' => 'SEO & Indexing',
            'modalTitle' => 'SEO & Indexing',
            'sort_order' => 110,
            'context_prefix' => 'seo_indexing',
        ],
        'media' => [
            'id' => 'media',
            'title' => 'Media',
            'modalTitle' => 'Hướng dẫn Media',
            'sort_order' => 120,
            'context_prefix' => 'media',
        ],
        'publishing' => [
            'id' => 'publishing',
            'title' => 'Publishing',
            'modalTitle' => 'Publishing',
            'sort_order' => 130,
            'context_prefix' => 'publishing',
        ],
        'sync-queue' => [
            'id' => 'sync-queue',
            'title' => 'Đồng bộ WordPress',
            'modalTitle' => 'Hướng dẫn đồng bộ WordPress',
            'sort_order' => 140,
            'context_prefix' => 'sync',
        ],
        'settings' => [
            'id' => 'settings',
            'title' => 'Cấu hình hệ thống',
            'modalTitle' => 'Hướng dẫn cấu hình',
            'sort_order' => 150,
            'context_prefix' => 'settings',
        ],
        'account-settings' => [
            'id' => 'account-settings',
            'title' => 'Account & Settings',
            'modalTitle' => 'Account & Settings',
            'sort_order' => 160,
            'context_prefix' => 'account',
        ],
    ],

];
