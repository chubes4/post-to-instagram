/**
 * Creates a new image element from a URL.
 * @param {string} url - The image URL.
 * @returns {Promise<HTMLImageElement>} - A promise that resolves with the image element.
 */
const createImage = (url) =>
    new Promise((resolve, reject) => {
        const image = new Image();
        image.addEventListener('load', () => resolve(image));
        image.addEventListener('error', (error) => reject(error));
        // Needed to avoid cross-origin issues on CodeSandbox
        image.setAttribute('crossOrigin', 'anonymous');
        image.src = url;
    });

/**
 * Rotates an image.
 * @param {HTMLImageElement} image - The image to rotate.
 * @param {number} rotation - The rotation angle in degrees.
 * @returns {Promise<string>} - A promise that resolves with the rotated image as a data URL.
 */
// Not strictly needed for basic cropping if rotation is 0, but good to have
// For simplicity, we'll assume rotation is 0 for now or handled by react-easy-crop directly if supported.
// If direct canvas rotation is needed, this function can be expanded.

/**
 * This function was adapted from the react-easy-crop documentation
 * @param {string} imageSrc - Image source URL
 * @param {Object} pixelCrop - The pixel crop object { x, y, width, height }
 * @param {number} rotation - Rotation angle (currently unused in this simplified version)
 * @returns {Promise<Blob>} - A promise that resolves with the cropped image as a Blob.
 */
export async function getCroppedImg(imageSrc, pixelCrop, rotation = 0) {
    const image = await createImage(imageSrc);
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return null;
    }

    const radian = rotation * Math.PI / 180;

    // calculate bounding box of the rotated image
    const { width: bBoxWidth, height: bBoxHeight } = rotateSize(
        image.width,
        image.height,
        rotation
    );

    // set canvas size to match the bounding box
    canvas.width = bBoxWidth;
    canvas.height = bBoxHeight;

    // translate canvas context to a central location to allow rotating and drawing to image
    // then translate back to top left
    ctx.translate(bBoxWidth / 2, bBoxHeight / 2);
    ctx.rotate(radian);
    ctx.scale(1, 1); // Apply flip if needed: ctx.scale(flip.horizontal ? -1 : 1, flip.vertical ? -1 : 1)
    ctx.translate(-image.width / 2, -image.height / 2);

    // draw rotated image
    ctx.drawImage(image, 0, 0);

    // croppedAreaPixels values are bounding box relative
    // extract the cropped image using these values
    const data = ctx.getImageData(
        pixelCrop.x,
        pixelCrop.y,
        pixelCrop.width,
        pixelCrop.height
    );

    // set canvas width to final desired crop size - this will clear existing context
    canvas.width = pixelCrop.width;
    canvas.height = pixelCrop.height;

    // paste generated rotate image at the top left corner
    ctx.putImageData(data, 0, 0);

    // As a blob
    return new Promise((resolve, reject) => {
        canvas.toBlob((file) => {
            if (file) {
                // file.name = 'cropped.jpeg'; // Optional: assign a name
                resolve(file);
            } else {
                reject(new Error('Canvas to Blob conversion failed'));
            }
        }, 'image/jpeg', 0.9); // Adjust quality 0.0-1.0
    });
}

/**
 * Calculate the size of the bounding box of a rotated rectangle.
 * @param {number} width - The original width of the rectangle.
 * @param {number} height - The original height of the rectangle.
 * @param {number} rotation - The rotation angle in degrees.
 * @returns {{width: number, height: number}} - The width and height of the bounding box.
 */
function rotateSize(width, height, rotation) {
    const rotRad = rotation * Math.PI / 180;
    return {
        width: Math.abs(Math.cos(rotRad) * width) + Math.abs(Math.sin(rotRad) * height),
        height: Math.abs(Math.sin(rotRad) * width) + Math.abs(Math.cos(rotRad) * height),
    };
} 