---
key: media.image_routing
title: Image Routing
summary: Best-practice model choices for hero, gallery, and typography/infographic images.
group: media
sort_order: 10
keywords:
  - imagen
  - routing
  - hero
  - gallery
  - typography
updated_at: '2026-08-31'
---
# Image Routing

Hướng dẫn chọn model ảnh theo loại media. Đây là best practice cho operator — không đổi runtime routing tự động.

## Current recommendation

| Use case | Model |
| --- | --- |
| General Image | Imagen 4 |
| Typography | Gemini 3.1 Flash Image Preview |
| Video | (Current Default) |

## Recommended by use case

| Use case | Recommended |
| --- | --- |
| Hero / Banner | Imagen 4 |
| Product Gallery | Imagen 4 |
| Infographic / Typography | Gemini 3.1 Flash Image Preview |

## Reason

Gemini 3.1 Flash Image Preview currently renders Vietnamese typography significantly better than Imagen 4 during internal testing.

## Where to configure

Settings → AI Center / AI Advanced — model priority and usage mode.
