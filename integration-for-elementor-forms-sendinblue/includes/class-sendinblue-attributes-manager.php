<?php

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sendinblue Attributes Manager
 * Handles fetching and caching of Brevo/Sendinblue contact attributes
 */
class Sendinblue_Attributes_Manager {

	private static $instance = null;

	/**
	 * Timeout (seconds) for the attributes request.
	 * This request runs synchronously while the Elementor editor loads,
	 * so it must stay well below typical gateway timeouts (60s).
	 */
	const REQUEST_TIMEOUT = 15;

	/**
	 * How long (seconds) a failed attributes request is cached,
	 * so a broken API key / IP allowlist does not hit Brevo on every editor load.
	 */
	const ERROR_CACHE_TTL = 300;

	/**
	 * Get singleton instance
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Fetch all attributes from the Brevo API
	 *
	 * The Brevo endpoint GET /v3/contacts/attributes is not paginated: it always
	 * returns every attribute in the account and ignores limit/offset.
	 *
	 * @param string $api_key The Brevo API key
	 * @param int $limit Deprecated, kept for backwards compatibility (not sent to the API)
	 * @param int $offset Deprecated, kept for backwards compatibility (not sent to the API)
	 * @return array|WP_Error Array of attributes or error
	 */
	public function fetch_attributes( $api_key, $limit = 0, $offset = 0 ) {

		if ( empty( $api_key ) ) {
			return new WP_Error( 'missing_api_key', __( 'API key is required', 'sendinblue-elementor-integration' ) );
		}

		$url = 'https://api.brevo.com/v3/contacts/attributes';

		$response = wp_remote_get( $url, array(
			'timeout' => self::REQUEST_TIMEOUT,
			'headers' => array(
				'accept' => 'application/json',
				'api-key' => $api_key,
			),
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$response_code = wp_remote_retrieve_response_code( $response );

		if ( $response_code !== 200 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'api_error', sprintf( __( 'API returned status %d: %s', 'sendinblue-elementor-integration' ), $response_code, $body ) );
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( ! is_array( $data ) || ! isset( $data['attributes'] ) || ! is_array( $data['attributes'] ) ) {
			return new WP_Error( 'invalid_response', __( 'Invalid API response', 'sendinblue-elementor-integration' ) );
		}

		return $this->normalize_attributes( $data['attributes'] );
	}

	/**
	 * Fetch all attributes (single request, the Brevo endpoint is not paginated)
	 *
	 * Previous versions looped with limit/offset. Brevo ignores those parameters,
	 * so accounts with 100+ attributes got the same batch back forever, which
	 * caused an endless loop and 504 timeouts in the Elementor editor.
	 *
	 * @param string $api_key The Brevo API key
	 * @param int $max_items Maximum number of items to return (0 for all)
	 * @return array|WP_Error Array of attributes or error
	 */
	public function fetch_all_attributes( $api_key, $max_items = 0 ) {

		$attributes = $this->fetch_attributes( $api_key );

		if ( is_wp_error( $attributes ) ) {
			return $attributes;
		}

		if ( $max_items > 0 ) {
			return array_slice( $attributes, 0, (int) $max_items, true );
		}

		return $attributes;
	}

	/**
	 * Normalize attributes from API response
	 *
	 * @param array $attributes Raw attributes from API
	 * @return array Normalized attributes
	 */
	private function normalize_attributes( $attributes ) {

		$normalized = array();

		foreach ( $attributes as $attribute ) {
			if ( ! isset( $attribute['name'] ) ) {
				continue;
			}

			$name = $attribute['name'];
			$normalized[ $name ] = array(
				'name' => $name,
				'type' => isset( $attribute['type'] ) ? $attribute['type'] : 'text',
				'category' => isset( $attribute['category'] ) ? $attribute['category'] : '',
				'enumeration' => isset( $attribute['enumeration'] ) ? $attribute['enumeration'] : array(),
			);
		}

		return $normalized;
	}

	/**
	 * Get cached attributes or fetch from API
	 * Cache expires after 1 hour
	 *
	 * @param string $api_key The Brevo API key
	 * @return array|WP_Error Array of attributes or error
	 */
	public function get_attributes( $api_key ) {

		if ( empty( $api_key ) ) {
			if ( webtica_sendinblue_free_debug_enabled() ) {
				error_log( 'Sendinblue Attributes Manager - Empty API key provided' );
			}
			return array();
		}

		$cache_key = 'sendinblue_attributes_' . md5( $api_key );
		$cached = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			if ( webtica_sendinblue_free_debug_enabled() ) {
				error_log( sprintf( 'Sendinblue Attributes Manager - Returning %d cached attributes', count( $cached ) ) );
			}
			return $cached;
		}

		if ( webtica_sendinblue_free_debug_enabled() ) {
			error_log( 'Sendinblue Attributes Manager - Cache miss, fetching from API...' );
		}

		$attributes = $this->fetch_all_attributes( $api_key );

		if ( is_wp_error( $attributes ) ) {
			if ( webtica_sendinblue_free_debug_enabled() ) {
				error_log( 'Sendinblue Attributes Manager - Error fetching attributes: ' . $attributes->get_error_message() );
			}

			// Cache the failure briefly so the editor does not retry (and wait) on every load.
			// "Clear Cache & Refresh" in the form action removes this immediately.
			set_transient( $cache_key, array(), self::ERROR_CACHE_TTL );

			return array();
		}

		if ( webtica_sendinblue_free_debug_enabled() ) {
			error_log( sprintf( 'Sendinblue Attributes Manager - Successfully fetched %d attributes from API', count( $attributes ) ) );
		}

		// Cache for 1 hour
		set_transient( $cache_key, $attributes, HOUR_IN_SECONDS );

		return $attributes;
	}

	/**
	 * Clear attributes cache
	 *
	 * @param string $api_key Optional API key to clear specific cache
	 */
	public function clear_cache( $api_key = '' ) {

		if ( ! empty( $api_key ) ) {
			$cache_key = 'sendinblue_attributes_' . md5( $api_key );
			delete_transient( $cache_key );
		} else {
			// Clear all caches matching pattern
			global $wpdb;
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_sendinblue_attributes_%'" );
			$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_sendinblue_attributes_%'" );
		}
	}
}
