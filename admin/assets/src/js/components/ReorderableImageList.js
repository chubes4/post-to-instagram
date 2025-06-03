import { useRef } from '@wordpress/element';

const getAspectRatioStyle = (aspectRatio) => ({
    width: 48,
    height: 48 / aspectRatio,
    objectFit: 'cover',
    borderRadius: 4,
    border: '1px solid #ccc',
    cursor: 'pointer',
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
            {images.map((img, i) => (
                <img
                    key={img.id}
                    src={img.url}
                    alt={img.alt || ''}
                    style={getAspectRatioStyle(aspectRatio)}
                    draggable
                    onDragStart={() => handleDragStart(i)}
                    onDragEnter={() => handleDragEnter(i)}
                    onDragEnd={handleDragEnd}
                    onClick={() => onPreview && onPreview(i)}
                    title="Drag to reorder. Click to preview."
                />
            ))}
        </div>
    );
};

export default ReorderableImageList; 