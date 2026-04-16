<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Companies House API client + Claude tool definition.
 * https://developer.company-information.service.gov.uk/
 *
 * Auth is HTTP Basic: the API key is the username and the password is empty.
 */
class PQC_CompaniesHouse {

	const BASE = 'https://api.company-information.service.gov.uk';

	public static function is_configured() {
		$settings = pqc_get_settings();
		return ! empty( $settings['companies_house_api_key'] );
	}

	/**
	 * Claude tool definition exposed when the API key is configured.
	 */
	public static function tool_definition() {
		return [
			'name'        => 'companies_house_lookup',
			'description' => 'Look up authoritative UK company information from the official Companies House register. '
				. "Call this for every limited company named in any quote pack (the installer, the shell manufacturer or importer, and any named sub-contractor). "
				. "Workflow: call action='search' first with the company name to find the company_number, then call action='profile' with that number for status/incorporation/accounts, then 'officers' for director history and 'filing_history' for recent filings. "
				. 'Use this alongside web_search (which you must also use for reviews and news). Do not rely on marketing or quote-pack claims alone.',
			'input_schema' => [
				'type'       => 'object',
				'properties' => [
					'action'         => [
						'type'        => 'string',
						'enum'        => [ 'search', 'profile', 'officers', 'filing_history', 'persons_with_significant_control', 'charges' ],
						'description' => 'Which endpoint to call.',
					],
					'query'          => [
						'type'        => 'string',
						'description' => 'For action=search: the company name (or part of it). Ignored for other actions.',
					],
					'company_number' => [
						'type'        => 'string',
						'description' => 'The Companies House number (e.g. "01234567" or "OC123456"). Required for all actions except "search".',
					],
					'items_per_page' => [
						'type'        => 'integer',
						'description' => 'Results per page (default 10, max 50 for search; 35 for filings/officers).',
						'minimum'     => 1,
						'maximum'     => 50,
					],
				],
				'required'   => [ 'action' ],
			],
		];
	}

	/**
	 * Execute a tool call and return a string payload suitable for tool_result content.
	 *
	 * @param array $input Decoded tool_use input.
	 * @return string JSON string of the result, or an error message.
	 */
	public static function execute( $input ) {
		if ( ! is_array( $input ) ) {
			return 'Error: invalid tool input.';
		}
		$action = isset( $input['action'] ) ? (string) $input['action'] : '';
		$per    = isset( $input['items_per_page'] ) ? (int) $input['items_per_page'] : 10;
		$per    = max( 1, min( 50, $per ) );

		switch ( $action ) {
			case 'search':
				$q = isset( $input['query'] ) ? trim( (string) $input['query'] ) : '';
				if ( $q === '' ) {
					return 'Error: action=search requires "query".';
				}
				return self::format_result( self::get( '/search/companies', [ 'q' => $q, 'items_per_page' => $per ] ) );

			case 'profile':
				$num = self::normalize_number( $input['company_number'] ?? '' );
				if ( $num === '' ) {
					return 'Error: action=profile requires "company_number".';
				}
				return self::format_result( self::get( '/company/' . rawurlencode( $num ) ) );

			case 'officers':
				$num = self::normalize_number( $input['company_number'] ?? '' );
				if ( $num === '' ) {
					return 'Error: action=officers requires "company_number".';
				}
				return self::format_result( self::get( '/company/' . rawurlencode( $num ) . '/officers', [ 'items_per_page' => min( 35, $per ) ] ) );

			case 'filing_history':
				$num = self::normalize_number( $input['company_number'] ?? '' );
				if ( $num === '' ) {
					return 'Error: action=filing_history requires "company_number".';
				}
				return self::format_result( self::get( '/company/' . rawurlencode( $num ) . '/filing-history', [ 'items_per_page' => min( 35, $per ) ] ) );

			case 'persons_with_significant_control':
				$num = self::normalize_number( $input['company_number'] ?? '' );
				if ( $num === '' ) {
					return 'Error: action=persons_with_significant_control requires "company_number".';
				}
				return self::format_result( self::get( '/company/' . rawurlencode( $num ) . '/persons-with-significant-control', [ 'items_per_page' => min( 35, $per ) ] ) );

			case 'charges':
				$num = self::normalize_number( $input['company_number'] ?? '' );
				if ( $num === '' ) {
					return 'Error: action=charges requires "company_number".';
				}
				return self::format_result( self::get( '/company/' . rawurlencode( $num ) . '/charges', [ 'items_per_page' => min( 35, $per ) ] ) );

			default:
				return 'Error: unknown action. Allowed: search, profile, officers, filing_history, persons_with_significant_control, charges.';
		}
	}

	private static function normalize_number( $num ) {
		$num = strtoupper( preg_replace( '/\s+/', '', (string) $num ) );
		$num = preg_replace( '/[^A-Z0-9]/', '', $num );
		return $num;
	}

	private static function get( $path, array $query = [] ) {
		$settings = pqc_get_settings();
		$key      = isset( $settings['companies_house_api_key'] ) ? trim( $settings['companies_house_api_key'] ) : '';
		if ( $key === '' ) {
			return [ '__error' => 'Companies House API key is not configured.' ];
		}

		$url = self::BASE . $path;
		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$response = wp_remote_get( $url, [
			'timeout' => 20,
			'headers' => [
				'Authorization' => 'Basic ' . base64_encode( $key . ':' ),
				'Accept'        => 'application/json',
			],
		] );

		if ( is_wp_error( $response ) ) {
			return [ '__error' => 'Request failed: ' . $response->get_error_message() ];
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code === 404 ) {
			return [ '__error' => 'Not found (HTTP 404).' ];
		}
		if ( $code === 401 || $code === 403 ) {
			return [ '__error' => 'Companies House rejected the API key (HTTP ' . $code . ').' ];
		}
		if ( $code === 429 ) {
			return [ '__error' => 'Companies House rate-limited this request (HTTP 429). Try fewer lookups.' ];
		}
		if ( $code < 200 || $code >= 300 ) {
			return [ '__error' => 'Companies House returned HTTP ' . $code . '.' ];
		}
		if ( ! is_array( $data ) ) {
			return [ '__error' => 'Malformed response.' ];
		}
		return $data;
	}

	private static function format_result( $data ) {
		// Return compact JSON so tool_result stays small but structured.
		return wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
