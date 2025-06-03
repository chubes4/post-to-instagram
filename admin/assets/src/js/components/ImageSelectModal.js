import { useEffect, useRef } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

const getImageIdsFromBlocks = () => {
    if (!window.wp || !window.wp.data || !window.wp.data.select) return null;
    try {
        const blocks = window.wp.data.select('core/block-editor').getBlocks();
        let ids = [];
        blocks.forEach(block => {
            if (block.name === 'core/image' && block.attributes && block.attributes.id) {
                ids.push(Number(block.attributes.id));
            }
            if (block.name === 'core/gallery' && block.attributes && Array.isArray(block.attributes.ids)) {
                ids = ids.concat(block.attributes.ids.map(Number));
            }
        });
        return Array.from(new Set(ids));
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

        // Custom AttachmentFilters view
        const AttachmentFilters = window.wp.media.view.AttachmentFilters.extend({
            createFilters: function() {
                this.filters = {
                    in_this_post: {
                        text: __('Images in this Post', 'post-to-instagram'),
                        props: {
                            type: 'image',
                            uploadedTo: null, // show all images, filter in filterAttachments
                        },
                        priority: 100
                    }
                };
            },
            change: function(event) {
                var filter = this.filters[this.el.value];
                if (filter) {
                    this.model.set(filter.props);
                }
                this.model.trigger('change');
            },
            filterAttachments: function(attachments) {
                // Only show attachments whose ID is in allowedIds
                return attachments.filter(att => allowedIds.includes(Number(att.id)));
            }
        });

        const frame = wp.media({
            frame: 'post',
            state: 'gallery',
            title: __('Create gallery', 'post-to-instagram'),
            multiple: true,
            library: {
                type: 'image',
            },
            button: { text: __('Select', 'post-to-instagram') },
        });
        mediaFrameRef.current = frame;

        frame.on('ready', () => {
            const state = frame.state();
            // Remove all existing filters and add our custom one
            state.filters = new AttachmentFilters({
                controller: frame,
                model: state,
                priority: 100
            });
            state.filters.render();
            // Set default filter
            state.set('filter', 'in_this_post');
            // Insert the filter dropdown into the toolbar
            const toolbar = state.toolbar;
            if (toolbar && toolbar.$el && state.filters.$el) {
                toolbar.$el.find('.media-toolbar-primary').prepend(state.filters.$el);
            }
        });

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
                mediaFrameRef.current.off('ready');
                mediaFrameRef.current = null;
            }
        };
    }, [allowedIds && allowedIds.join(',')]);
    return null; // This component does not render anything itself
};

export default ImageSelectModal; 