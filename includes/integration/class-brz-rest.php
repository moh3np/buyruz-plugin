<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
// هشدار: پیش از هر تغییر، حتماً فایل CONTRIBUTING.md را با دقت کامل بخوانید و بی‌قید و شرط اجرا کنید و پس از اتمام کار تطابق را دوباره چک کنید.

class BRZ_Rest {
    public static function init() {
        add_action( 'rest_api_init', array( __CLASS__, 'register_fields' ) );
    }

    public static function register_fields() {
        register_rest_field(
            'product',
            'buyruz_compare_table',
            array(
                'get_callback'    => array( __CLASS__, 'get_value' ),
                'update_callback' => array( __CLASS__, 'update_value' ),
                'schema'          => array(
                    'description' => 'Buyruz Compare Table Data',
                    'type'        => 'object',
                    'context'     => array( 'view', 'edit' ),
                    'properties'  => array(
                        'enabled' => array(
                            'type' => 'boolean',
                        ),
                        'title'   => array(
                            'type' => 'string',
                        ),
                        'columns' => array(
                            'type'  => 'array',
                            'items' => array(
                                'type' => 'string',
                            ),
                        ),
                        'rows'    => array(
                            'type'  => 'array',
                            'items' => array(
                                'type'  => 'array',
                                'items' => array(
                                    'type' => 'string',
                                ),
                            ),
                        ),
                    ),
                ),
            )
        );
    }

    public static function get_value( $object, $field_name, $request ) {
        $post_id = $object['id'];
        $data    = get_post_meta( $post_id, '_buyruz_compare_table', true );
        
        if ( empty( $data ) ) {
            return null;
        }

        // Ensure it's an array if it was stored as JSON string previously
        if ( is_string( $data ) ) {
            $decoded = json_decode( $data, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                $data = $decoded;
            }
        }

        return $data;
    }

    public static function update_value( $value, $object, $field_name ) {
        if ( ! current_user_can( 'edit_product', $object->ID ) ) {
            return new WP_Error( 'rest_forbidden', 'Sorry, you cannot edit this resource.', array( 'status' => 403 ) );
        }

        // Basic validation could go here
        if ( ! is_array( $value ) ) {
             return new WP_Error( 'rest_invalid_param', 'Value must be an object/array.', array( 'status' => 400 ) );
        }

        return update_post_meta( $object->ID, '_buyruz_compare_table', $value );
    }
}
