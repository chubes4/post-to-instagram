import { useState, useEffect } from '@wordpress/element';
import { Modal, Button, Card, CardMedia, CardBody, Spinner, Notice, CheckboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const fetchImagesByIds = async (ids) => {
    if (!ids.length) return [];
    const params = ids.map(id => `include[]=${id}`).join('&');
    const resp = await fetch(`/wp-json/wp/v2/media?per_page=100&${params}`);
    if (!resp.ok) return [];
    return await resp.json();
};

const CustomImageSelectModal = ({
    allowedIds = window.pti_data && Array.isArray(window.pti_data.content_image_ids) ? window.pti_data.content_image_ids : [],
    selectedImages = [],
    setSelectedImages,
    onClose,
    maxSelect = 10,
}) => {
    const [images, setImages] = useState([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);
    const [selected, setSelected] = useState(selectedImages.map(img => img.id));
    const [notice, setNotice] = useState(null);
    const [includePosted, setIncludePosted] = useState(false);
    const sharedImageIds = (window.pti_data && Array.isArray(window.pti_data.shared_image_ids)) ? window.pti_data.shared_image_ids : [];

    useEffect(() => {
        setLoading(true);
        fetchImagesByIds(allowedIds)
            .then(data => {
                setImages(data);
                setLoading(false);
            })
            .catch(() => {
                setError(__('Failed to load images.', 'post-to-instagram'));
                setLoading(false);
            });
    }, [allowedIds && allowedIds.join(',')]);

    // Filter images based on includePosted
    const filteredImages = includePosted
        ? images
        : images.filter(img => !sharedImageIds.includes(img.id));

    const handleToggle = (id) => {
        if (selected.includes(id)) {
            setSelected(selected.filter(sid => sid !== id));
        } else {
            if (selected.length >= maxSelect) {
                setNotice({ status: 'error', message: __('You can select a maximum of 10 images.', 'post-to-instagram') });
                return;
            }
            setSelected([...selected, id]);
        }
    };

    const handleSelect = () => {
        const selectedImgs = selected.map(id => {
            const img = images.find(i => i.id === id);
            return {
                id: img.id,
                url: img.source_url,
                originalUrl: img.source_url, // For the cropper
                alt: img.alt_text,
                width: img.media_details?.width,
                height: img.media_details?.height,
            };
        });
        setSelectedImages(selectedImgs);
        if (onClose) onClose();
    };

    return (
        <Modal
            title={__('Select Images for Instagram', 'post-to-instagram')}
            onRequestClose={onClose}
            shouldCloseOnClickOutside={false}
        >
            <CheckboxControl
                label={__('Include already posted images', 'post-to-instagram')}
                checked={includePosted}
                onChange={setIncludePosted}
                __nextHasNoMarginBottom={true}
            />
            {loading && <Spinner />}
            {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
            {notice && (
                <Notice status={notice.status} isDismissible={true} onRemove={() => setNotice(null)}>
                    {notice.message}
                </Notice>
            )}
            {!loading && !error && (
                <div className="pti-image-grid">
                    {filteredImages.map(img => {
                        const isPosted = sharedImageIds.includes(img.id);
                        const isSelected = selected.includes(img.id);
                        const orderIndex = isSelected ? selected.indexOf(img.id) : -1;
                        return (
                            <Card
                                key={img.id}
                                isSelected={isSelected}
                                onClick={() => handleToggle(img.id)}
                                className={`pti-image-card${isSelected ? ' pti-image-card--selected' : ''}`}
                            >
                                <CardMedia>
                                    <img
                                        src={img.media_details?.sizes?.thumbnail?.source_url || img.source_url}
                                        alt={img.alt_text || ''}
                                        className="pti-image-card-img"
                                    />
                                    {isPosted && (
                                        <span className="pti-image-card-posted">
                                            {__('Posted', 'post-to-instagram')}
                                        </span>
                                    )}
                                    {isSelected && (
                                        <span className="pti-image-card-selected">
                                            {`${orderIndex + 1}/${maxSelect}`}
                                        </span>
                                    )}
                                </CardMedia>
                            </Card>
                        );
                    })}
                </div>
            )}
            <div style={{ display: 'flex', justifyContent: 'flex-end', gap: 8 }}>
                <Button isSecondary onClick={onClose}>{__('Cancel', 'post-to-instagram')}</Button>
                <Button isPrimary onClick={handleSelect} disabled={!selected.length}>
                    {__('Select', 'post-to-instagram')}
                </Button>
            </div>
        </Modal>
    );
};

export default CustomImageSelectModal; 