# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Development Commands

### Build & Development
- `npm run build` - Build production assets and automatically create distribution zip
- `npm run start` - Start development mode with watch
- `./build-dist.sh` - Create production-ready plugin distribution zip (called automatically by npm run build)

### Project Structure
No specific test framework is configured. Check with the user before implementing tests.

## Code Architecture

This is a WordPress plugin for posting images from WordPress posts to Instagram using OAuth 2.0 authentication. The architecture uses a modular PHP backend with PSR-4 autoloading and a modern React frontend.

### Autoloading & Class Structure
- **PSR-4 Autoloader**: Handles both legacy PTI_ classes and modern PostToInstagram\ namespaced classes
- **File Organization**: Centralized architecture under `/inc/Core/` with action-based patterns
- **WordPress Standards**: Follows WordPress coding standards with proper escaping and sanitization

### Core Components

**PHP Backend:**
- `post-to-instagram.php` - Main plugin file with autoloader and initialization
- `inc/Core/Admin.php` - Admin interface management and asset loading
- `inc/Core/Auth.php` - Instagram OAuth flow and token management
- `inc/Core/RestApi.php` - REST API endpoints for frontend communication
- `inc/Core/Actions/Post.php` - Instagram Graph API integration for posting
- `inc/Core/Actions/Schedule.php` - WP-Cron based scheduling system
- `inc/Core/Actions/Cleanup.php` - Daily cleanup of temporary files

**JavaScript Frontend (React):**
- `inc/Assets/src/js/post-editor.js` - Main Gutenberg sidebar plugin entry point
- `inc/Assets/src/js/components/` - React components (AuthPanel, CropImageModal, ScheduledPosts, CaptionInput, CustomImageSelectModal, SidebarPanelContent)
- `inc/Assets/src/js/hooks/` - Custom React hooks (useInstagramAuth, useInstagramPostActions)
- `inc/Assets/src/js/utils/` - Utility functions (cropImage, cropUtils, getPostImageIds)

### REST API Endpoints (/pti/v1/)
- **Authentication**: `/auth/status`, `/auth/credentials`, `/disconnect`
- **Image Processing**: `/upload-cropped-image` (uploads to temp directory)
- **Posting**: `/post-now` (immediate posting), `/schedule-post`, `/scheduled-posts`
- **Security**: All endpoints use WordPress nonces and capability checks

### Key Features
- OAuth 2.0 Instagram authentication
- Block editor integration with sidebar panel
- Image selection from post content (Gutenberg blocks + featured image)
- Drag-and-drop image reordering for carousels (max 10 images)
- Image cropping to Instagram aspect ratios (1:1, 4:5, 3:4, 1.91:1)
- Immediate posting and scheduling with WP-Cron
- Temporary image storage in `/wp-content/uploads/pti-temp/`

### Data Flow & Processing Model
**Hybrid Processing Architecture:**
1. **Image Selection**: Gutenberg content analysis → user selection → drag-and-drop reordering
2. **Client-Side Processing**: Images cropped using custom `cropImage` and `cropUtils` → canvas processing → blob creation
3. **Temporary Storage**: Cropped images uploaded to `/wp-content/uploads/pti-temp/` via REST API
4. **Instagram API**: Creates media containers → polls for FINISHED status → publishes
5. **Metadata Tracking**: Stores shared images in post meta `_pti_instagram_shared_images`

**OAuth Integration Pattern:**
- **Popup Communication**: Uses PostMessage API for seamless OAuth flow
- **State Management**: WordPress transients store OAuth state with CSRF protection
- **Token Exchange**: Short-lived tokens exchanged for long-lived access tokens
- **URL Rewriting**: Custom rewrite rules handle `/pti-oauth/` redirect endpoints

**Scheduling Architecture:**
- **Dual Processing**: Immediate posting (client-side crop) vs scheduled (server-side crop)
- **WP-Cron Integration**: Custom 5-minute interval processes scheduled posts
- **Server-Side Cropping**: Uses WordPress image editor for delayed execution
- **Error Recovery**: Failed posts marked with error messages, not removed from queue
- **Race Condition Protection**: Atomic `isPosting.current` ref prevents duplicate submissions

### WordPress Integration
- Uses Gutenberg block editor sidebar API
- Integrates with WordPress media library
- Follows WordPress coding standards and security practices
- Uses WordPress REST API for AJAX communication
- Stores configuration in wp_options table
- Tracks shared images in post meta `_pti_instagram_shared_images`
- Stores scheduled posts in post meta `_pti_instagram_scheduled_posts`

### Build System & Development Workflow
- **Webpack**: Uses `@wordpress/scripts` with custom configuration disabling Babel caching for development
- **Source Maps**: Different strategies for development vs production builds
- **Asset Output**: Builds to `admin/assets/js/` directory with hash-based filenames
- **Distribution**: `./build-dist.sh` creates clean plugin zip excluding dev files (node_modules, src, config)

### Development Notes
- **Asset Enqueuing**: Only loads on post edit screens (`post.php`, `post-new.php`) to avoid conflicts
- **Error Handling**: Check browser console for React errors, WordPress debug.log for PHP errors
- **Image Processing**: Temporary files auto-cleanup after 24 hours via WP-Cron
- **OAuth Testing**: Use `/pti-oauth/` endpoint for redirect URL testing
- **Security**: All operations protected with WordPress nonces and capability checks (`edit_posts`, `manage_options`)
- **Debug Logging**: Debug statements present in Instagram API class for troubleshooting

### Data Storage Patterns  
- **Plugin Settings**: Stored in `wp_options` as `pti_settings` (JSON object)
- **Post Metadata**: Uses `_pti_instagram_shared_images` and `_pti_instagram_scheduled_posts` 
- **Transients**: OAuth state and temporary data with automatic expiration
- **File System**: Temporary images in `/wp-content/uploads/pti-temp/` with daily cleanup