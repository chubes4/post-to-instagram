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
    const isPosting = useRef(false);

    /**
     * Post images to Instagram immediately.
     *
     * Crops images, uploads to temp storage, then posts via Instagram API.
     * Uses atomic protection against race conditions.
     */
    const postToInstagram = useCallback(async ({ postId, images, caption, aspectRatio, imageCropData, rotation, nonce }) => {
        if (isPosting.current) {
            return;
        }
        isPosting.current = true;
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
            isPosting.current = false;
            return postResponse;
        } catch (error) {
            setIsProcessing(false);
            if (window.wp && window.wp.data && window.wp.data.dispatch) {
                window.wp.data.dispatch('core/notices').createNotice('error', error.message || __('Error posting to Instagram.', 'post-to-instagram'), { isDismissible: true });
            }
            isPosting.current = false;
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
export { useInstagramPostActions, useInstagramPostActions as useInstagramPost, useInstagramPostActions as defaultHook }; 