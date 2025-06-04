<?php
if ( ! defined( 'WPINC' ) ) { die; }

/**
 * Convert an attachment to JPEG if needed. Returns an array:
 * [ 'url' => string, 'temp_file' => string|null ]
 * If already JPEG, temp_file is null.
 */
function pti_convert_to_jpg_if_needed( $attachment_id ) {
    $file = get_attached_file( $attachment_id );
    $mime = get_post_mime_type( $attachment_id );
    if ( $mime === 'image/jpeg' ) {
        return array(
            'url' => wp_get_attachment_url( $attachment_id ),
            'temp_file' => null,
        );
    }
    $editor = wp_get_image_editor( $file );
    if ( is_wp_error( $editor ) ) {
        return false;
    }
    $upload_dir = wp_upload_dir();
    $temp_dir = $upload_dir['basedir'] . '/pti-temp/';
    if ( ! file_exists( $temp_dir ) ) {
        wp_mkdir_p( $temp_dir );
    }
    $temp_file = $temp_dir . 'pti-' . $attachment_id . '-' . uniqid() . '.jpg';
    $editor->set_mime_type( 'image/jpeg' );
    $result = $editor->save( $temp_file, 'image/jpeg' );
    if ( is_wp_error( $result ) ) {
        return false;
    }
    $temp_url = $upload_dir['baseurl'] . '/pti-temp/' . basename( $temp_file );
    return array(
        'url' => $temp_url,
        'temp_file' => $temp_file,
    );
}

/**
 * Delete a temp file by path (if it exists).
 */
function pti_cleanup_temp_jpg( $temp_file ) {
    if ( $temp_file && file_exists( $temp_file ) ) {
        @unlink( $temp_file );
    }
} 