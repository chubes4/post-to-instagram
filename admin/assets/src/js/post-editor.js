import { __ } from '@wordpress/i18n';
import { registerPlugin } from '@wordpress/plugins';
import { PluginSidebar, PluginSidebarMoreMenuItem } from '@wordpress/editor';
import { PanelBody, Button } from '@wordpress/components';
import { Fragment, useState } from '@wordpress/element';
import AuthPanel from './components/AuthPanel';
import CustomImageSelectModal from './components/CustomImageSelectModal';
import CropImageModal from './components/CropImageModal';
import { getPostImageIds } from './utils/getPostImageIds';
import CaptionInput from './components/CaptionInput';
import SidebarPanelContent from './components/SidebarPanelContent';
import useInstagramAuth from './hooks/useInstagramAuth';

const PTI_ICON = 'instagram'; // Placeholder, can be replaced with a custom SVG icon component

const PostToInstagramPluginSidebar = () => {
    // Destructure pti_data passed from PHP
    const {
        i18n,
        auth_redirect_status,
        post_id: postId,
    } = pti_data;

    // Use the custom authentication hook
    const {
        isConfigured,
        isAuthenticated,
        authUrl,
        savedAppId,
        username,
        disconnecting,
        isLoading,
        checkAuthStatus,
        handleConnectInstagram,
        handleDisconnect,
    } = useInstagramAuth(i18n, auth_redirect_status);

    // UI and workflow state
    const [showEdit, setShowEdit] = useState(false);
    const [selectedImages, setSelectedImages] = useState([]);
    const [showImageModal, setShowImageModal] = useState(false);
    const [allowedIds, setAllowedIds] = useState([]);
    const [caption, setCaption] = useState('');
    const [posting, setPosting] = useState(false);
    // State for the new multi-image cropping modal
    const [showMultiCropModal, setShowMultiCropModal] = useState(false);
    // State to trigger a refresh in the ScheduledPosts component
    const [refreshKey, setRefreshKey] = useState(0);

    // This function will be called from the crop modal upon successful posting
    const handlePostComplete = () => {
        setShowMultiCropModal(false);
        setSelectedImages([]);
        setCaption('');
        // Increment the key to trigger a refresh in the child component
        setRefreshKey(k => k + 1);
    };

    // Handler for Post Now - now opens the cropping modal
    const handlePostNow = async () => {
        if (!selectedImages.length) return;
        setShowMultiCropModal(true);
    };

    // Handler to open the custom image modal for image selection
    const openMediaModal = () => {
        setAllowedIds(getPostImageIds());
        setShowImageModal(true);
    };


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
                    <SidebarPanelContent
                        isLoading={isLoading}
                        isConfigured={isConfigured}
                        showEdit={showEdit}
                        isAuthenticated={isAuthenticated}
                        authUrl={authUrl}
                        i18n={i18n}
                        checkAuthStatus={checkAuthStatus}
                        setShowEdit={setShowEdit}
                        savedAppId={savedAppId}
                        handleConnectInstagram={handleConnectInstagram}
                        openMediaModal={openMediaModal}
                        selectedImages={selectedImages}
                        setSelectedImages={setSelectedImages}
                        caption={caption}
                        setCaption={setCaption}
                        posting={posting}
                        handlePostNow={handlePostNow}
                        showImageModal={showImageModal}
                        allowedIds={allowedIds}
                        showMultiCropModal={showMultiCropModal}
                        postId={postId}
                        handlePostComplete={handlePostComplete}
                        setShowImageModal={setShowImageModal}
                        setShowMultiCropModal={setShowMultiCropModal}
                        refreshKey={refreshKey}
                    />
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