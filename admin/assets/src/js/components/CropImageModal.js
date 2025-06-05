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
];

const CropImageModal = ({ images, caption, postId, onClose, onPostComplete }) => {
    const [currentIndex, setCurrentIndex] = useState(0);
    const [lockedAspectRatio, setLockedAspectRatio] = useState(null); // Lock after first crop
    
    // Store crop data for each image: { crop: {x,y}, zoom, aspect, croppedAreaPixels }
    const [imageCropData, setImageCropData] = useState([]); 

    // Local state for the cropper component itself
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);

    const [isProcessing, setIsProcessing] = useState(false);
    const [processingMessage, setProcessingMessage] = useState('');

    const currentImage = images[currentIndex];

    // Initialize crop data for all images on mount
    useEffect(() => {
        setImageCropData(images.map(img => ({
            crop: { x: 0, y: 0 },
            zoom: 1,
            aspect: img.width && img.height ? img.width / img.height : 1,
            croppedAreaPixels: null,
        })));
    }, [images]);

    const onCropChange = useCallback((location) => {
        setCrop(location);
    }, []);

    const onZoomChange = useCallback((newZoom) => {
        setZoom(newZoom);
    }, []);

    const onCropFull = useCallback((croppedArea, croppedAreaPixels) => {
        // Update the stored crop data for the current image
        const newData = [...imageCropData];
        if (newData[currentIndex]) {
            newData[currentIndex].croppedAreaPixels = croppedAreaPixels;
            setImageCropData(newData);
        }
    }, [currentIndex, imageCropData]);
    
    const handleAspectRatioChange = (newAspect) => {
        const newAspectValue = parseFloat(newAspect);
        if (currentIndex === 0) {
            setLockedAspectRatio(newAspectValue);
        }
        const newData = [...imageCropData];
        if (newData[currentIndex]) {
            newData[currentIndex].aspect = newAspectValue;
            setImageCropData(newData);
            // Reset crop and zoom for the new aspect ratio
            setCrop({ x: 0, y: 0 });
            setZoom(1);
        }
    };

    const navigate = (direction) => {
        // Before navigating, save the current state of the cropper
        const newData = [...imageCropData];
        if (newData[currentIndex]) {
            newData[currentIndex].crop = crop;
            newData[currentIndex].zoom = zoom;
            setImageCropData(newData);
        }

        const newIndex = currentIndex + direction;
        if (newIndex >= 0 && newIndex < images.length) {
            setCurrentIndex(newIndex);
            // When moving to the next image, restore its saved state
            const nextImageData = imageCropData[newIndex];
            setCrop(nextImageData.crop);
            setZoom(nextImageData.zoom);
        }
    };
    
    const selectImage = (index) => {
        if (index === currentIndex) return;
        navigate(index - currentIndex);
    }
    
    const handleConfirmAndPost = async () => {
        setIsProcessing(true);
        try {
            const tempUrls = [];
            for (let i = 0; i < images.length; i++) {
                setProcessingMessage(`${__('Cropping and uploading image', 'post-to-instagram')} ${i + 1}/${images.length}...`);

                const img = images[i];
                const cropData = imageCropData[i];
                if (!cropData.croppedAreaPixels) {
                    // This can happen if the user doesn't interact with the cropper for an image.
                    // We need to generate the default centered crop pixels.
                    // This is a complex calculation; for now, we'll assume interaction or a simpler fallback.
                    // For a robust solution, one would calculate the default crop pixels here.
                    throw new Error(`Please adjust the crop for image ${i + 1} before posting.`);
                }

                const croppedBlob = await getCroppedImg(img.originalUrl || img.url, cropData.croppedAreaPixels, rotation);
                
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
            onPostComplete(); // This will close the modal and reset the sidebar

        } catch (error) {
            console.error('Error during posting process:', error);
            wp.data.dispatch('core/notices').createNotice('error', error.message, { isDismissible: true });
            setIsProcessing(false); // Stop processing on error to allow user to try again
            setProcessingMessage('');
        }
    };

    if (!currentImage || !imageCropData.length) {
        return <Spinner />;
    }

    const currentAspect = lockedAspectRatio || imageCropData[currentIndex].aspect;

    return (
        <Modal
            title={__('Crop Images & Post to Instagram', 'post-to-instagram')}
            onRequestClose={onClose}
            shouldCloseOnClickOutside={false}
            className="pti-multi-crop-modal"
        >
            <div className="pti-multi-crop-main-content">
                <div className="pti-crop-container" style={{ position: 'relative', height: 400, width: '100%', background: '#333' }}>
                    <Cropper
                        image={currentImage.originalUrl || currentImage.url}
                        crop={crop}
                        zoom={zoom}
                        rotation={rotation}
                        aspect={currentAspect}
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

                <div className="pti-crop-controls">
                    <Flex justify="space-between" align="center">
                        <FlexItem>
                            <SelectControl
                                label={__('Aspect Ratio', 'post-to-instagram')}
                                value={currentAspect}
                                options={aspectRatios}
                                onChange={handleAspectRatioChange}
                                disabled={currentIndex > 0 && lockedAspectRatio !== null}
                                help={currentIndex > 0 && lockedAspectRatio !== null ? __('Aspect ratio is locked by the first image.', 'post-to-instagram') : ''}
                                __nextHasNoMarginBottom
                            />
                        </FlexItem>
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