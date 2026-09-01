---
key: settings.workflow.media_pipeline
title: Media Generation Workflow
summary: Recommended pipeline from article content to typography image (optional vision validation).
group: settings
sort_order: 56
keywords:
  - workflow
  - blueprint
  - typography
  - vision
updated_at: '2026-08-31'
---
# Media Generation Workflow

Recommended operator flow khi tạo ảnh typography từ nội dung bài.

## Current recommendation

1. Article
2. Blueprint (LLM)
3. Typography Image
4. (Optional Vision Validation)

## Note

Do not send raw articles directly to Image models if the content is long.

## Where to configure

Settings → Workflows — Editor media sources and Prompt Hooks.
