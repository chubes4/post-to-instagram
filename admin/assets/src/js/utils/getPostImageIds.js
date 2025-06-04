// Returns a unique array of all image IDs in the current post (including nested blocks)
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
                if (block.innerBlocks && block.innerBlocks.length) {
                    walkBlocks(block.innerBlocks);
                }
            });
        };
        walkBlocks(blocks);
        return Array.from(new Set(ids));
    } catch (e) {
        return [];
    }
} 