=== Post to Instagram ===
Contributors: chubes4
Tags: instagram, social, block-editor, gutenberg, media, carousel
Requires at least: 5.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

== Description ==
A modern, modular, and open-source WordPress plugin to post images from your posts directly to Instagram. Seamlessly integrates with the block editor, supports multi-image carousels, drag-and-drop reordering, cropping, and secure OAuth authentication. Built for maintainability, WordPress-native UX, and open source collaboration.

== Features ==
* Connect your WordPress site to Instagram using secure OAuth 2.0
* Select images from your post content or featured image (block editor aware)
* Drag-and-drop reordering of images for Instagram carousels (up to 10 images, API limit)
* Crop images to Instagram-compliant aspect ratios before posting
* WordPress-native UI and modals for a seamless experience
* Modular, React-based codebase ready for open source
* (Planned) Captioning, scheduling, and image tracking

== Installation ==
1. Upload the plugin files to the `/wp-content/plugins/post-to-instagram` directory, or install via the WordPress plugin screen.
2. Activate the plugin through the 'Plugins' menu in WordPress.
3. Go to the post editor and look for the "Post to Instagram" sidebar panel.
4. Enter your Instagram App ID and App Secret (see FAQ for setup instructions).
5. Authenticate with Instagram via the secure popup flow.

== Usage ==
1. In the post editor, open the "Post to Instagram" sidebar.
2. If not configured, enter your Instagram App ID/Secret and connect.
3. Click "Select Images for Instagram" to open the image selection modal.
4. Choose up to 10 images from your post content or featured image.
5. Drag and drop to reorder images as desired.
6. Crop images to the required aspect ratio.
7. Add a caption.
8. Click "Post Now" to publish to Instagram.

== Requirements ==
* WordPress 5.0 or higher
* PHP 7.4 or higher
* Instagram App (App ID/Secret) with required permissions (see FAQ)

== Frequently Asked Questions ==
= Why can't I see all my images in the selection modal? =
Only images in your post content (core/image or core/gallery blocks) or set as the featured image are available for Instagram posting. This ensures a focused, WordPress-native experience.

= Why is the selection limited to 10 images? =
Instagram's API currently limits carousels to 10 images per post.

= What happens if my images have different aspect ratios? =
Instagram will crop all images in a carousel to match the aspect ratio of the first image. The plugin provides a cropping modal to ensure compliance.

= How do I get an Instagram App ID and Secret? =
You must create a Facebook Developer App, add the Instagram Graph API, and configure a valid OAuth redirect URI (see plugin settings for details).

== Changelog ==
= 1.0.0 =
* Initial release: OAuth authentication, image selection modal, drag-and-drop reordering, cropping, modular React UI, block editor integration.

== License ==
This plugin is free software, licensed under GPLv2 or later.

== Credits ==
Developed by Chris Huber (https://chubes.net).

== Support ==
For issues or feature requests, visit the GitHub repository: https://github.com/chubes4/post-to-instagram 