import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { Modal, Button, SelectControl, Flex, FlexItem, Spinner, Icon } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Cropper from 'react-easy-crop';
import { getCroppedImg } from './utils/cropImage';
import { chevronLeft, chevronRight } from '@wordpress/icons';

const aspectRatios = [
    { label: __('Square (1:1)', 'post-to-instagram'), value: 1 / 1 },
    { label: __('Portrait (4:5)', 'post-to-instagram'), value: 4 / 5 },
    { label: __('Landscape (1.91:1)', 'post-to-instagram'), value: 1.91 / 1 },
    { label: __('3:4', 'post-to-instagram'), value: 3 / 4 },
];

function findClosestAspectRatio(ratio) {
    let closest = aspectRatios[0].value;
    let minDiff = Math.abs(ratio - closest);
    for (let i = 1; i < aspectRatios.length; i++) {
        const diff = Math.abs(ratio - aspectRatios[i].value);
        if (diff < minDiff) {
            minDiff = diff;
            closest = aspectRatios[i].value;
        }
    }
    return closest;
}

const DEFAULT_ASPECT = 1;

const CropImageModal = ({ images, caption, postId, onClose, onPostComplete }) => {
    const [currentIndex, setCurrentIndex] = useState(0);
    const [aspectRatio, setAspectRatio] = useState(DEFAULT_ASPECT); // The shared aspect ratio
    const [imageCropData, setImageCropData] = useState([]); 
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);
    const [isProcessing, setIsProcessing] = useState(false);
    const [processingMessage, setProcessingMessage] = useState('');
    const currentImage = images[currentIndex];

    // On mount, set aspect ratio to closest to first image's ratio
    useEffect(() => {
        if (images[0]?.width && images[0]?.height) {
            const naturalRatio = images[0].width / images[0].height;
            setAspectRatio(findClosestAspectRatio(naturalRatio));
        } else {
            setAspectRatio(DEFAULT_ASPECT);
        }
    }, [images]);

    // Initialize crop data for all images on mount or when aspectRatio changes
    useEffect(() => {
        setImageCropData(prev => {
            // If prev exists, update aspect only, preserve crop/zoom if possible
            if (prev.length === images.length) {
                return prev.map(data => ({
                    ...data,
                    aspect: aspectRatio,
                }));
            }
            // Otherwise, initialize
            return images.map(img => ({
                crop: { x: 0, y: 0 },
                zoom: 1,
                aspect: aspectRatio,
                croppedAreaPixels: null,
            }));
        });
    }, [images, aspectRatio]);

    // When currentIndex changes, restore crop/zoom for that image
    useEffect(() => {
        if (imageCropData[currentIndex]) {
            setCrop(imageCropData[currentIndex].crop || { x: 0, y: 0 });
            setZoom(imageCropData[currentIndex].zoom || 1);
        }
    }, [currentIndex, imageCropData]);

    const onCropChange = useCallback((location) => {
        setCrop(location);
    }, []);

    const onZoomChange = useCallback((newZoom) => {
        setZoom(newZoom);
    }, []);

    const onCropFull = useCallback((croppedArea, croppedAreaPixels) => {
        setImageCropData(prev => {
            const newData = [...prev];
            if (newData[currentIndex]) {
                newData[currentIndex] = {
                    ...newData[currentIndex],
                    croppedAreaPixels,
                    crop,
                    zoom,
                };
            }
            return newData;
        });
    }, [currentIndex, crop, zoom]);

    const handleAspectRatioChange = (newAspect) => {
        const newAspectValue = parseFloat(newAspect);
        setAspectRatio(newAspectValue);
        // All images' aspect is updated in useEffect above, crop/zoom is preserved
    };

    const navigate = (direction) => {
        // Save current crop/zoom before navigating
        setImageCropData(prev => {
            const newData = [...prev];
            if (newData[currentIndex]) {
                newData[currentIndex] = {
                    ...newData[currentIndex],
                    crop,
                    zoom,
                };
            }
            return newData;
        });
        const newIndex = currentIndex + direction;
        if (newIndex >= 0 && newIndex < images.length) {
            setCurrentIndex(newIndex);
        }
    };

    const selectImage = (index) => {
        if (index === currentIndex) return;
        navigate(index - currentIndex);
    };

    const getDefaultCroppedAreaPixels = (img, aspect) => {
        // Calculate a centered crop box for the given aspect ratio
        const imgW = img.width;
        const imgH = img.height;
        let cropW, cropH, x, y;
        if (imgW / imgH > aspect) {
            // Image is wider than aspect
            cropH = imgH;
            cropW = imgH * aspect;
            x = (imgW - cropW) / 2;
            y = 0;
        } else {
            // Image is taller than aspect
            cropW = imgW;
            cropH = imgW / aspect;
            x = 0;
            y = (imgH - cropH) / 2;
        }
        return { x, y, width: cropW, height: cropH };
    };

    const handleConfirmAndPost = async () => {
        setIsProcessing(true);
        try {
            const tempUrls = [];
            for (let i = 0; i < images.length; i++) {
                setProcessingMessage(`${__('Cropping and uploading image', 'post-to-instagram')} ${i + 1}/${images.length}...`);
                const img = images[i];
                const cropData = imageCropData[i];
                let croppedAreaPixels = cropData.croppedAreaPixels;
                if (!croppedAreaPixels) {
                    // Calculate default crop
                    croppedAreaPixels = getDefaultCroppedAreaPixels(img, aspectRatio);
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
            const postResponse = await wp.apiFetch({
                path: '/pti/v1/post-now',
                method: 'POST',
                data: {
                    post_id: postId,
                    image_urls: tempUrls,
                    caption,
                    _wpnonce: pti_data.nonce_post_media,
                },
            });
            if (!postResponse.success) {
                throw new Error(postResponse.message || __('Failed to post to Instagram.', 'post-to-instagram'));
            }
            let successMsg = postResponse.message || __('Posted successfully!', 'post-to-instagram');
            if (postResponse.permalink) {
                successMsg = `${__('Posted to Instagram!', 'post-to-instagram')} <a href="${postResponse.permalink}" target="_blank" rel="noopener noreferrer">${__('View post', 'post-to-instagram')}</a>`;
            }
            wp.data.dispatch('core/notices').createNotice('success', successMsg, { isDismissible: true, __unstableHTML: true });
            onPostComplete();
        } catch (error) {
            console.error('Error during posting process:', error);
            wp.data.dispatch('core/notices').createNotice('error', error.message, { isDismissible: true });
            setIsProcessing(false);
            setProcessingMessage('');
        }
    };

    if (!currentImage || !imageCropData.length) {
        return <Spinner />;
    }

    return (
        <Modal
            title={__('Crop Images & Post to Instagram', 'post-to-instagram')}
            onRequestClose={onClose}
            shouldCloseOnClickOutside={false}
            className="pti-multi-crop-modal pti-multi-crop-modal--tall"
        >
            <div className="pti-multi-crop-main-content">
                {/* Aspect Ratio Dropdown above cropper */}
                <div className="pti-crop-controls" style={{ borderBottom: 'none', paddingBottom: 0 }}>
                    <SelectControl
                        label={__('Aspect Ratio', 'post-to-instagram')}
                        value={aspectRatio}
                        options={aspectRatios}
                        onChange={handleAspectRatioChange}
                        help={__('This will apply to all images. You can crop each image individually.', 'post-to-instagram')}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize={true}
                    />
                </div>
                <div className="pti-crop-container" style={{ position: 'relative', height: 440, width: '100%', background: '#333', marginTop: 8 }}>
                    <Cropper
                        image={currentImage.originalUrl || currentImage.url}
                        crop={crop}
                        zoom={zoom}
                        rotation={rotation}
                        aspect={aspectRatio}
                        onCropChange={onCropChange}
                        onZoomChange={onZoomChange}
                        onCropComplete={onCropFull}
                    />
                    {isProcessing && (
                         <div className="pti-processing-overlay">
                            <Spinner />
                            <p>{processingMessage}</p>
                        </div>
                    )}
                </div>
                <div className="pti-crop-controls" style={{ borderTop: 'none', borderBottom: '1px solid #ddd', marginTop: 0 }}>
                    <Flex justify="space-between" align="center">
                        <FlexItem className="pti-crop-navigation">
                             <Button icon={chevronLeft} onClick={() => navigate(-1)} disabled={currentIndex === 0} aria-label={__('Previous Image', 'post-to-instagram')} />
                             <span>{`${currentIndex + 1} / ${images.length}`}</span>
                             <Button icon={chevronRight} onClick={() => navigate(1)} disabled={currentIndex === images.length - 1} aria-label={__('Next Image', 'post-to-instagram')}/>
                        </FlexItem>
                    </Flex>
                </div>
                <div className="pti-thumbnail-strip">
                    {images.map((img, index) => (
                        <div 
                            key={img.id} 
                            className={`pti-thumbnail-item ${index === currentIndex ? 'is-active' : ''}`}
                            onClick={() => selectImage(index)}
                        >
                            <img src={img.url} alt={`Thumbnail for image ${index + 1}`} />
                        </div>
                    ))}
                </div>
            </div>
            <div className="pti-multi-crop-footer">
                <Button isSecondary onClick={onClose} disabled={isProcessing}>
                    {__('Cancel', 'post-to-instagram')}
                </Button>
                <Button isPrimary onClick={handleConfirmAndPost} disabled={isProcessing}>
                    {isProcessing ? __('Posting...', 'post-to-instagram') : __('Confirm and Post to Instagram', 'post-to-instagram')}
                </Button>
            </div>
        </Modal>
    );
};

export default CropImageModal; 