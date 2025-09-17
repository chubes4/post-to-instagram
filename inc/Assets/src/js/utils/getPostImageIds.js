// Returns a unique array of all image IDs in the current post (including nested blocks, galleries, and featured image)
export function getPostImageIds() {
    if (!window.wp || !window.wp.data || !window.wp.data.select) return [];
    try {
        const blocks = window.wp.data.select('core/block-editor').getBlocks();
        let ids = [];
        const walkBlocks = (blockList) => {
            blockList.forEach(block => {
                if (block.name === 'core/image' && block.attributes && block.attributes.id) {
                    ids.push(Number(block.attributes.id));
                }
                if (block.name === 'core/gallery' && block.attributes && Array.isArray(block.attributes.ids)) {
                    ids.push(...block.attributes.ids.map(Number));
                }
                if (block.innerBlocks && block.innerBlocks.length) {
                    walkBlocks(block.innerBlocks);
                }
            });
        };
        walkBlocks(blocks);
        // Add featured image
        const featuredId = window.wp.data.select('core/editor').getEditedPostAttribute('featured_media');
        if (featuredId && !ids.includes(Number(featuredId))) {
            ids.push(Number(featuredId));
        }
        return Array.from(new Set(ids.filter(Boolean)));
    } catch (e) {
        return [];
    }
} 