import { Fragment } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import AuthPanel from './AuthPanel';
import CustomImageSelectModal from './CustomImageSelectModal';
import CropImageModal from './CropImageModal';
import CaptionInput from './CaptionInput';
import ScheduledPosts from './ScheduledPosts';

const SidebarPanelContent = ({
    isLoading,
    isConfigured,
    showEdit,
    isAuthenticated,
    authUrl,
    i18n,
    checkAuthStatus,
    setShowEdit,
    savedAppId,
    handleConnectInstagram,
    openMediaModal,
    selectedImages,
    setSelectedImages,
    caption,
    setCaption,
    posting,
    handlePostNow,
    showImageModal,
    allowedIds,
    showMultiCropModal,
    postId,
    handlePostComplete,
    setShowImageModal,
    setShowMultiCropModal,
    refreshKey,
}) => {
    if (isLoading) {
        return <p>{__('Loading status...', 'post-to-instagram')}</p>;
    } else if (!isConfigured || showEdit) {
        return (
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
        return (
            <Fragment>
                <p>{__('Connect your Instagram account to start posting.', 'post-to-instagram')}</p>
                <Button variant="primary" onClick={handleConnectInstagram} disabled={isLoading || authUrl === '#'}>
                    {i18n.connect_instagram || 'Connect to Instagram'}
                </Button>
                <Button variant="secondary" onClick={() => setShowEdit(true)} style={{ marginLeft: 8 }}>
                    {__('Edit API Credentials', 'post-to-instagram')}
                </Button>
            </Fragment>
        );
    } else {
        return (
            <Fragment>
                <p>{__('Ready to post to Instagram!', 'post-to-instagram')}</p>
                <Button variant="primary" onClick={openMediaModal}>
                    {selectedImages.length > 0 ? (__('Change Selection', 'post-to-instagram')) : (i18n.select_images || 'Select Images')}
                </Button>

                {selectedImages.length > 0 && (
                    <>
                        <div className="pti-selection-summary" style={{ marginTop: '16px', marginBottom: '16px' }}>
                            <p style={{ margin: 0 }}>
                                {
                                    sprintf(
                                        _n(
                                            '%d Image Selected',
                                            '%d Images Selected',
                                            selectedImages.length,
                                            'post-to-instagram'
                                        ),
                                        selectedImages.length
                                    )
                                }
                            </p>
                        </div>

                        <CaptionInput
                            value={caption}
                            onChange={setCaption}
                            disabled={posting}
                        />

                        <div className="pti-post-actions">
                            <Button variant="primary" onClick={handlePostNow} disabled={posting}>
                                {posting ? __('Opening...', 'post-to-instagram') : __('Review & Post', 'post-to-instagram')}
                            </Button>
                        </div>
                    </>
                )}

                <ScheduledPosts postId={postId} refreshKey={refreshKey} />

                {showImageModal && (
                    <CustomImageSelectModal
                        allowedIds={allowedIds}
                        selectedImages={selectedImages}
                        setSelectedImages={setSelectedImages}
                        onClose={() => setShowImageModal(false)}
                        maxSelect={10}
                    />
                )}
                {showMultiCropModal && (
                    <CropImageModal
                        images={selectedImages}
                        setImages={setSelectedImages}
                        caption={caption}
                        postId={postId}
                        onClose={() => setShowMultiCropModal(false)}
                        onPostComplete={handlePostComplete}
                    />
                )}
            </Fragment>
        );
    }
};

export default SidebarPanelContent; 