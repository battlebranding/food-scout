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

		// Relationships.
		add_action( 'p2p_init', [ $this, 'register_food_p2p_connection'] );

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

		$taste = $request->get_param( 'taste' );

		$food_query = array(
			'post_type'   => 'food',
			'post_status' => 'publish',
			'tax_query'   => array(
				array(
					'taxonomy' => 'taste',
					'field'    => 'slug',
					'terms'    => $taste,
				),
			),
		);

		$food = get_posts( $food_query );

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

			$object = array(
				'id'          => $result->ID,
				'name'        => $result->post_title,
				'slug'        => $result->post_name,
				'description' => $result->post_content,
				'cost'        => '0.00',
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
