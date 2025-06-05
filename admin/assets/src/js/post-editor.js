import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/plugins';
import { PanelBody, Button } from '@wordpress/components';
import { Fragment, useState, useEffect, useRef } from '@wordpress/element';
import AuthPanel from './components/AuthPanel';
import CustomImageSelectModal from './components/CustomImageSelectModal';
import ReorderableImageList from './components/ReorderableImageList';
import CropImageModal from './components/CropImageModal';
import { getPostImageIds } from './utils/getPostImageIds';
import { createNotice } from '@wordpress/notices';

const PTI_ICON = 'instagram'; // Placeholder, can be replaced with a custom SVG icon component

const PostToInstagramPluginSidebar = () => {
    // Destructure pti_data passed from PHP
    const {
        is_configured: initialIsConfigured,
        is_authenticated: initialIsAuthenticated,
        auth_url: initialAuthUrl,
        i18n,
        auth_redirect_status,
        app_id: initialAppId,
        post_id: postId,
        username: initialUsername
    } = pti_data;

    const [isConfigured, setIsConfigured] = useState(initialIsConfigured);
    const [isAuthenticated, setIsAuthenticated] = useState(initialIsAuthenticated);
    const [authUrl, setAuthUrl] = useState(initialAuthUrl);
    const [isLoading, setIsLoading] = useState(true); // Start with loading true for initial check
    const [showEdit, setShowEdit] = useState(false);
    const [savedAppId, setSavedAppId] = useState(initialAppId || '');
    const [selectedImages, setSelectedImages] = useState([]);
    const [showImageModal, setShowImageModal] = useState(false);
    const [previewIndex, setPreviewIndex] = useState(null);
    const [allowedIds, setAllowedIds] = useState([]);
    const [caption, setCaption] = useState('');
    const [posting, setPosting] = useState(false);
    const [disconnecting, setDisconnecting] = useState(false);
    const [username, setUsername] = useState(initialUsername || null);

    // State for the new multi-image cropping modal
    const [showMultiCropModal, setShowMultiCropModal] = useState(false);

    // Calculate aspect ratio of the first image
    const aspectRatio = selectedImages.length > 0 && selectedImages[0].url && selectedImages[0].id
        ? (() => {
            // Try to get width/height from the media library if available
            const img = selectedImages[0];
            if (img.width && img.height) return img.width / img.height;
            // Fallback: assume square
            return 1;
        })()
        : 1;

    const handlePreview = (index) => setPreviewIndex(index);
    const closePreview = () => setPreviewIndex(null);

    // This function will be called from the crop modal upon successful posting
    const handlePostComplete = () => {
        setShowMultiCropModal(false);
        setSelectedImages([]);
        setCaption('');
        // We could also pass a message or data from the modal if needed
    };

    // Handler for Post Now - now opens the cropping modal
    const handlePostNow = async () => {
        if (!selectedImages.length) return;
        
        // Aspect ratio validation is now handled inside the cropping modal
        setShowMultiCropModal(true);
    };

    // Function to check authentication status via REST API
    const checkAuthStatus = (showAlerts = false) => {
        setIsLoading(true);
        wp.apiFetch({
            path: 'pti/v1/auth/status', // Correct path for wp.apiFetch (namespace/route)
            method: 'GET',
        })
        .then((data) => {
            setIsConfigured(data.is_configured);
            setIsAuthenticated(data.is_authenticated);
            setAuthUrl(data.auth_url || '#');
            setSavedAppId(data.app_id || '');
            setUsername(data.username || null);
            setIsLoading(false);
            if (showAlerts && data.is_authenticated) {
                // For better UX, consider using WordPress notices:
                // wp.data.dispatch('core/notices').createNotice('success', i18n.auth_successful || 'Authenticated!', { isDismissible: true });
            }
        })
        .catch((error) => {
            setIsLoading(false);
            console.error('Error checking auth status:', error);
            if (showAlerts) {
                // wp.data.dispatch('core/notices').createNotice('error', i18n.generic_auth_error || 'Error checking status.', { isDismissible: true });
                alert(i18n.generic_auth_error || 'Error checking authentication status.');
            }
        });
    };

    // Handle messages from OAuth window
    useEffect(() => {
        const handleAuthMessage = (event) => {
            // IMPORTANT: Add origin check for security in a real application
            // if (event.origin !== window.location.origin) { /* console.warn('Message from untrusted origin'); return; */ }
            if (event.data && event.data.type === 'pti_auth_complete') {
                if (event.data.success) {
                    checkAuthStatus(true); // Re-fetch status from server
                     if (window.oauthWindow && !window.oauthWindow.closed) {
                        window.oauthWindow.close();
                    }
                } else {
                    alert(i18n.auth_failed + (event.data.message ? '\\n' + event.data.message : ''));
                }
            }
        };
        window.addEventListener('message', handleAuthMessage);
        return () => window.removeEventListener('message', handleAuthMessage);
    }, [i18n]); // i18n might change if site language changes, good to include

    // Effect to handle auth redirect status from URL query params
    useEffect(() => {
        if (auth_redirect_status) {
            if (auth_redirect_status === 'success') {
                checkAuthStatus(true);
            } else {
                let errorMessage = i18n.auth_failed;
                if (i18n[auth_redirect_status]) {
                   errorMessage = i18n[auth_redirect_status];
                } else if (auth_redirect_status !== '1') { // Avoid showing " (Details: 1)"
                   errorMessage += ` (Details: ${auth_redirect_status})`;
                }
                alert(errorMessage);
            }
            // Clean the URL query parameters
            if (window.history.replaceState) {
                const currentUrl = new URL(window.location.href);
                currentUrl.searchParams.delete('pti_auth_success');
                currentUrl.searchParams.delete('pti_auth_error');
                window.history.replaceState({ path: currentUrl.href }, '', currentUrl.href);
            }
        }
    }, [auth_redirect_status, i18n]);

    const handleConnectInstagram = () => {
        if (!isConfigured) {
            alert(i18n.not_configured_for_auth || 'App not configured.');
            return;
        }
        if (authUrl && authUrl !== '#') {
            window.oauthWindow = window.open(authUrl, 'ptiOAuthConnect', 'width=600,height=700');
            const timer = setInterval(() => {
                if (window.oauthWindow && window.oauthWindow.closed) {
                    clearInterval(timer);
                    console.log('OAuth window closed by user or completed, re-checking auth status.');
                    checkAuthStatus(true);
                }
            }, 1000);
        } else {
            alert(i18n.generic_auth_error || 'Authentication URL not available.');
        }
    };
    
    // Initial status check when component mounts
    useEffect(() => {
        checkAuthStatus();
    }, []); // Empty dependency array ensures this runs only once on mount

    // Handler to open the custom image modal for image selection
    const openMediaModal = () => {
        setAllowedIds(getPostImageIds());
        setShowImageModal(true);
    };

    // Handler for disconnecting Instagram account
    const handleDisconnect = async () => {
        if (!window.confirm(i18n.disconnect_instagram || 'Disconnect Instagram Account?')) return;
        setDisconnecting(true);
        try {
            const response = await wp.apiFetch({
                path: '/pti/v1/disconnect',
                method: 'POST',
                data: { _wpnonce: pti_data.nonce_disconnect },
            });
            setDisconnecting(false);
            if (response && response.success) {
                wp.data.dispatch('core/notices').createNotice('success', i18n.disconnected || 'Account disconnected.', { isDismissible: true });
                checkAuthStatus();
            } else {
                wp.data.dispatch('core/notices').createNotice('error', response && response.message ? response.message : (i18n.error_disconnecting || 'Error disconnecting account.'), { isDismissible: true });
            }
        } catch (e) {
            setDisconnecting(false);
            wp.data.dispatch('core/notices').createNotice('error', i18n.error_disconnecting || 'Error disconnecting account.', { isDismissible: true });
        }
    };

    let panelContent;

    if (isLoading) {
        panelContent = <p>{__('Loading status...', 'post-to-instagram')}</p>;
    } else if (!isConfigured || showEdit) {
        panelContent = (
            <AuthPanel
                isConfigured={isConfigured}
                isAuthenticated={isAuthenticated}
                authUrl={authUrl}
                i18n={i18n}
                isLoading={isLoading}
                onAuthStatusChange={checkAuthStatus}
                showEdit={showEdit}
                setShowEdit={setShowEdit}
                savedAppId={savedAppId}
            />
        );
    } else if (!isAuthenticated) {
        panelContent = (
            <Fragment>
                <p>{__('Connect your Instagram account to start posting.', 'post-to-instagram')}</p>
                <Button isPrimary onClick={handleConnectInstagram} disabled={isLoading || authUrl === '#'}>
                    {i18n.connect_instagram || 'Connect to Instagram'}
                </Button>
                <Button isSecondary onClick={() => setShowEdit(true)} style={{ marginLeft: 8 }}>
                    {__('Edit API Credentials', 'post-to-instagram')}
                </Button>
            </Fragment>
        );
    } else {
        panelContent = (
            <Fragment>
                <p>{__('Ready to post to Instagram!', 'post-to-instagram')}</p>
                <Button isPrimary onClick={openMediaModal}>
                    {i18n.select_images || 'Select Images'}
                </Button>
                {/* Cropping notice */}
                <div className="pti-cropping-notice">
                    {__("All images will be cropped to match the aspect ratio of the first image in your selection, per Instagram's requirements.", 'post-to-instagram')}
                </div>
                {/* Drag-and-drop reordering and preview */}
                <ReorderableImageList
                    images={selectedImages}
                    setImages={setSelectedImages}
                    aspectRatio={aspectRatio}
                    onPreview={handlePreview}
                />
                {/* Caption input, only show if images are selected */}
                {selectedImages.length > 0 && (
                    <div className="pti-caption-box">
                        <label className="pti-caption-label">
                            {__('Instagram Caption', 'post-to-instagram')}
                        </label>
                        <textarea
                            className="pti-caption-input"
                            value={caption}
                            onChange={e => setCaption(e.target.value)}
                            rows={4}
                            placeholder={__('Write your Instagram caption here...', 'post-to-instagram')}
                        />
                        {/* Post Now / Schedule Post buttons */}
                        <div className="pti-post-actions">
                            <Button isPrimary onClick={handlePostNow} disabled={posting}>
                                {posting ? __('Posting...', 'post-to-instagram') : __('Post Now', 'post-to-instagram')}
                            </Button>
                            <Button isSecondary>{__('Schedule Post', 'post-to-instagram')}</Button>
                        </div>
                    </div>
                )}
                {/* Placeholder for ImagePreviewModal */}
                {previewIndex !== null && (
                    <div className="pti-preview-placeholder">
                        {__('(Image preview modal coming soon)', 'post-to-instagram')}
                    </div>
                )}
                {/* CustomImageSelectModal is rendered conditionally */}
                {showImageModal && (
                    <CustomImageSelectModal
                        allowedIds={allowedIds}
                        selectedImages={selectedImages}
                        setSelectedImages={setSelectedImages}
                        onClose={() => setShowImageModal(false)}
                        maxSelect={10}
                    />
                )}
                {/* Render the NEW Multi-Image Crop Modal conditionally */}
                {showMultiCropModal && (
                    <CropImageModal
                        images={selectedImages}
                        caption={caption}
                        postId={postId}
                        onClose={() => setShowMultiCropModal(false)}
                        onPostComplete={handlePostComplete}
                        // The rest of the logic is now self-contained within CropImageModal
                    />
                )}
            </Fragment>
        );
    }

    return (
        <Fragment>
            <PluginSidebarMoreMenuItem
                target="post-to-instagram-sidebar"
                icon={PTI_ICON}
            >
                {i18n.post_to_instagram || 'Post to Instagram'}
            </PluginSidebarMoreMenuItem>
            <PluginSidebar
                name="post-to-instagram-sidebar"
                title={i18n.post_to_instagram || 'Post to Instagram'}
                icon={PTI_ICON}
            >
                <PanelBody>
                    {panelContent}
                </PanelBody>
                {/* Move Disconnect button to the bottom of the sidebar, only if authenticated */}
                {isAuthenticated && (
                <div className="pti-disconnect-bottom">
                        {username && (
                            <div className="pti-account-info" style={{ marginBottom: 8, color: '#666', fontSize: 'smaller' }}>
                                {__('Connected Instagram account:', 'post-to-instagram')} <strong>@{username}</strong>
                            </div>
                        )}
                    <Button isSecondary onClick={handleDisconnect} disabled={disconnecting}>
                        {disconnecting ? __('Disconnecting...', 'post-to-instagram') : (i18n.disconnect_instagram || 'Disconnect')}
                    </Button>
                </div>
                )}
            </PluginSidebar>
        </Fragment>
    );
};

registerPlugin('post-to-instagram', {
    render: PostToInstagramPluginSidebar,
    icon: PTI_ICON,
}); 