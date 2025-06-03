import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor'; // Corrected import
import { __ } from '@wordpress/i18n';
import { PanelBody, Button } from '@wordpress/components';
import { Fragment, useState, useEffect, useRef } from '@wordpress/element';
import AuthPanel from './components/AuthPanel';
import ImageSelectModal from './components/ImageSelectModal';
import ReorderableImageList from './components/ReorderableImageList';

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
        post_id: postId
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

    // Handler to open the WP media modal for image selection
    const openMediaModal = () => setShowImageModal(true);

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
                <div style={{ margin: '8px 0', color: '#b26a00', fontSize: 12 }}>
                    {__("All images will be cropped to match the aspect ratio of the first image in your selection, per Instagram's requirements.", 'post-to-instagram')}
                </div>
                {/* Drag-and-drop reordering and preview */}
                <ReorderableImageList
                    images={selectedImages}
                    setImages={setSelectedImages}
                    aspectRatio={aspectRatio}
                    onPreview={handlePreview}
                />
                {/* Placeholder for ImagePreviewModal */}
                {previewIndex !== null && (
                    <div style={{ marginTop: 12, color: '#888' }}>
                        {__('(Image preview modal coming soon)', 'post-to-instagram')}
                    </div>
                )}
                <Button isSecondary onClick={() => alert('Disconnect TBD')} style={{ marginLeft: 8 }}>
                    {i18n.disconnect_instagram || 'Disconnect'}
                </Button>
                {/* ImageSelectModal is rendered conditionally */}
                {showImageModal && (
                    <ImageSelectModal
                        selectedImages={selectedImages}
                        setSelectedImages={setSelectedImages}
                        postId={postId}
                        onClose={() => setShowImageModal(false)}
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
            </PluginSidebar>
        </Fragment>
    );
};

registerPlugin('post-to-instagram', {
    render: PostToInstagramPluginSidebar,
    icon: PTI_ICON,
}); 