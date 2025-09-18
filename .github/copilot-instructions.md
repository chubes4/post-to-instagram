# AI Coding Assistant Instructions

Concise, project-specific guidance for AI agents contributing to this WordPress plugin. Focus on existing patterns—avoid introducing frameworks or architectures not already present.

## Core Purpose
Post images from WordPress posts to Instagram (immediate + scheduled) using Instagram Graph API with OAuth 2.0. React (Gutenberg sidebar) frontend + modular PHP backend (PSR-4 + action-driven).

## High-Level Architecture
- Entry: `post-to-instagram.php` defines constants, autoloader (namespaced + legacy `PTI_`), registers Core subsystems.
- Core subsystems (directory: `inc/Core/`):
  - `Auth.php`: OAuth flow, token storage/refresh, rewrite handling (`/pti-oauth/`).
  - `RestApi.php`: Namespaced REST endpoints `pti/v1` bridging JS ↔ server actions.
  - `Admin.php`: Gutenberg integration (enqueue + `wp_localize_script` data contract: `pti_data`).
  - `Actions/Post.php`: Immediate Instagram posting (containers → optional carousel → publish).
  - `Actions/Schedule.php`: Scheduling via post meta + 5‑min cron polling + server-side cropping.
  - `Actions/Cleanup.php`: Daily purge of `/wp-content/uploads/pti-temp/`.
- Frontend assets: `inc/Assets/src/js/` (React components, hooks, utils) compiled by Webpack/`@wordpress/scripts` to `inc/Assets/dist/`.

## Data & Storage Conventions
- Options: `pti_settings` holds `app_id`, `app_secret`, `auth_details{ access_token,user_id,username,expires_at }`.
- Post Meta:
  - `_pti_instagram_shared_images`: [{ image_id, instagram_media_id, timestamp, permalink }]
  - `_pti_instagram_scheduled_posts`: [{ id, image_ids[], crop_data[], caption, schedule_time, status }]
- Temp Files: `/wp-content/uploads/pti-temp/` for cropped/scheduled images (auto-clean daily).
- Transients: `pti_oauth_state_<nonce>` for CSRF state.

## REST API Surface (`/wp-json/pti/v1`)
- Auth: `GET auth/status`, `POST auth/credentials`, `POST disconnect`
- Posting: `POST post-now` (expects `post_id`, `image_urls[]`, `image_ids[]`, optional `caption`)
- Upload: `POST upload-cropped-image` (`cropped_image` file field) → temp URL
- Scheduling: `POST schedule-post` (`post_id`, `image_ids[]`, `crop_data[]`, `schedule_time`, optional `caption`); `GET scheduled-posts[?post_id]`
- All secured by capability checks; additional nonces passed via `pti_data` for client actions.

## Posting Flow (Immediate / Async)
1. JS gathers cropped image blobs → uploads → receives public temp URLs.
2. Calls `post-now` → `Actions/Post::handle_post` creates media containers for each image.
3. If all containers return FINISHED immediately: publish (single or carousel) and respond 200 with result.
4. If any container is still `IN_PROGRESS`: server stores transient with minimal state (container ids + statuses, ready/pending arrays, publish lock fields) and returns 202 + `processing_key`.
5. Frontend sequentially polls `/wp-json/pti/v1/post-status?processing_key=...` every ~4s (awaited loop, no overlapping requests) until status becomes `publishing`, then `completed` or `error`.
6. When all containers are FINISHED server acquires a transient-based publish lock (stale after 180s) and publishes (carousel if >1, else single). Transient cleared on success or terminal error.
7. Success action updates `_pti_instagram_shared_images` and fires `pti_post_success`.

## Scheduling Flow
- Client sends `image_ids` + `crop_data` + `schedule_time`.
- Stored in `_pti_instagram_scheduled_posts` as `pending`.
- Cron (every 5 min) crops original images server-side using stored crop rects, saves temp JPEGs, triggers same posting action.
- On success: remove scheduled entry; on failure: mark `failed` + retain error message.

## OAuth Pattern
- Authorization URL built with nonce-backed `state`; saved as transient.
- Redirect handled by rewrite `/pti-oauth/` → `Auth::handle_oauth_redirect()`.
- Short-lived → long-lived token exchange (60 days) + scheduled refresh (59 days) via `pti_refresh_token`.

## Frontend Integration Contract
- `Admin::localize_editor_data()` injects `pti_data` containing: post context, image IDs (content + shared), auth status (`is_configured`, `is_authenticated`, `auth_url`), nonces, i18n strings, `auth_redirect_status`.
- Maintain backward compatibility if extending: add new keys rather than renaming; check for existence before use in JS.

## Coding Patterns & Conventions
- PHP: Namespaced classes under `PostToInstagram\Core[\Actions]`; keep new code inside `inc/Core` or `inc/Core/Actions` unless clearly cross-cutting.
- Actions/Filters: External extensibility via `do_action('pti_post_success'| 'pti_post_error' | 'pti_schedule_success' | 'pti_schedule_error' | 'pti_cleanup_complete')`—reuse these for instrumentation.
- Security: Always validate capability + nonce for new mutating REST routes; mirror existing args schema style.
- Image handling: For scheduling, replicate crop logic pattern (server-side uses WP_Image_Editor with previously stored `croppedAreaPixels`). Do not introduce external image libs.
- Avoid adding synchronous `sleep` loops; current design prefers non-blocking container creation (future async enhancement).

## Build & Distribution
- Dev: `npm run start` (watch, source maps). Prod: `npm run build`.
- Release packaging: `./build.sh` (excludes sources like `src/`, `node_modules/`, `README.md`, `CLAUDE.md`). If adding new runtime files, ensure not excluded.
- Asset versioning: Uses generated `post-editor.asset.php` for dependency + version; reference only that for enqueue.

## Safe Extension Examples
- Add analytics on successful post: hook into `pti_post_success` rather than modifying `Post::handle_post`.
- Async publishing already implemented (do not reintroduce blocking loops or sleeps). Use `/post-status` endpoint for any UI enhancements needing live status.
- New REST endpoint: add in `RestApi::register_routes()` with namespaced path + capability + args schema; implement minimal logic or delegate to new Action class.
- Additional localized data: extend `Admin::localize_editor_data()` with additive key (e.g., `feature_flags`).

## Common Pitfalls
- Forgetting capability check or nonce → security regression.
- Writing temp files outside `pti-temp` → cleanup system won’t manage them.
- Renaming localized keys breaks existing React code—only add.
- Publishing logic: Respect transient publish lock fields (`publishing`, `publishing_started`, `published`) before triggering a second publish attempt.
- Scheduling: Make sure `schedule_time` stored in ISO string; timezone conversions handled during processing.
- Token refresh: Do not store alternative refresh cadence; rely on `Auth::schedule_token_refresh()`.

## When Unsure
- Mirror existing patterns; prefer action hooks over direct edits where possible.
- Ask user before introducing tests or external dependencies (none currently configured).

## Quick Reference (Key Files)
- Main bootstrap: `post-to-instagram.php`
- Auth: `inc/Core/Auth.php`
- REST: `inc/Core/RestApi.php`
- Posting logic: `inc/Core/Actions/Post.php`
- Scheduling: `inc/Core/Actions/Schedule.php`
- Cleanup: `inc/Core/Actions/Cleanup.php`
- Editor integration: `inc/Core/Admin.php`
- React entry: `inc/Assets/src/js/post-editor.js`

Keep responses minimal, aligned with these conventions, and avoid speculative refactors.