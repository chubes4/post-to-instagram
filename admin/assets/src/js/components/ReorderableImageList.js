import { useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const getAspectRatioStyle = (aspectRatio) => ({
    width: 48,
    height: 48 / aspectRatio,
    objectFit: 'cover',
    borderRadius: 4,
    border: '1px solid #ccc',
    background: '#fff',
});

const ReorderableImageList = ({ images, setImages, aspectRatio, onPreview }) => {
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
        setImages(updated);
        dragItem.current = undefined;
        dragOverItem.current = undefined;
    };
    if (!images.length) return null;
    return (
        <div style={{ display: 'flex', flexWrap: 'wrap', gap: 8, marginTop: 8 }}>
            {images.map((img, i) => {
                let imageUrl = img.url;
                if (img.croppedServerUrl) {
                    imageUrl = img.croppedServerUrl;
                } else if (img.croppedBlob) {
                    imageUrl = URL.createObjectURL(img.croppedBlob);
                }
                return (
                    <div key={img.id || `cropped-${i}`} style={{ position: 'relative' }} title={__('Drag to reorder. Click to preview.', 'post-to-instagram')} >
                        <img
                            src={imageUrl}
                            alt={img.alt || ''}
                            style={{ ...getAspectRatioStyle(aspectRatio), cursor: 'pointer' }}
                            draggable
                            onDragStart={() => handleDragStart(i)}
                            onDragEnter={() => handleDragEnter(i)}
                            onDragEnd={handleDragEnd}
                            onClick={() => onPreview && onPreview(i)}
                        />
                    </div>
                );
            })}
        </div>
    );
};

export default ReorderableImageList; 