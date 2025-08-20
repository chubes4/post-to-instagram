// This hook provides postToInstagram and scheduleInstagramPost functions for posting and scheduling images to Instagram.
// After a successful post or schedule, a global WordPress notice (green for success, red for error) is shown above the editor using wp.data.dispatch('core/notices').createNotice.
// This ensures user feedback is always visible, regardless of modal state.

import { useState, useCallback, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { getCroppedImg } from '../utils/cropImage';

function useInstagramPostActions() {
    const [isProcessing, setIsProcessing] = useState(false);
    const [processingMessage, setProcessingMessage] = useState('');
    const isPosting = useRef(false);

    // Post to Instagram
    const postToInstagram = useCallback(async ({ postId, images, caption, aspectRatio, imageCropData, rotation, nonce }) => {
        // Atomic check-and-set to prevent race conditions
        if (isPosting.current) {
            console.warn('Post to Instagram already in progress.');
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
                    // fallback: center crop
                    const imgW = img.width;
                    const imgH = img.height;
                    let cropW, cropH, x, y;
                    if (imgW / imgH > aspectRatio) {
                        cropH = imgH;
                        cropW = imgH * aspectRatio;
                        x = (imgW - cropW) / 2;
                        y = 0;
                    } else {
                        cropW = imgW;
                        cropH = imgW / aspectRatio;
                        x = 0;
                        y = (imgH - cropH) / 2;
                    }
                    croppedAreaPixels = { x, y, width: cropW, height: cropH };
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
            // Handle WordPress REST API response variations
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

    // Schedule Instagram Post
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
                    // fallback: center crop
                    const imgW = img.width;
                    const imgH = img.height;
                    let cropW, cropH, x, y;
                    if (imgW / imgH > aspectRatio) {
                        cropH = imgH;
                        cropW = imgH * aspectRatio;
                        x = (imgW - cropW) / 2;
                        y = 0;
                    } else {
                        cropW = imgW;
                        cropH = imgW / aspectRatio;
                        x = 0;
                        y = (imgH - cropH) / 2;
                    }
                    croppedAreaPixels = { x, y, width: cropW, height: cropH };
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