/**
 * Calculate fallback center crop for an image
 * @param {number} imgW - Image width
 * @param {number} imgH - Image height  
 * @param {number} aspectRatio - Target aspect ratio
 * @returns {object} Crop area pixels {x, y, width, height}
 */
export function calculateCenterCrop(imgW, imgH, aspectRatio) {
    let cropW, cropH, x, y;
    
    if (imgW / imgH > aspectRatio) {
        cropH = imgH;
        cropW = imgH * aspectRatio;
        x = (imgW - cropW) / 2;
        y = 0;
    } else {
        cropW = imgW;
        cropH = imgW / aspectRatio;
        x = 0;
        y = (imgH - cropH) / 2;
    }
    
    return { x, y, width: cropW, height: cropH };
}