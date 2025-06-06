import { useState, useCallback, useEffect, useRef } from '@wordpress/element';
import { Modal, Button, SelectControl, Spinner, DateTimePicker } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import Cropper from 'react-easy-crop';
import { getCroppedImg } from '../utils/cropImage';
import useInstagramPostActions from '../hooks/useInstagramPostActions';

const aspectRatios = [
    { label: __('Square (1:1)', 'post-to-instagram'), value: 1 / 1 },
    { label: __('Portrait (4:5)', 'post-to-instagram'), value: 4 / 5 },
    { label: __('Classic (3:4)', 'post-to-instagram'), value: 3 / 4 },
    { label: __('Landscape (1.91:1)', 'post-to-instagram'), value: 1.91 / 1 },
];

function findClosestAspectRatio(ratio) {
    if (!ratio) return aspectRatios[0].value;
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

const ReorderableThumbnails = ({ images, currentIndex, onSelect, onReorder }) => {
    const dragItem = useRef();
    const dragOverItem = useRef();

    const handleDragStart = (index) => {
        dragItem.current = index;
    };
    const handleDragEnter = (index) => {
        dragOverItem.current = index;
    };
    const handleDragEnd = () => {
        const from = dragItem.current;
        const to = dragOverItem.current;
        if (from === undefined || to === undefined || from === to) return;
        
        const updated = [...images];
        const [moved] = updated.splice(from, 1);
        updated.splice(to, 0, moved);
        
        onReorder(updated, from, to);

        dragItem.current = undefined;
        dragOverItem.current = undefined;
    };

    return (
        <div className="pti-reorderable-thumbnails">
            {images.map((img, i) => (
                <div
                    key={img.id || `reorder-${i}`}
                    className={`pti-thumbnail-item ${i === currentIndex ? 'is-current' : ''}`}
                    draggable
                    onDragStart={() => handleDragStart(i)}
                    onDragEnter={() => handleDragEnter(i)}
                    onDragEnd={handleDragEnd}
                    onClick={() => onSelect(i)}
                    title={__('Click to select, drag to reorder.', 'post-to-instagram')}
                >
                    <img
                        src={img.url}
                        alt={img.alt || ''}
                    />
                     <div className="pti-thumbnail-order">{i + 1}</div>
                </div>
            ))}
        </div>
    );
};

const CropImageModal = ({ images, setImages, caption, postId, onClose, onPostComplete }) => {
    const [currentIndex, setCurrentIndex] = useState(0);
    const [aspectRatio, setAspectRatio] = useState(DEFAULT_ASPECT);
    const [imageCropData, setImageCropData] = useState([]); 
    const [crop, setCrop] = useState({ x: 0, y: 0 });
    const [zoom, setZoom] = useState(1);
    const [rotation, setRotation] = useState(0);
    const [showScheduleForm, setShowScheduleForm] = useState(false);
    const [scheduleDateTime, setScheduleDateTime] = useState(new Date());
    const currentImage = images[currentIndex];

    const {
        postToInstagram,
        scheduleInstagramPost,
        isProcessing,
        processingMessage,
    } = useInstagramPostActions();

    // On mount, set aspect ratio to closest to first image's ratio
    useEffect(() => {
        if (images[0]?.width && images[0]?.height) {
            const naturalRatio = images[0].width / images[0].height;
            setAspectRatio(findClosestAspectRatio(naturalRatio));
        } else {
            setAspectRatio(DEFAULT_ASPECT);
        }
    }, [images]);

    // Initialize or re-initialize crop data for all images when they change
    useEffect(() => {
        setImageCropData(
            images.map(img => ({
                crop: { x: 0, y: 0 },
                zoom: 1,
                aspect: aspectRatio,
                croppedAreaPixels: null,
            }))
        );
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
        // This is called on every interaction. We update the state for the current image.
        setImageCropData(prev => {
            const newData = [...prev];
            if (newData[currentIndex]) {
                newData[currentIndex] = {
                    ...newData[currentIndex],
                    croppedAreaPixels,
                    crop, // last known crop
                    zoom, // last known zoom
                };
            }
            return newData;
        });
    }, [currentIndex, crop, zoom]);

    const handleAspectRatioChange = (newAspect) => {
        const newAspectValue = parseFloat(newAspect);
        setAspectRatio(newAspectValue);
        // Crop data will be re-initialized by the useEffect watching `aspectRatio`
    };

    const selectImage = (index) => {
        if (index === currentIndex) return;

        // Save current crop data before switching
        setImageCropData(prev => {
            const newData = [...prev];
            if (newData[currentIndex]) {
                newData[currentIndex] = { ...newData[currentIndex], crop, zoom };
            }
            return newData;
        });
        setCurrentIndex(index);
    };
    
    const handleReorder = (newImageList, from, to) => {
        // Update the parent component's state
        setImages(newImageList);
    
        // Also reorder the crop data to match
        setImageCropData(prevCropData => {
            const newCropData = [...prevCropData];
            const [movedCropData] = newCropData.splice(from, 1);
            newCropData.splice(to, 0, movedCropData);
            return newCropData;
        });

        // If the currently selected item was moved, update the index to follow it
        if (currentIndex === from) {
            setCurrentIndex(to);
        } else if (currentIndex >= to && currentIndex < from) {
            // It was before the moved item and got shifted down
            setCurrentIndex(currentIndex + 1);
        } else if (currentIndex <= to && currentIndex > from) {
            // It was after the moved item and got shifted up
            setCurrentIndex(currentIndex - 1);
        }
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
        try {
            await postToInstagram({
                postId,
                images,
                caption,
                aspectRatio,
                imageCropData,
                rotation,
                nonce: pti_data.nonce_post_media,
            });
            onPostComplete();
        } catch (error) {
            alert(__('Error posting to Instagram:', 'post-to-instagram') + ' ' + error.message);
        }
    };

    const handleConfirmAndSchedule = async () => {
        try {
            await scheduleInstagramPost({
                postId,
                images,
                imageCropData,
                aspectRatio,
                caption,
                scheduleDateTime,
                rotation,
                nonce: pti_data.nonce_schedule_media,
            });
            onPostComplete();
        } catch (error) {
            alert(__('Error scheduling post:', 'post-to-instagram') + ' ' + error.message);
        }
    };

    if (!currentImage || !imageCropData.length) {
        return <Spinner />;
    }

    return (
        <Modal
            title={__('Review, Reorder & Crop', 'post-to-instagram')}
            onRequestClose={onClose}
            shouldCloseOnClickOutside={false}
            className="pti-multi-crop-modal pti-multi-crop-modal--tall"
        >
            <div className="pti-multi-crop-main-content">
                <div className="pti-crop-controls-header">
                    <SelectControl
                        label={__('Aspect Ratio', 'post-to-instagram')}
                        value={aspectRatio}
                        options={aspectRatios}
                        onChange={handleAspectRatioChange}
                        help={__('Applies to all images.', 'post-to-instagram')}
                        __nextHasNoMarginBottom
                        __next40pxDefaultSize={true}
                    />
                     <div className="pti-crop-navigation-info">
                        <span>{`${__('Image', 'post-to-instagram')} ${currentIndex + 1} / ${images.length}`}</span>
                    </div>
                </div>

                {showScheduleForm ? (
                    <div className="pti-schedule-form">
                        <h3>{__('Select Schedule Time', 'post-to-instagram')}</h3>
                        <DateTimePicker
                            currentDate={scheduleDateTime}
                            onChange={(newDate) => setScheduleDateTime(newDate)}
                            is12Hour={true}
                        />
                    </div>
                ) : (
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
                    </div>
                )}

                 <ReorderableThumbnails
                    images={images}
                    currentIndex={currentIndex}
                    onSelect={selectImage}
                    onReorder={handleReorder}
                />
            </div>
             {isProcessing && (
                 <div className="pti-processing-overlay">
                    <Spinner />
                    <p>{processingMessage}</p>
                </div>
            )}

            <div className="pti-multi-crop-footer">
                 {!showScheduleForm ? (
                    <>
                        <Button isSecondary onClick={onClose} disabled={isProcessing}>
                            {__('Cancel', 'post-to-instagram')}
                        </Button>
                        <div style={{ flex: 1 }} />
                        <Button isSecondary onClick={() => setShowScheduleForm(true)} disabled={isProcessing}>
                            {__('Schedule Post', 'post-to-instagram')}
                        </Button>
                        <Button isPrimary onClick={handleConfirmAndPost} disabled={isProcessing || images.length === 0}>
                            {isProcessing ? __('Posting...', 'post-to-instagram') : __('Post Now', 'post-to-instagram')}
                        </Button>
                    </>
                ) : (
                    <>
                        <Button isSecondary onClick={() => setShowScheduleForm(false)} disabled={isProcessing}>
                           {__('Back', 'post-to-instagram')}
                       </Button>
                       <div style={{ flex: 1 }} />
                        <Button isPrimary onClick={handleConfirmAndSchedule} disabled={isProcessing}>
                           {__('Confirm Schedule', 'post-to-instagram')}
                       </Button>
                   </>
                )}
            </div>
        </Modal>
    );
};

export default CropImageModal; 