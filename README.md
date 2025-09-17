# Post to Instagram WordPress Plugin

A modern WordPress plugin for posting images from posts directly to Instagram with OAuth 2.0 authentication, React-based UI, and WP-Cron scheduling.

## Features

- **Instagram Integration**: Secure OAuth 2.0 authentication with Instagram Graph API
- **Gutenberg Integration**: Native block editor sidebar panel for seamless workflow
- **Multi-Image Carousels**: Support for up to 10 images with drag-and-drop reordering
- **Image Cropping**: Built-in cropping to Instagram aspect ratios (1:1, 4:5, 3:4, 1.91:1)
- **Scheduling**: WP-Cron based post scheduling with error recovery
- **Modern Stack**: React frontend with WordPress REST API backend

## Installation

### Prerequisites
- WordPress 5.0+
- PHP 7.4+
- Node.js and npm (for development)

### Setup
```bash
# Clone repository
git clone https://github.com/chubes4/post-to-instagram.git

# Install dependencies
npm install

# Build assets
npm run build

# Or start development mode
npm run start
```

## Development

### Project Structure
```
post-to-instagram/
├── inc/
│   ├── Assets/
│   │   ├── src/js/              # React source files
│   │   └── dist/               # Compiled assets
│   └── Core/
│       ├── Actions/
│       │   ├── Post.php        # Instagram posting
│       │   ├── Schedule.php    # WP-Cron scheduling
│       │   └── Cleanup.php     # File cleanup
│       ├── Admin.php           # Asset enqueuing & admin
│       ├── Auth.php            # OAuth flow
│       └── RestApi.php         # REST endpoints
├── auth/
│   └── oauth-handler.html      # OAuth popup handler
└── post-to-instagram.php       # Main plugin file
```

### REST API Endpoints

All endpoints use `/wp-json/pti/v1/` namespace:

**Authentication**
- `GET /auth/status` - Check authentication status
- `POST /auth/credentials` - Save Instagram app credentials
- `POST /disconnect` - Disconnect Instagram account

**Image Processing**
- `POST /upload-cropped-image` - Upload processed images to temp directory

**Posting**
- `POST /post-now` - Immediate Instagram posting
- `POST /schedule-post` - Schedule post for later
- `GET /scheduled-posts` - Retrieve scheduled posts

### React Components

**Core Components**
- `AuthPanel.js` - Instagram authentication UI
- `CaptionInput.js` - Caption text input with character count
- `CropImageModal.js` - Image cropping interface
- `CustomImageSelectModal.js` - Custom image selection interface
- `ScheduledPosts.js` - Scheduled post management
- `SidebarPanelContent.js` - Main sidebar content

**Custom Hooks**
- `useInstagramAuth.js` - Authentication state management
- `useInstagramPostActions.js` - Post and schedule actions

### Data Flow

1. **Authentication**: OAuth popup → token exchange → long-lived access token
2. **Image Selection**: Post content analysis → user selection → drag-and-drop ordering
3. **Processing**: Client-side cropping → temp file upload → Instagram API calls
4. **Posting**: Media container creation → status polling → publication

### Configuration

**Instagram App Setup**
1. Create Facebook Developer App
2. Add Instagram Graph API product
3. Configure OAuth redirect URI: `{site_url}/pti-oauth/`
4. Enter App ID and Secret in plugin settings

**Development Environment**
```bash
# Watch mode for development
npm run start

# Production build (automatically creates distribution zip)
npm run build
```

### Security

- All REST endpoints protected with WordPress nonces
- Capability checks: `edit_posts` for posting, `manage_options` for settings
- Temporary files auto-cleanup after 24 hours
- OAuth state validation with CSRF protection

### Troubleshooting

**Common Issues**
- Check browser console for React errors
- Review WordPress debug.log for PHP errors
- Verify Instagram app permissions and redirect URI
- Ensure temporary directory is writable

**Debug Mode**
Debug logging is available in the Instagram API class for troubleshooting API responses.

## License

GPL v2 or later

## Author

Chris Huber - [chubes.net](https://chubes.net) | [GitHub](https://github.com/chubes4)