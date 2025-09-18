/**
 * Instagram posting and scheduling actions hook.
 *
 * Handles image cropping, upload, and Instagram API communication.
 * Shows WordPress admin notices for user feedback.
 */
import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getCroppedImg } from '../utils/cropImage';
import { calculateCenterCrop } from '../utils/cropUtils';

function useInstagramPostActions() {
    const [isProcessing, setIsProcessing] = useState(false);
    const [processingMessage, setProcessingMessage] = useState('');

    /**
     * Post images to Instagram immediately.
     *
     * Crops images, uploads to temp storage, then posts via Instagram API.
     */
    // Sequential polling state (avoid overlapping network requests)
    const pollingActiveRef = useRef(false);
    const processingKeyRef = useRef(null);
    const clearPolling = () => {
        pollingActiveRef.current = false;
        processingKeyRef.current = null;
    };

    const pollProcessingStatus = useCallback(async () => {
        if (!processingKeyRef.current) return;
        if (pollingActiveRef.current) return; // Guard against accidental re-entry
        pollingActiveRef.current = true;
        try {
            while (pollingActiveRef.current && processingKeyRef.current) {
                let statusResp;
                try {
                    statusResp = await wp.apiFetch({
                        path: `/pti/v1/post-status?processing_key=${encodeURIComponent(processingKeyRef.current)}`,
                        method: 'GET'
                    });
                } catch (err) {
                    // Network or fetch error: stop polling and surface notice
                    clearPolling();
                    setIsProcessing(false);
                    if (window.wp?.data?.dispatch) {
                        window.wp.data.dispatch('core/notices').createNotice('error', err.message || __('Error polling Instagram status.', 'post-to-instagram'), { isDismissible: true });
                    }
                    break;
                }

                if (!statusResp) break;

                if (statusResp.status === 'publishing') {
                    setProcessingMessage(__('Finalizing publish on Instagram...', 'post-to-instagram'));
                } else if (statusResp.status === 'processing') {
                    const ready = statusResp.ready || 0;
                    const total = statusResp.total || 0;
                    setProcessingMessage(`${__('Instagram processing containers', 'post-to-instagram')} ${ready}/${total}...`);
                } else if (statusResp.status === 'completed') {
                    clearPolling();
                    setProcessingMessage(__('Successfully posted to Instagram!', 'post-to-instagram'));
                    setIsProcessing(false);
                    if (window.wp?.data?.dispatch) {
                        window.wp.data.dispatch('core/notices').createNotice('success', __('Successfully posted to Instagram!', 'post-to-instagram'), { isDismissible: true });
                    }
                    break;
                } else if (statusResp.status === 'error') {
                    clearPolling();
                    setIsProcessing(false);
                    if (window.wp?.data?.dispatch) {
                        window.wp.data.dispatch('core/notices').createNotice('error', statusResp.message || __('Error during Instagram processing.', 'post-to-instagram'), { isDismissible: true });
                    }
                    break;
                } else if (statusResp.status === 'not_found') {
                    clearPolling();
                    setIsProcessing(false);
                    if (window.wp?.data?.dispatch) {
                        window.wp.data.dispatch('core/notices').createNotice('error', statusResp.message || __('Processing key not found.', 'post-to-instagram'), { isDismissible: true });
                    }
                    break;
                }

                // Wait 4 seconds before next poll (sequential, no overlap)
                await new Promise(r => setTimeout(r, 4000));
            }
        } finally {
            // If loop exits without clearing (e.g., natural completion), ensure flag resets
            pollingActiveRef.current = false;
        }
    }, [setProcessingMessage]);

    const postToInstagram = useCallback(async ({ postId, images, caption, aspectRatio, imageCropData, rotation, nonce }) => {
        setIsProcessing(true);
        try {
            const tempUrls = [];
            for (let i = 0; i < images.length; i++) {
                setProcessingMessage(`${__('Processing image', 'post-to-instagram')} ${i + 1}/${images.length}...`);
                const img = images[i];
                const cropData = imageCropData[i];
                let croppedAreaPixels = cropData.croppedAreaPixels;
                if (!croppedAreaPixels) {
                    croppedAreaPixels = calculateCenterCrop(img.width, img.height, aspectRatio);
                }
                const croppedBlob = await getCroppedImg(img.originalUrl || img.url, croppedAreaPixels, rotation);
                const formData = new FormData();
                const fileName = 'cropped-' + (img.url.split('/').pop() || 'image.jpg');
                formData.append('cropped_image', croppedBlob, fileName);
                formData.append('_wpnonce', nonce);
                const response = await wp.apiFetch({
                    path: '/pti/v1/upload-cropped-image',
                    method: 'POST',
                    body: formData,
                });
                if (!response.success || !response.url) {
                    throw new Error(response.message || `Failed to upload image ${i + 1}.`);
                }
                tempUrls.push(response.url);
            }
            setProcessingMessage(__('Finalizing post with Instagram...', 'post-to-instagram'));
            const imageIds = images.map(img => img.id);
            const postResponse = await wp.apiFetch({
                path: '/pti/v1/post-now',
                method: 'POST',
                data: {
                    post_id: postId,
                    image_urls: tempUrls,
                    image_ids: imageIds,
                    caption: caption,
                    _wpnonce: nonce,
                },
            });
            // If backend indicates async processing, start polling
            if (postResponse.status === 'processing' || postResponse.processing_key) {
                const processingKey = postResponse.processing_key;
                if (!processingKey) {
                    throw new Error(__('Processing key missing from async response.', 'post-to-instagram'));
                }
                setProcessingMessage(__('Uploading complete. Waiting for Instagram processing...', 'post-to-instagram'));

                processingKeyRef.current = processingKey;
                pollProcessingStatus();
                return postResponse;
            }

            const isSuccess = postResponse.success === true ||
                             (postResponse.data && postResponse.data.success === true) ||
                             (!postResponse.success && !postResponse.message && postResponse.media_id);

            if (!isSuccess) {
                const errorMessage = postResponse.message || 
                                    (postResponse.data && postResponse.data.message) || 
                                    'Failed to post to Instagram.';
                throw new Error(errorMessage);
            }
            setProcessingMessage(__('Successfully posted to Instagram!', 'post-to-instagram'));
            setIsProcessing(false);
            if (window.wp && window.wp.data && window.wp.data.dispatch) {
                window.wp.data.dispatch('core/notices').createNotice('success', __('Successfully posted to Instagram!', 'post-to-instagram'), { isDismissible: true });
            }
            return postResponse;
        } catch (error) {
            clearPolling();
            setIsProcessing(false);
            if (window.wp && window.wp.data && window.wp.data.dispatch) {
                window.wp.data.dispatch('core/notices').createNotice('error', error.message || __('Error posting to Instagram.', 'post-to-instagram'), { isDismissible: true });
            }
            throw error;
        }
    }, []);

    /**
     * Schedule Instagram post for future publishing.
     *
     * Stores crop data for server-side processing via WP-Cron.
     */
    const scheduleInstagramPost = useCallback(async ({ postId, images, imageCropData, aspectRatio, caption, scheduleDateTime, rotation, nonce }) => {
        setIsProcessing(true);
        try {
            const finalCropData = [];
            const imageIds = images.map(img => img.id);
            for (let i = 0; i < images.length; i++) {
                setProcessingMessage(`${__('Processing image', 'post-to-instagram')} ${i + 1}/${images.length}...`);
                const img = images[i];
                const cropData = imageCropData[i];
                let croppedAreaPixels = cropData.croppedAreaPixels;
                if (!croppedAreaPixels) {
                    croppedAreaPixels = calculateCenterCrop(img.width, img.height, aspectRatio);
                }
                finalCropData.push({
                    image_id: img.id,
                    aspect_ratio: aspectRatio,
                    crop: cropData.crop,
                    zoom: cropData.zoom,
                    croppedAreaPixels: croppedAreaPixels
                });
            }
            setProcessingMessage(__('Scheduling post...', 'post-to-instagram'));
            const scheduleResponse = await wp.apiFetch({
                path: '/pti/v1/schedule-post',
                method: 'POST',
                data: {
                    post_id: postId,
                    image_ids: imageIds,
                    crop_data: finalCropData,
                    caption: caption,
                    schedule_time: scheduleDateTime,
                    _wpnonce: nonce,
                },
            });
            if (!scheduleResponse.success) {
                throw new Error(scheduleResponse.message || 'Failed to schedule post.');
            }
            setProcessingMessage(__('Post successfully scheduled!', 'post-to-instagram'));
            setIsProcessing(false);
            if (window.wp && window.wp.data && window.wp.data.dispatch) {
                window.wp.data.dispatch('core/notices').createNotice('success', __('Post successfully scheduled!', 'post-to-instagram'), { isDismissible: true });
            }
            return scheduleResponse;
        } catch (error) {
            setIsProcessing(false);
            if (window.wp && window.wp.data && window.wp.data.dispatch) {
                window.wp.data.dispatch('core/notices').createNotice('error', error.message || __('Error scheduling post.', 'post-to-instagram'), { isDismissible: true });
            }
            throw error;
        }
    }, []);

    return {
        postToInstagram,
        scheduleInstagramPost,
        isProcessing,
        processingMessage,
    };
}

export default useInstagramPostActions;
export { useInstagramPostActions }; 