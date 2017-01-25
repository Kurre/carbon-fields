<?php

namespace Carbon_Fields\Loader;

use \Carbon_Fields\Pimple\Container as PimpleContainer;
use \Carbon_Fields\Container\Repository as ContainerRepository;
use \Carbon_Fields\Templater\Templater;
use \Carbon_Fields\Libraries\Sidebar_Manager\Sidebar_Manager;
use \Carbon_Fields\Libraries\Plugin_Update_Warning\Plugin_Update_Warning;
use \Carbon_Fields\Exception\Incorrect_Syntax_Exception;

/**
 * Loader and main initialization
 */
class Loader {

	protected $templater;

	protected $sidebar_manager;

	protected $plugin_update_warning;

	protected $container_repository;

	public function __construct( Templater $templater, Sidebar_Manager $sidebar_manager, Plugin_Update_Warning $plugin_update_warning, ContainerRepository $container_repository ) {
		$this->templater = $templater;
		$this->sidebar_manager = $sidebar_manager;
		$this->plugin_update_warning = $plugin_update_warning;
		$this->container_repository = $container_repository;
	}

	/**
	 * Hook the main Carbon Fields initialization functionality.
	 */
	public function boot() {
		include_once( dirname( dirname( __DIR__ ) ) . '/config.php' );
		include_once( \Carbon_Fields\DIR . '/core/functions.php' );
		
		add_action( 'after_setup_theme', array( $this, 'load_textdomain' ), 9999 );
		add_action( 'init', array( $this, 'trigger_fields_register' ), 0 );
		add_action( 'carbon_after_register_fields', array( $this, 'initialize_containers' ) );
		add_action( 'crb_field_activated', array( $this, 'add_templates' ) );
		add_action( 'crb_container_activated', array( $this, 'add_templates' ) );
		add_action( 'admin_footer', array( $this, 'enqueue_scripts' ), 0 );
		add_action( 'admin_print_footer_scripts', array( $this, 'print_json_data_script' ), 9 );

		# Initialize templater
		$this->templater->boot();

		# Initialize sidebar manager
		$this->sidebar_manager->boot();

		if ( is_admin() ) {
			# Initialize plugin update warning
			$this->plugin_update_warning->boot();
		}
	}

	/**
	 * Load the plugin textdomain.
	 */
	public function load_textdomain() {
		$dir = \Carbon_Fields\DIR . '/languages/';
		$domain = \Carbon_Fields\TEXT_DOMAIN;
		$locale = get_locale();
		$path = $dir . $domain . '-' . $locale . '.mo';
		load_textdomain( $domain, $path );
	}

	/**
	 * Register containers and fields.
	 */
	public function trigger_fields_register() {
		try {
			do_action( 'carbon_register_fields' );
			do_action( 'carbon_after_register_fields' );
		} catch ( Incorrect_Syntax_Exception $e ) {
			$callback = '';
			foreach ( $e->getTrace() as $trace ) {
				$callback .= '<br/>' . ( isset( $trace['file'] ) ? $trace['file'] . ':' . $trace['line'] : $trace['function'] . '()' );
			}
			wp_die( '<h3>' . $e->getMessage() . '</h3><small>' . $callback . '</small>' );
		}
	}

	/**
	 * Initialize containers.
	 */
	public function initialize_containers() {
		$this->container_repository->initialize_containers();
	}

	/**
	 * Adds the field/container template(s) to the templates stack.
	 *
	 * @param object $object field or container object
	 **/
	public function add_templates( $object ) {
		$templates = $object->get_templates();

		if ( ! $templates ) {
			return false;
		}

		foreach ( $templates as $name => $callback ) {
			ob_start();

			call_user_func( $callback );

			$html = ob_get_clean();

			// Add the template to the stack
			$this->templater->add_template( $name, $html );
		}
	}

	/**
	 * Initialize main scripts
	 */
	public function enqueue_scripts() {
		wp_enqueue_script( 'carbon-ext', \Carbon_Fields\URL . '/assets/js/ext.js', array( 'jquery' ), \Carbon_Fields\VERSION );
		wp_enqueue_script( 'carbon-app', \Carbon_Fields\URL . '/assets/js/app.js', array( 'jquery', 'backbone', 'underscore', 'jquery-touch-punch', 'jquery-ui-sortable', 'carbon-ext' ), \Carbon_Fields\VERSION );
	}

	/**
	 * Retrieve containers and sidebars for use in the JS.
	 *
	 * @return array $carbon_data
	 */
	public function get_json_data() {
		global $wp_registered_sidebars;

		$carbon_data = array(
			'containers' => array(),
			'sidebars' => array(),
		);

		$containers = $this->container_repository->get_active_containers();

		foreach ( $containers as $container ) {
			$container_data = $container->to_json( true );

			$carbon_data['containers'][] = $container_data;
		}

		foreach ( $wp_registered_sidebars as $sidebar ) {
			// Check if we have inactive sidebars
			if ( isset( $sidebar['class'] ) && strpos( $sidebar['class'], 'inactive-sidebar' ) !== false ) {
				continue;
			}

			$carbon_data['sidebars'][] = array(
				'name' => $sidebar['name'],
				'id'   => $sidebar['id'],
			);
		}

		return $carbon_data;
	}

	/**
	 * Print the carbon JSON data script.
	 */
	public function print_json_data_script() {
		?>
<script type="text/javascript">
<!--//--><![CDATA[//><!--
var carbon_json = <?php echo wp_json_encode( $this->get_json_data() ); ?>;
//--><!]]>
</script>
		<?php
	}
}