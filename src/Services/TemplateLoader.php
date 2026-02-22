<?php

namespace PostalWarmup\Services;

use PostalWarmup\Models\Database;

/**
 * Chargeur de templates (Helper)
 * Ported logic from includes/class-pw-template-loader.php
 */
class TemplateLoader {

	private static $cache = [];

	public static function load( $name, $domain = null ) {
		if ( isset( self::$cache[ $name ] ) ) {
			return self::$cache[ $name ];
		}

		// Check DB first (v3 feature)
		global $wpdb;
		$table = $wpdb->prefix . 'postal_templates';
		
		$db_template = $wpdb->get_row( $wpdb->prepare( "SELECT id, data, folder_id, status, tags, timezone FROM $table WHERE name = %s", $name ), ARRAY_A );
		
		if ( $db_template ) {
			$data = json_decode( $db_template['data'], true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				// Inject meta data for Admin usage
				$data['id'] = $db_template['id'];
				$data['name'] = $name; // Ensure name is present
				$data['folder_id'] = $db_template['folder_id'];
				$data['status'] = $db_template['status'];
				$data['timezone'] = $db_template['timezone'];
				// Handle legacy tags format (string vs array)
				// If tags in DB column (new format) use them, otherwise check JSON
				if ( ! empty( $db_template['tags'] ) ) {
					$data['tags'] = explode( ',', $db_template['tags'] );
				}

				self::$cache[ $name ] = $data;
				return $data;
			}
		}

		// Fallback to JSON files
		if ( defined( 'PW_TEMPLATES_DIR' ) ) {
			$file = PW_TEMPLATES_DIR . $name . '.json';
			if ( file_exists( $file ) ) {
				$content = file_get_contents( $file );
				$data = json_decode( $content, true );
				if ( json_last_error() === JSON_ERROR_NONE ) {
					if (!isset($data['name'])) {
						$data['name'] = $name;
					}
					self::$cache[ $name ] = $data;
					return $data;
				}
			}
		}

		// Return null if not found (v3.2.0 change to support shortcode fallback logic)
		return null;
	}

	public static function get_default_template() {
		return self::get_fallback();
	}

	public static function get_fallback() {
		return [
			'subject' => [ get_option( 'pw_default_subject', 'Hello' ) ],
			'text' => [ get_option( 'pw_default_text', 'This is a warmup email.' ) ],
			'html' => [ get_option( 'pw_default_html', '<p>This is a warmup email.</p>' ) ],
			'from_name' => [ get_option( 'pw_default_from_name', 'Support' ) ],
			'reply_to' => []
		];
	}

	public static function pick_random( $array ) {
		if ( ! is_array( $array ) || empty( $array ) ) return '';

		// Check for weighted arrays: ['text', weight]
		$weighted = false;
		$total_weight = 0;
		$items = [];

		foreach ( $array as $item ) {
			if ( is_array( $item ) && count( $item ) === 2 && is_numeric( $item[1] ) ) {
				$weighted = true;
				$items[] = [ 'value' => $item[0], 'weight' => (int) $item[1] ];
				$total_weight += (int) $item[1];
			} else {
				// Treat simple strings as weight 1
				$items[] = [ 'value' => $item, 'weight' => 1 ];
				$total_weight += 1;
			}
		}

		if ( $weighted && $total_weight > 0 ) {
			$rand = mt_rand( 1, $total_weight );
			$current = 0;
			foreach ( $items as $item ) {
				$current += $item['weight'];
				if ( $rand <= $current ) {
					return $item['value'];
				}
			}
		}

		// Fallback to simple random
		return $array[ array_rand( $array ) ];
	}

	public static function pick_weighted( $array ) {
		return self::pick_random( $array );
	}

	public static function apply_placeholders( $text, $vars ) {
		foreach ( $vars as $key => $value ) {
			$text = str_replace( "{{{$key}}}", $value, $text );
		}
		return $text;
	}
}
