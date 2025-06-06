import { Fragment, useState, useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button, TabPanel, Spinner } from '@wordpress/components';
import AuthPanel from './AuthPanel';
import CustomImageSelectModal from './CustomImageSelectModal';
import CropImageModal from './CropImageModal';
import CaptionInput from './CaptionInput';
import { _n, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

const ScheduledPostsList = ({ posts, title }) => {
    if (!posts || posts.length === 0) {
        return <p>{__('No posts scheduled yet.', 'post-to-instagram')}</p>;
    }
    return (
        <div className="scheduled-posts-list">
            <h4>{title}</h4>
            {/* Basic list for now, can be enhanced to a table */}
            <ul>
                {posts.map(post => (
                    <li key={post.id}>
                        {sprintf(
                            __('%d images scheduled for %s', 'post-to-instagram'),
                            post.image_ids.length,
                            post.schedule_time
                        )}
                        {/* Add Edit/Cancel buttons here later */}
                    </li>
                ))}
            </ul>
        </div>
    );
};

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
}) => {
    const [scheduledPosts, setScheduledPosts] = useState([]);
    const [allScheduledPosts, setAllScheduledPosts] = useState([]);
    const [isFetchingScheduled, setIsFetchingScheduled] = useState(true);

    const fetchScheduledPosts = () => {
        setIsFetchingScheduled(true);
        Promise.all([
            apiFetch({ path: `/pti/v1/scheduled-posts?post_id=${postId}` }),
            apiFetch({ path: `/pti/v1/scheduled-posts` })
        ]).then(([postSpecific, allPosts]) => {
            setScheduledPosts(postSpecific);
            setAllScheduledPosts(allPosts);
            setIsFetchingScheduled(false);
        }).catch(error => {
            console.error('Error fetching scheduled posts:', error);
            setIsFetchingScheduled(false);
        });
    };

    useEffect(() => {
        if (isAuthenticated) {
            fetchScheduledPosts();
        }
    }, [isAuthenticated, postId]);

    const handlePostComplete = () => {
        // This function is called after posting or scheduling is complete
        // It should refresh the lists of scheduled/posted items
        fetchScheduledPosts();
        // Reset selection state, etc.
        setSelectedImages([]);
        setCaption('');
    };

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
            <TabPanel
                className="pti-sidebar-tabs"
                activeClass="is-active"
                tabs={[
                    {
                        name: 'post',
                        title: __('New Post', 'post-to-instagram'),
                        className: 'tab-post',
                    },
                    {
                        name: 'scheduled',
                        title: __('Scheduled', 'post-to-instagram'),
                        className: 'tab-scheduled',
                    },
                ]}
            >
                {(tab) => (
                    <Fragment>
                        {tab.name === 'post' && (
                             <Fragment>
                                <p>{__('Ready to post to Instagram!', 'post-to-instagram')}</p>
                                <Button variant="primary" onClick={openMediaModal}>
                                    {selectedImages.length > 0 ? (__('Change Selection', 'post-to-instagram')) : (i18n.select_images || 'Select Images')}
                                </Button>

                                {/* Show summary and actions if images are selected */}
                                {selectedImages.length > 0 && (
                                    <>
                                        <div className="pti-selection-summary" style={{ marginTop: '16px', marginBottom: '16px' }}>
                                            <p style={{ margin: 0 }}>
                                                {
                                                    sprintf(
                                                        /* translators: %d: number of images selected. */
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

                                        {/* Post Now / Schedule Post buttons */}
                                        <div className="pti-post-actions">
                                            <Button variant="primary" onClick={handlePostNow} disabled={posting}>
                                                {posting ? __('Opening...', 'post-to-instagram') : __('Review & Post', 'post-to-instagram')}
                                            </Button>
                                        </div>
                                    </>
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
                                {/* Render the Multi-Image Crop Modal conditionally */}
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
                        )}
                        {tab.name === 'scheduled' && (
                             <div className="pti-scheduled-panel">
                                {isFetchingScheduled ? (
                                    <Spinner />
                                ) : (
                                    <>
                                        <ScheduledPostsList posts={scheduledPosts} title={__('Scheduled for this post', 'post-to-instagram')} />
                                        <ScheduledPostsList posts={allScheduledPosts} title={__('All scheduled posts', 'post-to-instagram')} />
                                    </>
                                )}
                            </div>
                        )}
                    </Fragment>
                )}
            </TabPanel>
        );
    }
};

export default SidebarPanelContent; 