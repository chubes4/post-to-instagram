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
        image.setAttribute('crossOrigin', 'anonymous');
        image.src = url;
    });

/**
 * Crop and rotate image using HTML5 canvas.
 *
 * Adapted from react-easy-crop documentation.
 *
 * @param {string} imageSrc - Image source URL
 * @param {Object} pixelCrop - Crop area { x, y, width, height }
 * @param {number} rotation - Rotation angle in degrees
 * @returns {Promise<Blob>} JPEG blob for upload
 */
export async function getCroppedImg(imageSrc, pixelCrop, rotation = 0) {
    const image = await createImage(imageSrc);
    const canvas = document.createElement('canvas');
    const ctx = canvas.getContext('2d');

    if (!ctx) {
        return null;
    }

    const radian = rotation * Math.PI / 180;

    const { width: bBoxWidth, height: bBoxHeight } = rotateSize(
        image.width,
        image.height,
        rotation
    );

    canvas.width = bBoxWidth;
    canvas.height = bBoxHeight;

    ctx.translate(bBoxWidth / 2, bBoxHeight / 2);
    ctx.rotate(radian);
    ctx.scale(1, 1);
    ctx.translate(-image.width / 2, -image.height / 2);

    ctx.drawImage(image, 0, 0);

    const data = ctx.getImageData(
        pixelCrop.x,
        pixelCrop.y,
        pixelCrop.width,
        pixelCrop.height
    );

    canvas.width = pixelCrop.width;
    canvas.height = pixelCrop.height;

    ctx.putImageData(data, 0, 0);

    return new Promise((resolve, reject) => {
        canvas.toBlob((file) => {
            if (file) {
                resolve(file);
            } else {
                reject(new Error('Canvas to Blob conversion failed'));
            }
        }, 'image/jpeg', 0.9);
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