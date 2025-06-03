import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const getImageIdsFromBlocks = () => {
    if (!window.wp || !window.wp.data || !window.wp.data.select) return null;
    try {
        const blocks = window.wp.data.select('core/block-editor').getBlocks();
        let ids = [];
        blocks.forEach(block => {
            if (block.name === 'core/image' && block.attributes && block.attributes.id) {
                ids.push(block.attributes.id);
            }
            if (block.name === 'core/gallery' && block.attributes && Array.isArray(block.attributes.ids)) {
                ids = ids.concat(block.attributes.ids);
            }
        });
        return Array.from(new Set(ids.map(Number)));
    } catch (e) {
        return null;
    }
};

const ImageSelectModal = ({ selectedImages, setSelectedImages, postId, onClose }) => {
    const mediaFrameRef = useRef(null);
    // Dynamically get image IDs from the block editor, fallback to PHP-provided IDs
    const dynamicIds = getImageIdsFromBlocks();
    const allowedIds = (dynamicIds && dynamicIds.length)
        ? dynamicIds
        : (window.pti_data && window.pti_data.content_image_ids) || [];

    useEffect(() => {
        if (!window.wp || !window.wp.media) return;
        if (!allowedIds.length) {
            alert(__('No images found in this post. Please add images to the post content or set a featured image.', 'post-to-instagram'));
            if (onClose) onClose();
            return;
        }
        if (mediaFrameRef.current) {
            mediaFrameRef.current.open();
            return;
        }
        const frame = wp.media({
            title: __('Select Images for Instagram', 'post-to-instagram'),
            library: {
                type: 'image',
                query: allowedIds.length ? { include: allowedIds } : {},
            },
            multiple: true,
            button: { text: __('Select', 'post-to-instagram') },
        });
        mediaFrameRef.current = frame;
        frame.on('select', () => {
            const selection = frame.state().get('selection');
            const images = selection.toArray().slice(0, 10).map(att => ({
                id: att.id,
                url: att.get('url'),
                alt: att.get('alt'),
                width: att.get('width'),
                height: att.get('height'),
            }));
            setSelectedImages(images);
            if (onClose) onClose();
        });
        frame.on('open', () => {
            const selection = frame.state().get('selection');
            selection.reset();
            if (selectedImages && selectedImages.length) {
                const attachments = selectedImages.map(img => wp.media.attachment(img.id));
                attachments.forEach(att => selection.add(att));
            }
        });
        frame.on('close', () => {
            if (onClose) onClose();
        });
        frame.open();
        // Cleanup on unmount
        return () => {
            if (mediaFrameRef.current) {
                mediaFrameRef.current.off('select');
                mediaFrameRef.current.off('open');
                mediaFrameRef.current.off('close');
                mediaFrameRef.current = null;
            }
        };
    }, [allowedIds && allowedIds.join(',')]);
    return null; // This component does not render anything itself
};

export default ImageSelectModal; 