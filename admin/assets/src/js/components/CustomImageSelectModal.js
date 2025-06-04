import { useState, useEffect } from '@wordpress/element';
import { Modal, Button, Card, CardMedia, CardBody, Spinner, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

const fetchImagesByIds = async (ids) => {
    if (!ids.length) return [];
    const params = ids.map(id => `include[]=${id}`).join('&');
    const resp = await fetch(`/wp-json/wp/v2/media?per_page=100&${params}`);
    if (!resp.ok) return [];
    return await resp.json();
};

const CustomImageSelectModal = ({
    allowedIds = [],
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
        const selectedImgs = images.filter(img => selected.includes(img.id)).map(img => ({
            id: img.id,
            url: img.source_url,
            alt: img.alt_text,
            width: img.media_details?.width,
            height: img.media_details?.height,
        }));
        setSelectedImages(selectedImgs);
        if (onClose) onClose();
    };

    return (
        <Modal
            title={__('Select Images for Instagram', 'post-to-instagram')}
            onRequestClose={onClose}
            shouldCloseOnClickOutside={false}
        >
            {loading && <Spinner />}
            {error && <Notice status="error" isDismissible={false}>{error}</Notice>}
            {notice && (
                <Notice status={notice.status} isDismissible={true} onRemove={() => setNotice(null)}>
                    {notice.message}
                </Notice>
            )}
            {!loading && !error && (
                <div style={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(120px, 1fr))', gap: 16, marginBottom: 24 }}>
                    {images.map(img => (
                        <Card
                            key={img.id}
                            isSelected={selected.includes(img.id)}
                            onClick={() => handleToggle(img.id)}
                            style={{ cursor: 'pointer', border: selected.includes(img.id) ? '2px solid #007cba' : '1px solid #ddd' }}
                        >
                            <CardMedia>
                                <img
                                    src={img.media_details?.sizes?.thumbnail?.source_url || img.source_url}
                                    alt={img.alt_text || ''}
                                    style={{ width: '100%', height: 80, objectFit: 'cover', borderRadius: 4 }}
                                />
                            </CardMedia>
                            <CardBody>
                                <input
                                    type="checkbox"
                                    checked={selected.includes(img.id)}
                                    readOnly
                                    style={{ marginRight: 8 }}
                                />
                                {img.title?.rendered || __('Image', 'post-to-instagram')}
                            </CardBody>
                        </Card>
                    ))}
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