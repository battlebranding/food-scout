<?php
/**
 * Plugin Name: Food Scout API
 * Descripton: The API for Food Scout
 * Version: 0.1.0
 * Author: Marcus Battle
 */

/**
 * The Food Scout API class.
 *
 * @since 0.1.0
 */
class Food_Scout_API {

	/**
	 * The plugin class.
	 *
	 * @var   Food_Scout_API
	 * @since 0.1.0
	 */
	protected static $single_instance = null;

	/**
	 * Create an instance of the Food Scout API object.
	 *
	 * @since 0.1.0
	 */
	static function init() {

		if ( null === self::$single_instance ) {
			self::$single_instance = new self();
		}

		return self::$single_instance;

	}

	/**
	 * Add the plugin hooks.
	 *
	 * @since 0.1.0
	 */
	public function hooks() {

		// Post Types.
		add_action( 'init', [ $this, 'register_restaurant_cpt' ], 10 );
		add_action( 'init', [ $this, 'register_food_cpt' ], 10 );
		add_action( 'init', [ $this, 'register_taste_taxonomy' ], 10 );

		// Actions.
		add_action( 'save_post', [ $this, 'set_restaurant_geolocation' ], 50, 3 );

		// Relationships.
		add_action( 'p2p_init', [ $this, 'register_food_p2p_connection' ] );

		// API.
		add_action( 'rest_api_init', [ $this, 'register_api_endpoints' ] );
	}

	/**
	 * Register the 'Food' CPT.
	 *
	 * @since 0.1.0
	 */
	public function register_restaurant_cpt() {

		$args = array(
			'public'    => true,
			'label'     => 'Restaurants',
			'menu_icon' => 'dashicons-store',
			'rewrite'   => array(
				'slug' => 'restaurants',
			),
		);

		register_post_type( 'restaurant', $args );
	}

	/**
	 * Register the 'Food' CPT.
	 *
	 * @since 0.1.0
	 */
	public function register_food_cpt() {

		$args = array(
			'public'    => true,
			'label'     => 'Food',
			'menu_icon' => 'dashicons-carrot',
		);

		register_post_type( 'food', $args );
	}

	/**
	 * Register the 'Taste' custom taxonomy.
	 *
	 * @since 0.1.0
	 */
	public function register_taste_taxonomy() {

		register_taxonomy(
			'taste',
			'food',
			array(
				'label'        => __( 'Taste' ),
				'hierarchical' => false,
			)
		);
	}

	/**
	 * Creates a Post 2 Post connection for food to restaurants.
	 *
	 * @since 0.1.0
	 */
	public function register_food_p2p_connection() {

		p2p_register_connection_type( array(
			'name' => 'food_to_restaurant',
			'from' => 'food',
			'to'   => 'restaurant',
		) );
	}

	/**
	 * Get the restauratn geolocation from Google Maps API.
	 *
	 * If this doesn't work, the alternative is https://geocod.io/.
	 *
	 * @since 0.1.0
	 *
	 * @param integer $post_id     The post ID.
	 * @param WP_Post $post_after  The post object after the update.
	 * @param WP_Post $post_before The post object before the update.
	 */
	public function set_restaurant_geolocation( $post_id, $post_after, $post_before ) {

		$restaurant_address = str_replace( ' ', '+', $this->build_restaurant_address( $post_id ) );
		$geonames_url       = "http://maps.googleapis.com/maps/api/geocode/json?address={$restaurant_address}";

		$response = wp_remote_get( $geonames_url, array(
			'timeout' => 30,
		) );

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ) );

		// Capture the geolocation.
		$geolocation = isset( $data->results[0]->geometry->location ) ? $data->results[0]->geometry->location : null;

		$latitude  = $geolocation->lat;
		$longitude = $geolocation->lng;

		update_post_meta( $post_id, 'latitude', $latitude );
		update_post_meta( $post_id, 'longitude', $longitude );
	}

	/**
	 * Build the restaurant address into one string.
	 *
	 * @since 0.1.0
	 *
	 * @param integer $post_id The post ID.
	 *
	 * @return string $restaurant_address
	 */
	public function build_restaurant_address( $post_id ) {

		$restaurant_address  = get_post_meta( $post_id, 'address', true );
		$restaurant_address .= ' ' . get_post_meta( $post_id, 'city', true );
		$restaurant_address .= ', ' . get_post_meta( $post_id, 'state', true );
		$restaurant_address .= ' ' . get_post_meta( $post_id, 'zip', true );

		return $restaurant_address;
	}

	/**
	 * Register the API endpoints.
	 *
	 * @since 0.1.0
	 */
	public function register_api_endpoints() {

		register_rest_route( 'food-scout/v1', '/restaurants', array(
			'methods' => 'GET',
			'callback' => [ $this, 'get_restaurants_via_api' ],
		) );

		register_rest_route( 'food-scout/v1', '/food', array(
			'methods' => 'GET',
			'callback' => [ $this, 'get_food_via_api' ],
		) );

		register_rest_route( 'food-scout/v1', '/taste', array(
			'methods' => 'GET',
			'callback' => [ $this, 'get_taste_via_api' ],
		) );
	}

	/**
	 * GET the restaurants CPT.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The WP Rest Request object.
	 */
	public function get_restaurants_via_api( $request ) {

		$restaurant_query = array(
			'post_type'   => 'restaurant',
			'post_status' => 'publish',
		);

		$restaurants = get_posts( $restaurant_query );

		wp_send_json_success( $this->parse_restaurants( $restaurants ) );
	}

	/**
	 * GET the food CPT.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The WP Rest Request object.
	 */
	public function get_food_via_api( $request ) {

		$taste     = $request->get_param( 'taste' );
		$latitude  = $request->get_param( 'latitude' );
		$longitude = $request->get_param( 'longitude' );
		$radius    = $request->get_param( 'radius' ) ? $request->get_param( 'radius' ) : 25;

		// Get the restaurants first. Then get all of the food from those restuarants.
		global $wpdb;

		// @TODO: Add LIMIT to query.
		$sql = "
		SELECT ID, distance 
		FROM (
			SELECT 
				posts.ID,
				3956 * ACOS( COS( RADIANS( {$latitude} ) ) * COS( RADIANS( latitude.meta_value ) ) * COS( RADIANS( {$longitude} ) - RADIANS( longitude.meta_value ) ) + SIN( RADIANS( {$latitude} ) ) * SIN( RADIANS( latitude.meta_value ) ) ) AS distance,
				latitude.meta_value AS latitude, 
				{$latitude} - ( {$radius} / 69 ) AS latitude_min,
				{$latitude} + ( {$radius} / 69 ) AS latitude_max,
				longitude.meta_value AS longitude,
				{$longitude} - ( {$radius} / ( 69 * COS( RADIANS( {$latitude} ) ) ) ) AS longitude_min,
				{$longitude} + ( {$radius} / ( 69 * COS( RADIANS( {$latitude} ) ) ) ) AS longitude_max
			FROM $wpdb->posts AS posts
			LEFT JOIN $wpdb->postmeta latitude ON ( posts.ID = latitude.post_id )
			LEFT JOIN $wpdb->postmeta longitude ON ( posts.ID = longitude.post_id )
			WHERE
				latitude.meta_key = 'latitude' AND latitude.meta_value IS NOT NULL
				AND longitude.meta_key = 'longitude' AND longitude.meta_value IS NOT NULL
				AND latitude.meta_value BETWEEN ( {$latitude} - ( {$radius} / 69 ) ) AND ( {$latitude} + ( {$radius} / 69 ) )
				AND longitude.meta_value BETWEEN ( {$longitude} - ( {$radius} / ( 69 * COS( RADIANS( {$latitude} ) ) ) ) ) AND ( {$longitude} + ( {$radius} / ( 69 * COS( RADIANS( {$latitude} ) ) ) ) )
		) r
		WHERE distance < {$radius}
		ORDER BY distance ASC";

		$results = $wpdb->get_results( $sql );

		if ( empty( $results ) ) {
			wp_send_json_success( array() );
		}

		$restaurant_ids = array_column( $results, 'ID' );

		// Get all of the food from the area restaurants near me.
		$connected = new WP_Query( array(
			'connected_type'  => 'food_to_restaurant',
			'connected_items' => $restaurant_ids,
			'nopaging'        => true,
			'posts_per_page'  => 1,
			'post_status'     => 'publish',
			'tax_query'       => array(
				array(
					'taxonomy' => 'taste',
					'field'    => 'slug',
					'terms'    => $taste,
				),
			),
		) );

		$food = $connected->have_posts() ? $connected->posts : array();

		wp_send_json_success( $this->parse_food( $food ) );
	}

	/**
	 * GET the taste taxonomy.
	 *
	 * @since 0.1.0
	 *
	 * @param WP_REST_Request $request The WP Rest Request object.
	 */
	public function get_taste_via_api( $request ) {

		$search_query = strtolower( $request->get_param( 'q' ) );

		if ( empty( $search_query ) ) {
			wp_send_json_success( array() );
		}

		$terms = get_terms( array(
			'hide_empty' => false,
			'taxonomy'   => 'taste',
			'search'     => $search_query,
		) );

		wp_send_json_success( $this->parse_taste( $terms ) );
	}

	/**
	 * Parses the results from an API request and converts to preferred indices for restaurants.
	 *
	 * @since 0.1.0
	 *
	 * @param array $results The results from a query to parse.
	 */
	public function parse_restaurants( $results = array() ) {

		if ( empty( $results ) ) {
			return $results;
		}

		$objects = array();

		foreach ( $results as $key => $result ) {

			$object = array(
				'id'          => $result->ID,
				'name'        => $result->post_title,
				'slug'        => $result->post_name,
				'description' => $result->post_content,
				'food_count'  => 0,
				'address'     => array(
					'latitude'  => get_post_meta( $result->ID, 'latitude', true ),
					'longitude' => get_post_meta( $result->ID, 'longitude', true ),
				),
			);

			$objects[] = $object;
		}

		return $objects;
	}

	/**
	 * Parses the results from an API request and converts to preferred indices for food.
	 *
	 * @since 0.1.0
	 *
	 * @param array $results The results from a query to parse.
	 */
	public function parse_food( $results = array() ) {

		if ( empty( $results ) ) {
			return $results;
		}

		$objects = array();

		foreach ( $results as $key => $result ) {

			// Get the connected restaurant.
			$connected = new WP_Query( array(
				'connected_type'  => 'food_to_restaurant',
				'connected_items' => $result,
				'nopaging'        => true,
				'posts_per_page'  => 1,
			) );

			$object = array(
				'id'          => $result->ID,
				'name'        => $result->post_title,
				'slug'        => $result->post_name,
				'description' => $result->post_content,
				'cost'        => '0.00',
				'restaurant'  => $connected->have_posts() ? $this->parse_restaurants( $connected->posts )[0] : null,
				'taste'       => $this->parse_taste( wp_get_object_terms( $result->ID, 'taste' ) ),
			);

			$objects[] = $object;
		}

		return $objects;
	}

	/**
	 * Parses the results from an API request and converts to preferred indices.
	 *
	 * @since 0.1.0
	 *
	 * @param array $results The results from a query to parse.
	 */
	public function parse_taste( $results = array() ) {

		if ( empty( $results ) ) {
			return $results;
		}

		$objects = array();

		foreach ( $results as $key => $result ) {

			$object = array(
				'id'          => $result->term_id,
				'name'        => $result->name,
				'slug'        => $result->slug,
				'count'       => $result->count,
				'description' => $result->description,
				'type'        => '',
			);

			$objects[] = $object;
		}

		return $objects;
	}
}

add_action( 'plugins_loaded', array( Food_Scout_API::init(), 'hooks' ), 10 );
