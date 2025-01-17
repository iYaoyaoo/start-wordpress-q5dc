<?php
/**
 * GeChiUI Customize Widgets classes
 *
 * @package GeChiUI
 * @subpackage Customize
 *
 */

/**
 * Customize Widgets class.
 *
 * Implements widget management in the Customizer.
 *
 *
 *
 * @see GC_Customize_Manager
 */
final class GC_Customize_Widgets {

	/**
	 * GC_Customize_Manager instance.
	 *
	 * @var GC_Customize_Manager
	 */
	public $manager;

	/**
	 * All id_bases for widgets defined in core.
	 *
	 * @var array
	 */
	protected $core_widget_id_bases = array(
		'archives',
		'calendar',
		'categories',
		'custom_html',
		'links',
		'media_audio',
		'media_image',
		'media_video',
		'meta',
		'nav_menu',
		'pages',
		'recent-comments',
		'recent-posts',
		'rss',
		'search',
		'tag_cloud',
		'text',
	);

	/**
	 * @var array
	 */
	protected $rendered_sidebars = array();

	/**
	 * @var array
	 */
	protected $rendered_widgets = array();

	/**
	 * @var array
	 */
	protected $old_sidebars_widgets = array();

	/**
	 * Mapping of widget ID base to whether it supports selective refresh.
	 *
	 * @var array
	 */
	protected $selective_refreshable_widgets;

	/**
	 * Mapping of setting type to setting ID pattern.
	 *
	 * @var array
	 */
	protected $setting_id_patterns = array(
		'widget_instance' => '/^widget_(?P<id_base>.+?)(?:\[(?P<widget_number>\d+)\])?$/',
		'sidebar_widgets' => '/^sidebars_widgets\[(?P<sidebar_id>.+?)\]$/',
	);

	/**
	 * Initial loader.
	 *
	 *
	 * @param GC_Customize_Manager $manager Customizer bootstrap instance.
	 */
	public function __construct( $manager ) {
		$this->manager = $manager;

		// See https://github.com/xgc/gc-customize-snapshots/blob/962586659688a5b1fd9ae93618b7ce2d4e7a421c/php/class-customize-snapshot-manager.php#L420-L449
		add_filter( 'customize_dynamic_setting_args', array( $this, 'filter_customize_dynamic_setting_args' ), 10, 2 );
		add_action( 'widgets_init', array( $this, 'register_settings' ), 95 );
		add_action( 'customize_register', array( $this, 'schedule_customize_register' ), 1 );

		// Skip remaining hooks when the user can't manage widgets anyway.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		add_action( 'gc_loaded', array( $this, 'override_sidebars_widgets_for_theme_switch' ) );
		add_action( 'customize_controls_init', array( $this, 'customize_controls_init' ) );
		add_action( 'customize_controls_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'customize_controls_print_styles', array( $this, 'print_styles' ) );
		add_action( 'customize_controls_print_scripts', array( $this, 'print_scripts' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'print_footer_scripts' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'output_widget_control_templates' ) );
		add_action( 'customize_preview_init', array( $this, 'customize_preview_init' ) );
		add_filter( 'customize_refresh_nonces', array( $this, 'refresh_nonces' ) );
		add_filter( 'should_load_block_editor_scripts_and_styles', array( $this, 'should_load_block_editor_scripts_and_styles' ) );

		add_action( 'dynamic_sidebar', array( $this, 'tally_rendered_widgets' ) );
		add_filter( 'is_active_sidebar', array( $this, 'tally_sidebars_via_is_active_sidebar_calls' ), 10, 2 );
		add_filter( 'dynamic_sidebar_has_widgets', array( $this, 'tally_sidebars_via_dynamic_sidebar_calls' ), 10, 2 );

		// Selective Refresh.
		add_filter( 'customize_dynamic_partial_args', array( $this, 'customize_dynamic_partial_args' ), 10, 2 );
		add_action( 'customize_preview_init', array( $this, 'selective_refresh_init' ) );
	}

	/**
	 * List whether each registered widget can be use selective refresh.
	 *
	 * If the theme does not support the customize-selective-refresh-widgets feature,
	 * then this will always return an empty array.
	 *
	 *
	 * @global GC_Widget_Factory $gc_widget_factory
	 *
	 * @return array Mapping of id_base to support. If theme doesn't support
	 *               selective refresh, an empty array is returned.
	 */
	public function get_selective_refreshable_widgets() {
		global $gc_widget_factory;
		if ( ! current_theme_supports( 'customize-selective-refresh-widgets' ) ) {
			return array();
		}
		if ( ! isset( $this->selective_refreshable_widgets ) ) {
			$this->selective_refreshable_widgets = array();
			foreach ( $gc_widget_factory->widgets as $gc_widget ) {
				$this->selective_refreshable_widgets[ $gc_widget->id_base ] = ! empty( $gc_widget->widget_options['customize_selective_refresh'] );
			}
		}
		return $this->selective_refreshable_widgets;
	}

	/**
	 * Determines if a widget supports selective refresh.
	 *
	 *
	 * @param string $id_base Widget ID Base.
	 * @return bool Whether the widget can be selective refreshed.
	 */
	public function is_widget_selective_refreshable( $id_base ) {
		$selective_refreshable_widgets = $this->get_selective_refreshable_widgets();
		return ! empty( $selective_refreshable_widgets[ $id_base ] );
	}

	/**
	 * Retrieves the widget setting type given a setting ID.
	 *
	 *
	 * @param string $setting_id Setting ID.
	 * @return string|void Setting type.
	 */
	protected function get_setting_type( $setting_id ) {
		static $cache = array();
		if ( isset( $cache[ $setting_id ] ) ) {
			return $cache[ $setting_id ];
		}
		foreach ( $this->setting_id_patterns as $type => $pattern ) {
			if ( preg_match( $pattern, $setting_id ) ) {
				$cache[ $setting_id ] = $type;
				return $type;
			}
		}
	}

	/**
	 * Inspects the incoming customized data for any widget settings, and dynamically adds
	 * them up-front so widgets will be initialized properly.
	 *
	 */
	public function register_settings() {
		$widget_setting_ids   = array();
		$incoming_setting_ids = array_keys( $this->manager->unsanitized_post_values() );
		foreach ( $incoming_setting_ids as $setting_id ) {
			if ( ! is_null( $this->get_setting_type( $setting_id ) ) ) {
				$widget_setting_ids[] = $setting_id;
			}
		}
		if ( $this->manager->doing_ajax( 'update-widget' ) && isset( $_REQUEST['widget-id'] ) ) {
			$widget_setting_ids[] = $this->get_setting_id( gc_unslash( $_REQUEST['widget-id'] ) );
		}

		$settings = $this->manager->add_dynamic_settings( array_unique( $widget_setting_ids ) );

		if ( $this->manager->settings_previewed() ) {
			foreach ( $settings as $setting ) {
				$setting->preview();
			}
		}
	}

	/**
	 * Determines the arguments for a dynamically-created setting.
	 *
	 *
	 * @param false|array $args       The arguments to the GC_Customize_Setting constructor.
	 * @param string      $setting_id ID for dynamic setting, usually coming from `$_POST['customized']`.
	 * @return array|false Setting arguments, false otherwise.
	 */
	public function filter_customize_dynamic_setting_args( $args, $setting_id ) {
		if ( $this->get_setting_type( $setting_id ) ) {
			$args = $this->get_setting_args( $setting_id );
		}
		return $args;
	}

	/**
	 * Retrieves an unslashed post value or return a default.
	 *
	 *
	 * @param string $name    Post value.
	 * @param mixed  $default Default post value.
	 * @return mixed Unslashed post value or default value.
	 */
	protected function get_post_value( $name, $default = null ) {
		if ( ! isset( $_POST[ $name ] ) ) {
			return $default;
		}

		return gc_unslash( $_POST[ $name ] );
	}

	/**
	 * Override sidebars_widgets for theme switch.
	 *
	 * When switching a theme via the Customizer, supply any previously-configured
	 * sidebars_widgets from the target theme as the initial sidebars_widgets
	 * setting. Also store the old theme's existing settings so that they can
	 * be passed along for storing in the sidebars_widgets theme_mod when the
	 * theme gets switched.
	 *
	 *
	 * @global array $sidebars_widgets
	 * @global array $_gc_sidebars_widgets
	 */
	public function override_sidebars_widgets_for_theme_switch() {
		global $sidebars_widgets;

		if ( $this->manager->doing_ajax() || $this->manager->is_theme_active() ) {
			return;
		}

		$this->old_sidebars_widgets = gc_get_sidebars_widgets();
		add_filter( 'customize_value_old_sidebars_widgets_data', array( $this, 'filter_customize_value_old_sidebars_widgets_data' ) );
		$this->manager->set_post_value( 'old_sidebars_widgets_data', $this->old_sidebars_widgets ); // Override any value cached in changeset.

		// retrieve_widgets() looks at the global $sidebars_widgets.
		$sidebars_widgets = $this->old_sidebars_widgets;
		$sidebars_widgets = retrieve_widgets( 'customize' );
		add_filter( 'option_sidebars_widgets', array( $this, 'filter_option_sidebars_widgets_for_theme_switch' ), 1 );
		// Reset global cache var used by gc_get_sidebars_widgets().
		unset( $GLOBALS['_gc_sidebars_widgets'] );
	}

	/**
	 * Filters old_sidebars_widgets_data Customizer setting.
	 *
	 * When switching themes, filter the Customizer setting old_sidebars_widgets_data
	 * to supply initial $sidebars_widgets before they were overridden by retrieve_widgets().
	 * The value for old_sidebars_widgets_data gets set in the old theme's sidebars_widgets
	 * theme_mod.
	 *
	 *
	 * @see GC_Customize_Widgets::handle_theme_switch()
	 *
	 * @param array $old_sidebars_widgets
	 * @return array
	 */
	public function filter_customize_value_old_sidebars_widgets_data( $old_sidebars_widgets ) {
		return $this->old_sidebars_widgets;
	}

	/**
	 * Filters sidebars_widgets option for theme switch.
	 *
	 * When switching themes, the retrieve_widgets() function is run when the Customizer initializes,
	 * and then the new sidebars_widgets here get supplied as the default value for the sidebars_widgets
	 * option.
	 *
	 *
	 * @see GC_Customize_Widgets::handle_theme_switch()
	 * @global array $sidebars_widgets
	 *
	 * @param array $sidebars_widgets
	 * @return array
	 */
	public function filter_option_sidebars_widgets_for_theme_switch( $sidebars_widgets ) {
		$sidebars_widgets                  = $GLOBALS['sidebars_widgets'];
		$sidebars_widgets['array_version'] = 3;
		return $sidebars_widgets;
	}

	/**
	 * Ensures all widgets get loaded into the Customizer.
	 *
	 * Note: these actions are also fired in gc_ajax_update_widget().
	 *
	 */
	public function customize_controls_init() {
		/** This action is documented in gc-admin/includes/ajax-actions.php */
		do_action( 'load-widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores

		/** This action is documented in gc-admin/includes/ajax-actions.php */
		do_action( 'widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores

		/** This action is documented in gc-admin/widgets.php */
		do_action( 'sidebar_admin_setup' );
	}

	/**
	 * Ensures widgets are available for all types of previews.
	 *
	 * When in preview, hook to {@see 'customize_register'} for settings after GeChiUI is loaded
	 * so that all filters have been initialized (e.g. Widget Visibility).
	 *
	 */
	public function schedule_customize_register() {
		if ( is_admin() ) {
			$this->customize_register();
		} else {
			add_action( 'gc', array( $this, 'customize_register' ) );
		}
	}

	/**
	 * Registers Customizer settings and controls for all sidebars and widgets.
	 *
	 *
	 * @global array $gc_registered_widgets
	 * @global array $gc_registered_widget_controls
	 * @global array $gc_registered_sidebars
	 */
	public function customize_register() {
		global $gc_registered_widgets, $gc_registered_widget_controls, $gc_registered_sidebars;

		$use_widgets_block_editor = gc_use_widgets_block_editor();

		add_filter( 'sidebars_widgets', array( $this, 'preview_sidebars_widgets' ), 1 );

		$sidebars_widgets = array_merge(
			array( 'gc_inactive_widgets' => array() ),
			array_fill_keys( array_keys( $gc_registered_sidebars ), array() ),
			gc_get_sidebars_widgets()
		);

		$new_setting_ids = array();

		/*
		 * Register a setting for all widgets, including those which are active,
		 * inactive, and orphaned since a widget may get suppressed from a sidebar
		 * via a plugin (like Widget Visibility).
		 */
		foreach ( array_keys( $gc_registered_widgets ) as $widget_id ) {
			$setting_id   = $this->get_setting_id( $widget_id );
			$setting_args = $this->get_setting_args( $setting_id );
			if ( ! $this->manager->get_setting( $setting_id ) ) {
				$this->manager->add_setting( $setting_id, $setting_args );
			}
			$new_setting_ids[] = $setting_id;
		}

		/*
		 * Add a setting which will be supplied for the theme's sidebars_widgets
		 * theme_mod when the theme is switched.
		 */
		if ( ! $this->manager->is_theme_active() ) {
			$setting_id   = 'old_sidebars_widgets_data';
			$setting_args = $this->get_setting_args(
				$setting_id,
				array(
					'type'  => 'global_variable',
					'dirty' => true,
				)
			);
			$this->manager->add_setting( $setting_id, $setting_args );
		}

		$this->manager->add_panel(
			'widgets',
			array(
				'type'                     => 'widgets',
				'title'                    => __( '小工具' ),
				'description'              => __( '小工具是与内容独立的区域，可被放在您的主题专为小工具提供的区域中（通常被称为边栏）。' ),
				'priority'                 => 110,
				'active_callback'          => array( $this, 'is_panel_active' ),
				'auto_expand_sole_section' => true,
				'theme_supports'           => 'widgets',
			)
		);

		foreach ( $sidebars_widgets as $sidebar_id => $sidebar_widget_ids ) {
			if ( empty( $sidebar_widget_ids ) ) {
				$sidebar_widget_ids = array();
			}

			$is_registered_sidebar = is_registered_sidebar( $sidebar_id );
			$is_inactive_widgets   = ( 'gc_inactive_widgets' === $sidebar_id );
			$is_active_sidebar     = ( $is_registered_sidebar && ! $is_inactive_widgets );

			// Add setting for managing the sidebar's widgets.
			if ( $is_registered_sidebar || $is_inactive_widgets ) {
				$setting_id   = sprintf( 'sidebars_widgets[%s]', $sidebar_id );
				$setting_args = $this->get_setting_args( $setting_id );
				if ( ! $this->manager->get_setting( $setting_id ) ) {
					if ( ! $this->manager->is_theme_active() ) {
						$setting_args['dirty'] = true;
					}
					$this->manager->add_setting( $setting_id, $setting_args );
				}
				$new_setting_ids[] = $setting_id;

				// Add section to contain controls.
				$section_id = sprintf( 'sidebar-widgets-%s', $sidebar_id );
				if ( $is_active_sidebar ) {

					$section_args = array(
						'title'      => $gc_registered_sidebars[ $sidebar_id ]['name'],
						'priority'   => array_search( $sidebar_id, array_keys( $gc_registered_sidebars ), true ),
						'panel'      => 'widgets',
						'sidebar_id' => $sidebar_id,
					);

					if ( $use_widgets_block_editor ) {
						$section_args['description'] = '';
					} else {
						$section_args['description'] = $gc_registered_sidebars[ $sidebar_id ]['description'];
					}

					/**
					 * Filters Customizer widget section arguments for a given sidebar.
					 *
				
					 *
					 * @param array      $section_args Array of Customizer widget section arguments.
					 * @param string     $section_id   Customizer section ID.
					 * @param int|string $sidebar_id   Sidebar ID.
					 */
					$section_args = apply_filters( 'customizer_widgets_section_args', $section_args, $section_id, $sidebar_id );

					$section = new GC_Customize_Sidebar_Section( $this->manager, $section_id, $section_args );
					$this->manager->add_section( $section );

					if ( $use_widgets_block_editor ) {
						$control = new GC_Sidebar_Block_Editor_Control(
							$this->manager,
							$setting_id,
							array(
								'section'     => $section_id,
								'sidebar_id'  => $sidebar_id,
								'label'       => $section_args['title'],
								'description' => $section_args['description'],
							)
						);
					} else {
						$control = new GC_Widget_Area_Customize_Control(
							$this->manager,
							$setting_id,
							array(
								'section'    => $section_id,
								'sidebar_id' => $sidebar_id,
								'priority'   => count( $sidebar_widget_ids ), // place '添加小工具' and 'Reorder' buttons at end.
							)
						);
					}

					$this->manager->add_control( $control );

					$new_setting_ids[] = $setting_id;
				}
			}

			if ( ! $use_widgets_block_editor ) {
				// Add a control for each active widget (located in a sidebar).
				foreach ( $sidebar_widget_ids as $i => $widget_id ) {

					// Skip widgets that may have gone away due to a plugin being deactivated.
					if ( ! $is_active_sidebar || ! isset( $gc_registered_widgets[ $widget_id ] ) ) {
						continue;
					}

					$registered_widget = $gc_registered_widgets[ $widget_id ];
					$setting_id        = $this->get_setting_id( $widget_id );
					$id_base           = $gc_registered_widget_controls[ $widget_id ]['id_base'];

					$control = new GC_Widget_Form_Customize_Control(
						$this->manager,
						$setting_id,
						array(
							'label'          => $registered_widget['name'],
							'section'        => $section_id,
							'sidebar_id'     => $sidebar_id,
							'widget_id'      => $widget_id,
							'widget_id_base' => $id_base,
							'priority'       => $i,
							'width'          => $gc_registered_widget_controls[ $widget_id ]['width'],
							'height'         => $gc_registered_widget_controls[ $widget_id ]['height'],
							'is_wide'        => $this->is_wide_widget( $widget_id ),
						)
					);
					$this->manager->add_control( $control );
				}
			}
		}

		if ( $this->manager->settings_previewed() ) {
			foreach ( $new_setting_ids as $new_setting_id ) {
				$this->manager->get_setting( $new_setting_id )->preview();
			}
		}
	}

	/**
	 * Determines whether the widgets panel is active, based on whether there are sidebars registered.
	 *
	 *
	 * @see GC_Customize_Panel::$active_callback
	 *
	 * @global array $gc_registered_sidebars
	 * @return bool Active.
	 */
	public function is_panel_active() {
		global $gc_registered_sidebars;
		return ! empty( $gc_registered_sidebars );
	}

	/**
	 * Converts a widget_id into its corresponding Customizer setting ID (option name).
	 *
	 *
	 * @param string $widget_id Widget ID.
	 * @return string Maybe-parsed widget ID.
	 */
	public function get_setting_id( $widget_id ) {
		$parsed_widget_id = $this->parse_widget_id( $widget_id );
		$setting_id       = sprintf( 'widget_%s', $parsed_widget_id['id_base'] );

		if ( ! is_null( $parsed_widget_id['number'] ) ) {
			$setting_id .= sprintf( '[%d]', $parsed_widget_id['number'] );
		}
		return $setting_id;
	}

	/**
	 * Determines whether the widget is considered "wide".
	 *
	 * Core widgets which may have controls wider than 250, but can still be shown
	 * in the narrow Customizer panel. The RSS and Text widgets in Core, for example,
	 * have widths of 400 and yet they still render fine in the Customizer panel.
	 *
	 * This method will return all Core widgets as being not wide, but this can be
	 * overridden with the {@see 'is_wide_widget_in_customizer'} filter.
	 *
	 *
	 * @global array $gc_registered_widget_controls
	 *
	 * @param string $widget_id Widget ID.
	 * @return bool Whether or not the widget is a "wide" widget.
	 */
	public function is_wide_widget( $widget_id ) {
		global $gc_registered_widget_controls;

		$parsed_widget_id = $this->parse_widget_id( $widget_id );
		$width            = $gc_registered_widget_controls[ $widget_id ]['width'];
		$is_core          = in_array( $parsed_widget_id['id_base'], $this->core_widget_id_bases, true );
		$is_wide          = ( $width > 250 && ! $is_core );

		/**
		 * Filters whether the given widget is considered "wide".
		 *
		 *
		 * @param bool   $is_wide   Whether the widget is wide, Default false.
		 * @param string $widget_id Widget ID.
		 */
		return apply_filters( 'is_wide_widget_in_customizer', $is_wide, $widget_id );
	}

	/**
	 * Converts a widget ID into its id_base and number components.
	 *
	 *
	 * @param string $widget_id Widget ID.
	 * @return array Array containing a widget's id_base and number components.
	 */
	public function parse_widget_id( $widget_id ) {
		$parsed = array(
			'number'  => null,
			'id_base' => null,
		);

		if ( preg_match( '/^(.+)-(\d+)$/', $widget_id, $matches ) ) {
			$parsed['id_base'] = $matches[1];
			$parsed['number']  = (int) $matches[2];
		} else {
			// Likely an old single widget.
			$parsed['id_base'] = $widget_id;
		}
		return $parsed;
	}

	/**
	 * Converts a widget setting ID (option path) to its id_base and number components.
	 *
	 *
	 * @param string $setting_id Widget setting ID.
	 * @return array|GC_Error Array containing a widget's id_base and number components,
	 *                        or a GC_Error object.
	 */
	public function parse_widget_setting_id( $setting_id ) {
		if ( ! preg_match( '/^(widget_(.+?))(?:\[(\d+)\])?$/', $setting_id, $matches ) ) {
			return new GC_Error( 'widget_setting_invalid_id' );
		}

		$id_base = $matches[2];
		$number  = isset( $matches[3] ) ? (int) $matches[3] : null;

		return compact( 'id_base', 'number' );
	}

	/**
	 * Calls admin_print_styles-widgets.php and admin_print_styles hooks to
	 * allow custom styles from plugins.
	 *
	 */
	public function print_styles() {
		/** This action is documented in gc-admin/admin-header.php */
		do_action( 'admin_print_styles-widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores

		/** This action is documented in gc-admin/admin-header.php */
		do_action( 'admin_print_styles' );
	}

	/**
	 * Calls admin_print_scripts-widgets.php and admin_print_scripts hooks to
	 * allow custom scripts from plugins.
	 *
	 */
	public function print_scripts() {
		/** This action is documented in gc-admin/admin-header.php */
		do_action( 'admin_print_scripts-widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores

		/** This action is documented in gc-admin/admin-header.php */
		do_action( 'admin_print_scripts' );
	}

	/**
	 * Enqueues scripts and styles for Customizer panel and export data to JavaScript.
	 *
	 *
	 * @global GC_Scripts $gc_scripts
	 * @global array $gc_registered_sidebars
	 * @global array $gc_registered_widgets
	 */
	public function enqueue_scripts() {
		global $gc_scripts, $gc_registered_sidebars, $gc_registered_widgets;

		gc_enqueue_style( 'customize-widgets' );
		gc_enqueue_script( 'customize-widgets' );

		/** This action is documented in gc-admin/admin-header.php */
		do_action( 'admin_enqueue_scripts', 'widgets.php' );

		/*
		 * Export available widgets with control_tpl removed from model
		 * since plugins need templates to be in the DOM.
		 */
		$available_widgets = array();

		foreach ( $this->get_available_widgets() as $available_widget ) {
			unset( $available_widget['control_tpl'] );
			$available_widgets[] = $available_widget;
		}

		$widget_reorder_nav_tpl = sprintf(
			'<div class="widget-reorder-nav"><span class="move-widget" tabindex="0">%1$s</span><span class="move-widget-down" tabindex="0">%2$s</span><span class="move-widget-up" tabindex="0">%3$s</span></div>',
			__( '移动至另一区域&hellip;' ),
			__( '下移' ),
			__( '上移' )
		);

		$move_widget_area_tpl = str_replace(
			array( '{description}', '{btn}' ),
			array(
				__( '选择将小工具移动到的区域：' ),
				_x( '移动至', 'Move widget' ),
			),
			'<div class="move-widget-area">
				<p class="description">{description}</p>
				<ul class="widget-area-select">
					<% _.each( sidebars, function ( sidebar ){ %>
						<li class="" data-id="<%- sidebar.id %>" title="<%- sidebar.description %>" tabindex="0"><%- sidebar.name %></li>
					<% }); %>
				</ul>
				<div class="move-widget-actions">
					<button class="move-widget-btn button" type="button">{btn}</button>
				</div>
			</div>'
		);

		/*
		 * Gather all strings in PHP that may be needed by JS on the client.
		 * Once JS i18n is implemented (in #20491), this can be removed.
		 */
		$some_non_rendered_areas_messages    = array();
		$some_non_rendered_areas_messages[1] = html_entity_decode(
			__( '您的主题还有一个小工具区域不在这个页面上显示。' ),
			ENT_QUOTES,
			get_bloginfo( 'charset' )
		);
		$registered_sidebar_count            = count( $gc_registered_sidebars );
		for ( $non_rendered_count = 2; $non_rendered_count < $registered_sidebar_count; $non_rendered_count++ ) {
			$some_non_rendered_areas_messages[ $non_rendered_count ] = html_entity_decode(
				sprintf(
					/* translators: %s: The number of other widget areas registered but not rendered. */
					_n(
						'您的主题还有%s个小工具区域不在这个页面上显示。',
						'您的主题还有%s个小工具区域不在这个页面上显示。',
						$non_rendered_count
					),
					number_format_i18n( $non_rendered_count )
				),
				ENT_QUOTES,
				get_bloginfo( 'charset' )
			);
		}

		if ( 1 === $registered_sidebar_count ) {
			$no_areas_shown_message = html_entity_decode(
				sprintf(
					__( '您的主题有一个小工具区域，但这个页面不显示小工具。' )
				),
				ENT_QUOTES,
				get_bloginfo( 'charset' )
			);
		} else {
			$no_areas_shown_message = html_entity_decode(
				sprintf(
					/* translators: %s: The total number of widget areas registered. */
					_n(
						'您的主题有%s个小工具区域，但这个页面不显示任何小工具。',
						'您的主题有%s个小工具区域，但这个页面不显示任何小工具。',
						$registered_sidebar_count
					),
					number_format_i18n( $registered_sidebar_count )
				),
				ENT_QUOTES,
				get_bloginfo( 'charset' )
			);
		}

		$settings = array(
			'registeredSidebars'          => array_values( $gc_registered_sidebars ),
			'registeredWidgets'           => $gc_registered_widgets,
			'availableWidgets'            => $available_widgets, // @todo Merge this with registered_widgets.
			'l10n'                        => array(
				'saveBtnLabel'     => __( '应用' ),
				'saveBtnTooltip'   => __( '在发布前保存并预览修改。' ),
				'removeBtnLabel'   => __( '移除' ),
				'removeBtnTooltip' => __( '保留小工具设置，并将其移动至未启用的小工具' ),
				'error'            => __( '发生了错误，请刷新此页面并重试。' ),
				'widgetMovedUp'    => __( '小工具已上移' ),
				'widgetMovedDown'  => __( '小工具已下移' ),
				'navigatePreview'  => __( '使用定制器配置小工具时，您可以随意到其他页面去；定制器在所有页面都能用。' ),
				'someAreasShown'   => $some_non_rendered_areas_messages,
				'noAreasShown'     => $no_areas_shown_message,
				'reorderModeOn'    => __( '重排模式已启用' ),
				'reorderModeOff'   => __( '重排模式已关闭' ),
				'reorderLabelOn'   => esc_attr__( '重排小工具' ),
				/* translators: %d: The number of widgets found. */
				'widgetsFound'     => __( '找到的小工具数目：%d' ),
				'noWidgetsFound'   => __( '未找到小工具。' ),
			),
			'tpl'                         => array(
				'widgetReorderNav' => $widget_reorder_nav_tpl,
				'moveWidgetArea'   => $move_widget_area_tpl,
			),
			'selectiveRefreshableWidgets' => $this->get_selective_refreshable_widgets(),
		);

		foreach ( $settings['registeredWidgets'] as &$registered_widget ) {
			unset( $registered_widget['callback'] ); // May not be JSON-serializeable.
		}

		$gc_scripts->add_data(
			'customize-widgets',
			'data',
			sprintf( 'var _gcCustomizeWidgetsSettings = %s;', gc_json_encode( $settings ) )
		);

		/*
		 * TODO: Update 'gc-customize-widgets' to not rely so much on things in
		 * 'customize-widgets'. This will let us skip most of the above and not
		 * enqueue 'customize-widgets' which saves bytes.
		 */

		if ( gc_use_widgets_block_editor() ) {
			$block_editor_context = new GC_Block_Editor_Context();

			$editor_settings = get_block_editor_settings(
				get_legacy_widget_block_editor_settings(),
				$block_editor_context
			);

			gc_add_inline_script(
				'gc-customize-widgets',
				sprintf(
					'gc.domReady( function() {
					   gc.customizeWidgets.initialize( "widgets-customizer", %s );
					} );',
					gc_json_encode( $editor_settings )
				)
			);

			// Preload server-registered block schemas.
			gc_add_inline_script(
				'gc-blocks',
				'gc.blocks.unstable__bootstrapServerSideBlockDefinitions(' . gc_json_encode( get_block_editor_server_block_settings() ) . ');'
			);

			gc_add_inline_script(
				'gc-blocks',
				sprintf( 'gc.blocks.setCategories( %s );', gc_json_encode( get_block_categories( $block_editor_context ) ) ),
				'after'
			);

			gc_enqueue_script( 'gc-customize-widgets' );
			gc_enqueue_style( 'gc-customize-widgets' );

			/** This action is documented in edit-form-blocks.php */
			do_action( 'enqueue_block_editor_assets' );
		}
	}

	/**
	 * Renders the widget form control templates into the DOM.
	 *
	 */
	public function output_widget_control_templates() {
		?>
		<div id="widgets-left"><!-- compatibility with JS which looks for widget templates here -->
		<div id="available-widgets">
			<div class="customize-section-title">
				<button class="customize-section-back" tabindex="-1">
					<span class="screen-reader-text"><?php _e( '返回' ); ?></span>
				</button>
				<h3>
					<span class="customize-action">
					<?php
						/* translators: &#9656; is the unicode right-pointing triangle. %s: Section title in the Customizer. */
						printf( __( '自定义 &#9656; %s' ), esc_html( $this->manager->get_panel( 'widgets' )->title ) );
					?>
					</span>
					<?php _e( '添加小工具' ); ?>
				</h3>
			</div>
			<div id="available-widgets-filter">
				<label class="screen-reader-text" for="widgets-search"><?php _e( '搜索小工具' ); ?></label>
				<input type="text" id="widgets-search" placeholder="<?php esc_attr_e( '搜索小工具&hellip;' ); ?>" aria-describedby="widgets-search-desc" />
				<div class="search-icon" aria-hidden="true"></div>
				<button type="button" class="clear-results"><span class="screen-reader-text"><?php _e( '清空结果' ); ?></span></button>
				<p class="screen-reader-text" id="widgets-search-desc"><?php _e( '搜索结果会随着您的输入而不断更新。' ); ?></p>
			</div>
			<div id="available-widgets-list">
			<?php foreach ( $this->get_available_widgets() as $available_widget ) : ?>
				<div id="widget-tpl-<?php echo esc_attr( $available_widget['id'] ); ?>" data-widget-id="<?php echo esc_attr( $available_widget['id'] ); ?>" class="widget-tpl <?php echo esc_attr( $available_widget['id'] ); ?>" tabindex="0">
					<?php echo $available_widget['control_tpl']; ?>
				</div>
			<?php endforeach; ?>
			<p class="no-widgets-found-message"><?php _e( '未找到小工具。' ); ?></p>
			</div><!-- #available-widgets-list -->
		</div><!-- #available-widgets -->
		</div><!-- #widgets-left -->
		<?php
	}

	/**
	 * Calls admin_print_footer_scripts and admin_print_scripts hooks to
	 * allow custom scripts from plugins.
	 *
	 */
	public function print_footer_scripts() {
		/** This action is documented in gc-admin/admin-footer.php */
		do_action( 'admin_print_footer_scripts-widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores

		/** This action is documented in gc-admin/admin-footer.php */
		do_action( 'admin_print_footer_scripts' );

		/** This action is documented in gc-admin/admin-footer.php */
		do_action( 'admin_footer-widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores
	}

	/**
	 * Retrieves common arguments to supply when constructing a Customizer setting.
	 *
	 *
	 * @param string $id        Widget setting ID.
	 * @param array  $overrides Array of setting overrides.
	 * @return array Possibly modified setting arguments.
	 */
	public function get_setting_args( $id, $overrides = array() ) {
		$args = array(
			'type'       => 'option',
			'capability' => 'edit_theme_options',
			'default'    => array(),
		);

		if ( preg_match( $this->setting_id_patterns['sidebar_widgets'], $id, $matches ) ) {
			$args['sanitize_callback']    = array( $this, 'sanitize_sidebar_widgets' );
			$args['sanitize_js_callback'] = array( $this, 'sanitize_sidebar_widgets_js_instance' );
			$args['transport']            = current_theme_supports( 'customize-selective-refresh-widgets' ) ? 'postMessage' : 'refresh';
		} elseif ( preg_match( $this->setting_id_patterns['widget_instance'], $id, $matches ) ) {
			$id_base                      = $matches['id_base'];
			$args['sanitize_callback']    = function( $value ) use ( $id_base ) {
				return $this->sanitize_widget_instance( $value, $id_base );
			};
			$args['sanitize_js_callback'] = function( $value ) use ( $id_base ) {
				return $this->sanitize_widget_js_instance( $value, $id_base );
			};
			$args['transport']            = $this->is_widget_selective_refreshable( $matches['id_base'] ) ? 'postMessage' : 'refresh';
		}

		$args = array_merge( $args, $overrides );

		/**
		 * Filters the common arguments supplied when constructing a Customizer setting.
		 *
		 *
		 * @see GC_Customize_Setting
		 *
		 * @param array  $args Array of Customizer setting arguments.
		 * @param string $id   Widget setting ID.
		 */
		return apply_filters( 'widget_customizer_setting_args', $args, $id );
	}

	/**
	 * Ensures sidebar widget arrays only ever contain widget IDS.
	 *
	 * Used as the 'sanitize_callback' for each $sidebars_widgets setting.
	 *
	 *
	 * @param string[] $widget_ids Array of widget IDs.
	 * @return string[] Array of sanitized widget IDs.
	 */
	public function sanitize_sidebar_widgets( $widget_ids ) {
		$widget_ids           = array_map( 'strval', (array) $widget_ids );
		$sanitized_widget_ids = array();
		foreach ( $widget_ids as $widget_id ) {
			$sanitized_widget_ids[] = preg_replace( '/[^a-z0-9_\-]/', '', $widget_id );
		}
		return $sanitized_widget_ids;
	}

	/**
	 * Builds up an index of all available widgets for use in Backbone models.
	 *
	 *
	 * @global array $gc_registered_widgets
	 * @global array $gc_registered_widget_controls
	 *
	 * @see gc_list_widgets()
	 *
	 * @return array List of available widgets.
	 */
	public function get_available_widgets() {
		static $available_widgets = array();
		if ( ! empty( $available_widgets ) ) {
			return $available_widgets;
		}

		global $gc_registered_widgets, $gc_registered_widget_controls;
		require_once ABSPATH . 'gc-admin/includes/widgets.php'; // For next_widget_id_number().

		$sort = $gc_registered_widgets;
		usort( $sort, array( $this, '_sort_name_callback' ) );
		$done = array();

		foreach ( $sort as $widget ) {
			if ( in_array( $widget['callback'], $done, true ) ) { // We already showed this multi-widget.
				continue;
			}

			$sidebar = is_active_widget( $widget['callback'], $widget['id'], false, false );
			$done[]  = $widget['callback'];

			if ( ! isset( $widget['params'][0] ) ) {
				$widget['params'][0] = array();
			}

			$available_widget = $widget;
			unset( $available_widget['callback'] ); // Not serializable to JSON.

			$args = array(
				'widget_id'   => $widget['id'],
				'widget_name' => $widget['name'],
				'_display'    => 'template',
			);

			$is_disabled     = false;
			$is_multi_widget = ( isset( $gc_registered_widget_controls[ $widget['id'] ]['id_base'] ) && isset( $widget['params'][0]['number'] ) );
			if ( $is_multi_widget ) {
				$id_base            = $gc_registered_widget_controls[ $widget['id'] ]['id_base'];
				$args['_temp_id']   = "$id_base-__i__";
				$args['_multi_num'] = next_widget_id_number( $id_base );
				$args['_add']       = 'multi';
			} else {
				$args['_add'] = 'single';

				if ( $sidebar && 'gc_inactive_widgets' !== $sidebar ) {
					$is_disabled = true;
				}
				$id_base = $widget['id'];
			}

			$list_widget_controls_args = gc_list_widget_controls_dynamic_sidebar(
				array(
					0 => $args,
					1 => $widget['params'][0],
				)
			);
			$control_tpl               = $this->get_widget_control( $list_widget_controls_args );

			// The properties here are mapped to the Backbone Widget model.
			$available_widget = array_merge(
				$available_widget,
				array(
					'temp_id'      => isset( $args['_temp_id'] ) ? $args['_temp_id'] : null,
					'is_multi'     => $is_multi_widget,
					'control_tpl'  => $control_tpl,
					'multi_number' => ( 'multi' === $args['_add'] ) ? $args['_multi_num'] : false,
					'is_disabled'  => $is_disabled,
					'id_base'      => $id_base,
					'transport'    => $this->is_widget_selective_refreshable( $id_base ) ? 'postMessage' : 'refresh',
					'width'        => $gc_registered_widget_controls[ $widget['id'] ]['width'],
					'height'       => $gc_registered_widget_controls[ $widget['id'] ]['height'],
					'is_wide'      => $this->is_wide_widget( $widget['id'] ),
				)
			);

			$available_widgets[] = $available_widget;
		}

		return $available_widgets;
	}

	/**
	 * Naturally orders available widgets by name.
	 *
	 *
	 * @param array $widget_a The first widget to compare.
	 * @param array $widget_b The second widget to compare.
	 * @return int Reorder position for the current widget comparison.
	 */
	protected function _sort_name_callback( $widget_a, $widget_b ) {
		return strnatcasecmp( $widget_a['name'], $widget_b['name'] );
	}

	/**
	 * Retrieves the widget control markup.
	 *
	 *
	 * @param array $args Widget control arguments.
	 * @return string Widget control form HTML markup.
	 */
	public function get_widget_control( $args ) {
		$args[0]['before_form']           = '<div class="form">';
		$args[0]['after_form']            = '</div><!-- .form -->';
		$args[0]['before_widget_content'] = '<div class="widget-content">';
		$args[0]['after_widget_content']  = '</div><!-- .widget-content -->';
		ob_start();
		gc_widget_control( ...$args );
		$control_tpl = ob_get_clean();
		return $control_tpl;
	}

	/**
	 * Retrieves the widget control markup parts.
	 *
	 *
	 * @param array $args Widget control arguments.
	 * @return array {
	 *     @type string $control Markup for widget control wrapping form.
	 *     @type string $content The contents of the widget form itself.
	 * }
	 */
	public function get_widget_control_parts( $args ) {
		$args[0]['before_widget_content'] = '<div class="widget-content">';
		$args[0]['after_widget_content']  = '</div><!-- .widget-content -->';
		$control_markup                   = $this->get_widget_control( $args );

		$content_start_pos = strpos( $control_markup, $args[0]['before_widget_content'] );
		$content_end_pos   = strrpos( $control_markup, $args[0]['after_widget_content'] );

		$control  = substr( $control_markup, 0, $content_start_pos + strlen( $args[0]['before_widget_content'] ) );
		$control .= substr( $control_markup, $content_end_pos );
		$content  = trim(
			substr(
				$control_markup,
				$content_start_pos + strlen( $args[0]['before_widget_content'] ),
				$content_end_pos - $content_start_pos - strlen( $args[0]['before_widget_content'] )
			)
		);

		return compact( 'control', 'content' );
	}

	/**
	 * Adds hooks for the Customizer preview.
	 *
	 */
	public function customize_preview_init() {
		add_action( 'gc_enqueue_scripts', array( $this, 'customize_preview_enqueue' ) );
		add_action( 'gc_print_styles', array( $this, 'print_preview_css' ), 1 );
		add_action( 'gc_footer', array( $this, 'export_preview_data' ), 20 );
	}

	/**
	 * Refreshes the nonce for widget updates.
	 *
	 *
	 * @param array $nonces Array of nonces.
	 * @return array Array of nonces.
	 */
	public function refresh_nonces( $nonces ) {
		$nonces['update-widget'] = gc_create_nonce( 'update-widget' );
		return $nonces;
	}

	/**
	 * Tells the script loader to load the scripts and styles of custom blocks
	 * if the widgets block editor is enabled.
	 *
	 *
	 * @param bool $is_block_editor_screen Current decision about loading block assets.
	 * @return bool Filtered decision about loading block assets.
	 */
	public function should_load_block_editor_scripts_and_styles( $is_block_editor_screen ) {
		if ( gc_use_widgets_block_editor() ) {
			return true;
		}

		return $is_block_editor_screen;
	}

	/**
	 * When previewing, ensures the proper previewing widgets are used.
	 *
	 * Because gc_get_sidebars_widgets() gets called early at {@see 'init' } (via
	 * gc_convert_widget_settings()) and can set global variable `$_gc_sidebars_widgets`
	 * to the value of `get_option( 'sidebars_widgets' )` before the Customizer preview
	 * filter is added, it has to be reset after the filter has been added.
	 *
	 *
	 * @param array $sidebars_widgets List of widgets for the current sidebar.
	 * @return array
	 */
	public function preview_sidebars_widgets( $sidebars_widgets ) {
		$sidebars_widgets = get_option( 'sidebars_widgets', array() );

		unset( $sidebars_widgets['array_version'] );
		return $sidebars_widgets;
	}

	/**
	 * Enqueues scripts for the Customizer preview.
	 *
	 */
	public function customize_preview_enqueue() {
		gc_enqueue_script( 'customize-preview-widgets' );
	}

	/**
	 * Inserts default style for highlighted widget at early point so theme
	 * stylesheet can override.
	 *
	 */
	public function print_preview_css() {
		?>
		<style>
		.widget-customizer-highlighted-widget {
			outline: none;
			-webkit-box-shadow: 0 0 2px rgba(30, 140, 190, 0.8);
			box-shadow: 0 0 2px rgba(30, 140, 190, 0.8);
			position: relative;
			z-index: 1;
		}
		</style>
		<?php
	}

	/**
	 * Communicates the sidebars that appeared on the page at the very end of the page,
	 * and at the very end of the gc_footer,
	 *
	 *
	 * @global array $gc_registered_sidebars
	 * @global array $gc_registered_widgets
	 */
	public function export_preview_data() {
		global $gc_registered_sidebars, $gc_registered_widgets;

		$switched_locale = switch_to_locale( get_user_locale() );

		$l10n = array(
			'widgetTooltip' => __( '要编辑此小工具，按住Shift并点击鼠标。' ),
		);

		if ( $switched_locale ) {
			restore_previous_locale();
		}

		$rendered_sidebars = array_filter( $this->rendered_sidebars );
		$rendered_widgets  = array_filter( $this->rendered_widgets );

		// Prepare Customizer settings to pass to JavaScript.
		$settings = array(
			'renderedSidebars'            => array_fill_keys( array_keys( $rendered_sidebars ), true ),
			'renderedWidgets'             => array_fill_keys( array_keys( $rendered_widgets ), true ),
			'registeredSidebars'          => array_values( $gc_registered_sidebars ),
			'registeredWidgets'           => $gc_registered_widgets,
			'l10n'                        => $l10n,
			'selectiveRefreshableWidgets' => $this->get_selective_refreshable_widgets(),
		);

		foreach ( $settings['registeredWidgets'] as &$registered_widget ) {
			unset( $registered_widget['callback'] ); // May not be JSON-serializeable.
		}

		?>
		<script type="text/javascript">
			var _gcWidgetCustomizerPreviewSettings = <?php echo gc_json_encode( $settings ); ?>;
		</script>
		<?php
	}

	/**
	 * Tracks the widgets that were rendered.
	 *
	 *
	 * @param array $widget Rendered widget to tally.
	 */
	public function tally_rendered_widgets( $widget ) {
		$this->rendered_widgets[ $widget['id'] ] = true;
	}

	/**
	 * Determine if a widget is rendered on the page.
	 *
	 *
	 * @param string $widget_id Widget ID to check.
	 * @return bool Whether the widget is rendered.
	 */
	public function is_widget_rendered( $widget_id ) {
		return ! empty( $this->rendered_widgets[ $widget_id ] );
	}

	/**
	 * Determines if a sidebar is rendered on the page.
	 *
	 *
	 * @param string $sidebar_id Sidebar ID to check.
	 * @return bool Whether the sidebar is rendered.
	 */
	public function is_sidebar_rendered( $sidebar_id ) {
		return ! empty( $this->rendered_sidebars[ $sidebar_id ] );
	}

	/**
	 * Tallies the sidebars rendered via is_active_sidebar().
	 *
	 * Keep track of the times that is_active_sidebar() is called in the template,
	 * and assume that this means that the sidebar would be rendered on the template
	 * if there were widgets populating it.
	 *
	 *
	 * @param bool   $is_active  Whether the sidebar is active.
	 * @param string $sidebar_id Sidebar ID.
	 * @return bool Whether the sidebar is active.
	 */
	public function tally_sidebars_via_is_active_sidebar_calls( $is_active, $sidebar_id ) {
		if ( is_registered_sidebar( $sidebar_id ) ) {
			$this->rendered_sidebars[ $sidebar_id ] = true;
		}

		/*
		 * We may need to force this to true, and also force-true the value
		 * for 'dynamic_sidebar_has_widgets' if we want to ensure that there
		 * is an area to drop widgets into, if the sidebar is empty.
		 */
		return $is_active;
	}

	/**
	 * Tallies the sidebars rendered via dynamic_sidebar().
	 *
	 * Keep track of the times that dynamic_sidebar() is called in the template,
	 * and assume this means the sidebar would be rendered on the template if
	 * there were widgets populating it.
	 *
	 *
	 * @param bool   $has_widgets Whether the current sidebar has widgets.
	 * @param string $sidebar_id  Sidebar ID.
	 * @return bool Whether the current sidebar has widgets.
	 */
	public function tally_sidebars_via_dynamic_sidebar_calls( $has_widgets, $sidebar_id ) {
		if ( is_registered_sidebar( $sidebar_id ) ) {
			$this->rendered_sidebars[ $sidebar_id ] = true;
		}

		/*
		 * We may need to force this to true, and also force-true the value
		 * for 'is_active_sidebar' if we want to ensure there is an area to
		 * drop widgets into, if the sidebar is empty.
		 */
		return $has_widgets;
	}

	/**
	 * Retrieves MAC for a serialized widget instance string.
	 *
	 * Allows values posted back from JS to be rejected if any tampering of the
	 * data has occurred.
	 *
	 *
	 * @param string $serialized_instance Widget instance.
	 * @return string MAC for serialized widget instance.
	 */
	protected function get_instance_hash_key( $serialized_instance ) {
		return gc_hash( $serialized_instance );
	}

	/**
	 * Sanitizes a widget instance.
	 *
	 * Unserialize the JS-instance for storing in the options. It's important that this filter
	 * only get applied to an instance *once*.
	 *
	 *
	 * @global GC_Widget_Factory $gc_widget_factory
	 *
	 * @param array  $value   Widget instance to sanitize.
	 * @param string $id_base Optional. Base of the ID of the widget being sanitized. Default null.
	 * @return array|void Sanitized widget instance.
	 */
	public function sanitize_widget_instance( $value, $id_base = null ) {
		global $gc_widget_factory;

		if ( array() === $value ) {
			return $value;
		}

		if ( isset( $value['raw_instance'] ) && $id_base && gc_use_widgets_block_editor() ) {
			$widget_object = $gc_widget_factory->get_widget_object( $id_base );
			if ( ! empty( $widget_object->widget_options['show_instance_in_rest'] ) ) {
				if ( 'block' === $id_base && ! current_user_can( 'unfiltered_html' ) ) {
					/*
					 * The content of the 'block' widget is not filtered on the fly while editing.
					 * Filter the content here to prevent vulnerabilities.
					 */
					$value['raw_instance']['content'] = gc_kses_post( $value['raw_instance']['content'] );
				}

				return $value['raw_instance'];
			}
		}

		if (
			empty( $value['is_widget_customizer_js_value'] ) ||
			empty( $value['instance_hash_key'] ) ||
			empty( $value['encoded_serialized_instance'] )
		) {
			return;
		}

		$decoded = base64_decode( $value['encoded_serialized_instance'], true );
		if ( false === $decoded ) {
			return;
		}

		if ( ! hash_equals( $this->get_instance_hash_key( $decoded ), $value['instance_hash_key'] ) ) {
			return;
		}

		$instance = unserialize( $decoded );
		if ( false === $instance ) {
			return;
		}

		return $instance;
	}

	/**
	 * Converts a widget instance into JSON-representable format.
	 *
	 *
	 * @global GC_Widget_Factory $gc_widget_factory
	 *
	 * @param array  $value   Widget instance to convert to JSON.
	 * @param string $id_base Optional. Base of the ID of the widget being sanitized. Default null.
	 * @return array JSON-converted widget instance.
	 */
	public function sanitize_widget_js_instance( $value, $id_base = null ) {
		global $gc_widget_factory;

		if ( empty( $value['is_widget_customizer_js_value'] ) ) {
			$serialized = serialize( $value );

			$js_value = array(
				'encoded_serialized_instance'   => base64_encode( $serialized ),
				'title'                         => empty( $value['title'] ) ? '' : $value['title'],
				'is_widget_customizer_js_value' => true,
				'instance_hash_key'             => $this->get_instance_hash_key( $serialized ),
			);

			if ( $id_base && gc_use_widgets_block_editor() ) {
				$widget_object = $gc_widget_factory->get_widget_object( $id_base );
				if ( ! empty( $widget_object->widget_options['show_instance_in_rest'] ) ) {
					$js_value['raw_instance'] = (object) $value;
				}
			}

			return $js_value;
		}

		return $value;
	}

	/**
	 * Strips out widget IDs for widgets which are no longer registered.
	 *
	 * One example where this might happen is when a plugin orphans a widget
	 * in a sidebar upon deactivation.
	 *
	 *
	 * @global array $gc_registered_widgets
	 *
	 * @param array $widget_ids List of widget IDs.
	 * @return array Parsed list of widget IDs.
	 */
	public function sanitize_sidebar_widgets_js_instance( $widget_ids ) {
		global $gc_registered_widgets;
		$widget_ids = array_values( array_intersect( $widget_ids, array_keys( $gc_registered_widgets ) ) );
		return $widget_ids;
	}

	/**
	 * Finds and invokes the widget update and control callbacks.
	 *
	 * Requires that `$_POST` be populated with the instance data.
	 *
	 *
	 * @global array $gc_registered_widget_updates
	 * @global array $gc_registered_widget_controls
	 *
	 * @param string $widget_id Widget ID.
	 * @return array|GC_Error Array containing the updated widget information.
	 *                        A GC_Error object, otherwise.
	 */
	public function call_widget_update( $widget_id ) {
		global $gc_registered_widget_updates, $gc_registered_widget_controls;

		$setting_id = $this->get_setting_id( $widget_id );

		/*
		 * Make sure that other setting changes have previewed since this widget
		 * may depend on them (e.g. Menus being present for Navigation Menu widget).
		 */
		if ( ! did_action( 'customize_preview_init' ) ) {
			foreach ( $this->manager->settings() as $setting ) {
				if ( $setting->id !== $setting_id ) {
					$setting->preview();
				}
			}
		}

		$this->start_capturing_option_updates();
		$parsed_id   = $this->parse_widget_id( $widget_id );
		$option_name = 'widget_' . $parsed_id['id_base'];

		/*
		 * If a previously-sanitized instance is provided, populate the input vars
		 * with its values so that the widget update callback will read this instance
		 */
		$added_input_vars = array();
		if ( ! empty( $_POST['sanitized_widget_setting'] ) ) {
			$sanitized_widget_setting = json_decode( $this->get_post_value( 'sanitized_widget_setting' ), true );
			if ( false === $sanitized_widget_setting ) {
				$this->stop_capturing_option_updates();
				return new GC_Error( 'widget_setting_malformed' );
			}

			$instance = $this->sanitize_widget_instance( $sanitized_widget_setting, $parsed_id['id_base'] );
			if ( is_null( $instance ) ) {
				$this->stop_capturing_option_updates();
				return new GC_Error( 'widget_setting_unsanitized' );
			}

			if ( ! is_null( $parsed_id['number'] ) ) {
				$value                         = array();
				$value[ $parsed_id['number'] ] = $instance;
				$key                           = 'widget-' . $parsed_id['id_base'];
				$_REQUEST[ $key ]              = gc_slash( $value );
				$_POST[ $key ]                 = $_REQUEST[ $key ];
				$added_input_vars[]            = $key;
			} else {
				foreach ( $instance as $key => $value ) {
					$_REQUEST[ $key ]   = gc_slash( $value );
					$_POST[ $key ]      = $_REQUEST[ $key ];
					$added_input_vars[] = $key;
				}
			}
		}

		// Invoke the widget update callback.
		foreach ( (array) $gc_registered_widget_updates as $name => $control ) {
			if ( $name === $parsed_id['id_base'] && is_callable( $control['callback'] ) ) {
				ob_start();
				call_user_func_array( $control['callback'], $control['params'] );
				ob_end_clean();
				break;
			}
		}

		// Clean up any input vars that were manually added.
		foreach ( $added_input_vars as $key ) {
			unset( $_POST[ $key ] );
			unset( $_REQUEST[ $key ] );
		}

		// Make sure the expected option was updated.
		if ( 0 !== $this->count_captured_options() ) {
			if ( $this->count_captured_options() > 1 ) {
				$this->stop_capturing_option_updates();
				return new GC_Error( 'widget_setting_too_many_options' );
			}

			$updated_option_name = key( $this->get_captured_options() );
			if ( $updated_option_name !== $option_name ) {
				$this->stop_capturing_option_updates();
				return new GC_Error( 'widget_setting_unexpected_option' );
			}
		}

		// Obtain the widget instance.
		$option = $this->get_captured_option( $option_name );
		if ( null !== $parsed_id['number'] ) {
			$instance = $option[ $parsed_id['number'] ];
		} else {
			$instance = $option;
		}

		/*
		 * Override the incoming $_POST['customized'] for a newly-created widget's
		 * setting with the new $instance so that the preview filter currently
		 * in place from GC_Customize_Setting::preview() will use this value
		 * instead of the default widget instance value (an empty array).
		 */
		$this->manager->set_post_value( $setting_id, $this->sanitize_widget_js_instance( $instance, $parsed_id['id_base'] ) );

		// Obtain the widget control with the updated instance in place.
		ob_start();
		$form = $gc_registered_widget_controls[ $widget_id ];
		if ( $form ) {
			call_user_func_array( $form['callback'], $form['params'] );
		}
		$form = ob_get_clean();

		$this->stop_capturing_option_updates();

		return compact( 'instance', 'form' );
	}

	/**
	 * Updates widget settings asynchronously.
	 *
	 * Allows the Customizer to update a widget using its form, but return the new
	 * instance info via Ajax instead of saving it to the options table.
	 *
	 * Most code here copied from gc_ajax_save_widget().
	 *
	 *
	 * @see gc_ajax_save_widget()
	 */
	public function gc_ajax_update_widget() {

		if ( ! is_user_logged_in() ) {
			gc_die( 0 );
		}

		check_ajax_referer( 'update-widget', 'nonce' );

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			gc_die( -1 );
		}

		if ( empty( $_POST['widget-id'] ) ) {
			gc_send_json_error( 'missing_widget-id' );
		}

		/** This action is documented in gc-admin/includes/ajax-actions.php */
		do_action( 'load-widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores

		/** This action is documented in gc-admin/includes/ajax-actions.php */
		do_action( 'widgets.php' ); // phpcs:ignore GeChiUI.NamingConventions.ValidHookName.UseUnderscores

		/** This action is documented in gc-admin/widgets.php */
		do_action( 'sidebar_admin_setup' );

		$widget_id = $this->get_post_value( 'widget-id' );
		$parsed_id = $this->parse_widget_id( $widget_id );
		$id_base   = $parsed_id['id_base'];

		$is_updating_widget_template = (
			isset( $_POST[ 'widget-' . $id_base ] )
			&&
			is_array( $_POST[ 'widget-' . $id_base ] )
			&&
			preg_match( '/__i__|%i%/', key( $_POST[ 'widget-' . $id_base ] ) )
		);
		if ( $is_updating_widget_template ) {
			gc_send_json_error( 'template_widget_not_updatable' );
		}

		$updated_widget = $this->call_widget_update( $widget_id ); // => {instance,form}
		if ( is_gc_error( $updated_widget ) ) {
			gc_send_json_error( $updated_widget->get_error_code() );
		}

		$form     = $updated_widget['form'];
		$instance = $this->sanitize_widget_js_instance( $updated_widget['instance'], $id_base );

		gc_send_json_success( compact( 'form', 'instance' ) );
	}

	/*
	 * Selective Refresh Methods
	 */

	/**
	 * Filters arguments for dynamic widget partials.
	 *
	 *
	 * @param array|false $partial_args Partial arguments.
	 * @param string      $partial_id   Partial ID.
	 * @return array (Maybe) modified partial arguments.
	 */
	public function customize_dynamic_partial_args( $partial_args, $partial_id ) {
		if ( ! current_theme_supports( 'customize-selective-refresh-widgets' ) ) {
			return $partial_args;
		}

		if ( preg_match( '/^widget\[(?P<widget_id>.+)\]$/', $partial_id, $matches ) ) {
			if ( false === $partial_args ) {
				$partial_args = array();
			}
			$partial_args = array_merge(
				$partial_args,
				array(
					'type'                => 'widget',
					'render_callback'     => array( $this, 'render_widget_partial' ),
					'container_inclusive' => true,
					'settings'            => array( $this->get_setting_id( $matches['widget_id'] ) ),
					'capability'          => 'edit_theme_options',
				)
			);
		}

		return $partial_args;
	}

	/**
	 * Adds hooks for selective refresh.
	 *
	 */
	public function selective_refresh_init() {
		if ( ! current_theme_supports( 'customize-selective-refresh-widgets' ) ) {
			return;
		}
		add_filter( 'dynamic_sidebar_params', array( $this, 'filter_dynamic_sidebar_params' ) );
		add_filter( 'gc_kses_allowed_html', array( $this, 'filter_gc_kses_allowed_data_attributes' ) );
		add_action( 'dynamic_sidebar_before', array( $this, 'start_dynamic_sidebar' ) );
		add_action( 'dynamic_sidebar_after', array( $this, 'end_dynamic_sidebar' ) );
	}

	/**
	 * Inject selective refresh data attributes into widget container elements.
	 *
	 *
	 * @param array $params {
	 *     Dynamic sidebar params.
	 *
	 *     @type array $args        Sidebar args.
	 *     @type array $widget_args Widget args.
	 * }
	 * @see GC_Customize_Nav_Menus::filter_gc_nav_menu_args()
	 *
	 * @return array Params.
	 */
	public function filter_dynamic_sidebar_params( $params ) {
		$sidebar_args = array_merge(
			array(
				'before_widget' => '',
				'after_widget'  => '',
			),
			$params[0]
		);

		// Skip widgets not in a registered sidebar or ones which lack a proper wrapper element to attach the data-* attributes to.
		$matches  = array();
		$is_valid = (
			isset( $sidebar_args['id'] )
			&&
			is_registered_sidebar( $sidebar_args['id'] )
			&&
			( isset( $this->current_dynamic_sidebar_id_stack[0] ) && $this->current_dynamic_sidebar_id_stack[0] === $sidebar_args['id'] )
			&&
			preg_match( '#^<(?P<tag_name>\w+)#', $sidebar_args['before_widget'], $matches )
		);
		if ( ! $is_valid ) {
			return $params;
		}
		$this->before_widget_tags_seen[ $matches['tag_name'] ] = true;

		$context = array(
			'sidebar_id' => $sidebar_args['id'],
		);
		if ( isset( $this->context_sidebar_instance_number ) ) {
			$context['sidebar_instance_number'] = $this->context_sidebar_instance_number;
		} elseif ( isset( $sidebar_args['id'] ) && isset( $this->sidebar_instance_count[ $sidebar_args['id'] ] ) ) {
			$context['sidebar_instance_number'] = $this->sidebar_instance_count[ $sidebar_args['id'] ];
		}

		$attributes                    = sprintf( ' data-customize-partial-id="%s"', esc_attr( 'widget[' . $sidebar_args['widget_id'] . ']' ) );
		$attributes                   .= ' data-customize-partial-type="widget"';
		$attributes                   .= sprintf( ' data-customize-partial-placement-context="%s"', esc_attr( gc_json_encode( $context ) ) );
		$attributes                   .= sprintf( ' data-customize-widget-id="%s"', esc_attr( $sidebar_args['widget_id'] ) );
		$sidebar_args['before_widget'] = preg_replace( '#^(<\w+)#', '$1 ' . $attributes, $sidebar_args['before_widget'] );

		$params[0] = $sidebar_args;
		return $params;
	}

	/**
	 * List of the tag names seen for before_widget strings.
	 *
	 * This is used in the {@see 'filter_gc_kses_allowed_html'} filter to ensure that the
	 * data-* attributes can be allowed.
	 *
	 * @var array
	 */
	protected $before_widget_tags_seen = array();

	/**
	 * Ensures the HTML data-* attributes for selective refresh are allowed by kses.
	 *
	 * This is needed in case the `$before_widget` is run through gc_kses() when printed.
	 *
	 *
	 * @param array $allowed_html Allowed HTML.
	 * @return array (Maybe) modified allowed HTML.
	 */
	public function filter_gc_kses_allowed_data_attributes( $allowed_html ) {
		foreach ( array_keys( $this->before_widget_tags_seen ) as $tag_name ) {
			if ( ! isset( $allowed_html[ $tag_name ] ) ) {
				$allowed_html[ $tag_name ] = array();
			}
			$allowed_html[ $tag_name ] = array_merge(
				$allowed_html[ $tag_name ],
				array_fill_keys(
					array(
						'data-customize-partial-id',
						'data-customize-partial-type',
						'data-customize-partial-placement-context',
						'data-customize-partial-widget-id',
						'data-customize-partial-options',
					),
					true
				)
			);
		}
		return $allowed_html;
	}

	/**
	 * Keep track of the number of times that dynamic_sidebar() was called for a given sidebar index.
	 *
	 * This helps facilitate the uncommon scenario where a single sidebar is rendered multiple times on a template.
	 *
	 * @var array
	 */
	protected $sidebar_instance_count = array();

	/**
	 * The current request's sidebar_instance_number context.
	 *
	 * @var int|null
	 */
	protected $context_sidebar_instance_number;

	/**
	 * Current sidebar ID being rendered.
	 *
	 * @var array
	 */
	protected $current_dynamic_sidebar_id_stack = array();

	/**
	 * Begins keeping track of the current sidebar being rendered.
	 *
	 * Insert marker before widgets are rendered in a dynamic sidebar.
	 *
	 *
	 * @param int|string $index Index, name, or ID of the dynamic sidebar.
	 */
	public function start_dynamic_sidebar( $index ) {
		array_unshift( $this->current_dynamic_sidebar_id_stack, $index );
		if ( ! isset( $this->sidebar_instance_count[ $index ] ) ) {
			$this->sidebar_instance_count[ $index ] = 0;
		}
		$this->sidebar_instance_count[ $index ] += 1;
		if ( ! $this->manager->selective_refresh->is_render_partials_request() ) {
			printf( "\n<!--dynamic_sidebar_before:%s:%d-->\n", esc_html( $index ), (int) $this->sidebar_instance_count[ $index ] );
		}
	}

	/**
	 * Finishes keeping track of the current sidebar being rendered.
	 *
	 * Inserts a marker after widgets are rendered in a dynamic sidebar.
	 *
	 *
	 * @param int|string $index Index, name, or ID of the dynamic sidebar.
	 */
	public function end_dynamic_sidebar( $index ) {
		array_shift( $this->current_dynamic_sidebar_id_stack );
		if ( ! $this->manager->selective_refresh->is_render_partials_request() ) {
			printf( "\n<!--dynamic_sidebar_after:%s:%d-->\n", esc_html( $index ), (int) $this->sidebar_instance_count[ $index ] );
		}
	}

	/**
	 * Current sidebar being rendered.
	 *
	 * @var string|null
	 */
	protected $rendering_widget_id;

	/**
	 * Current widget being rendered.
	 *
	 * @var string|null
	 */
	protected $rendering_sidebar_id;

	/**
	 * Filters sidebars_widgets to ensure the currently-rendered widget is the only widget in the current sidebar.
	 *
	 *
	 * @param array $sidebars_widgets Sidebars widgets.
	 * @return array Filtered sidebars widgets.
	 */
	public function filter_sidebars_widgets_for_rendering_widget( $sidebars_widgets ) {
		$sidebars_widgets[ $this->rendering_sidebar_id ] = array( $this->rendering_widget_id );
		return $sidebars_widgets;
	}

	/**
	 * Renders a specific widget using the supplied sidebar arguments.
	 *
	 *
	 * @see dynamic_sidebar()
	 *
	 * @param GC_Customize_Partial $partial Partial.
	 * @param array                $context {
	 *     Sidebar args supplied as container context.
	 *
	 *     @type string $sidebar_id              ID for sidebar for widget to render into.
	 *     @type int    $sidebar_instance_number Disambiguating instance number.
	 * }
	 * @return string|false
	 */
	public function render_widget_partial( $partial, $context ) {
		$id_data   = $partial->id_data();
		$widget_id = array_shift( $id_data['keys'] );

		if ( ! is_array( $context )
			|| empty( $context['sidebar_id'] )
			|| ! is_registered_sidebar( $context['sidebar_id'] )
		) {
			return false;
		}

		$this->rendering_sidebar_id = $context['sidebar_id'];

		if ( isset( $context['sidebar_instance_number'] ) ) {
			$this->context_sidebar_instance_number = (int) $context['sidebar_instance_number'];
		}

		// Filter sidebars_widgets so that only the queried widget is in the sidebar.
		$this->rendering_widget_id = $widget_id;

		$filter_callback = array( $this, 'filter_sidebars_widgets_for_rendering_widget' );
		add_filter( 'sidebars_widgets', $filter_callback, 1000 );

		// Render the widget.
		ob_start();
		$this->rendering_sidebar_id = $context['sidebar_id'];
		dynamic_sidebar( $this->rendering_sidebar_id );
		$container = ob_get_clean();

		// Reset variables for next partial render.
		remove_filter( 'sidebars_widgets', $filter_callback, 1000 );

		$this->context_sidebar_instance_number = null;
		$this->rendering_sidebar_id            = null;
		$this->rendering_widget_id             = null;

		return $container;
	}

	//
	// Option Update Capturing.
	//

	/**
	 * List of captured widget option updates.
	 *
	 * @var array $_captured_options Values updated while option capture is happening.
	 */
	protected $_captured_options = array();

	/**
	 * Whether option capture is currently happening.
	 *
	 * @var bool $_is_current Whether option capture is currently happening or not.
	 */
	protected $_is_capturing_option_updates = false;

	/**
	 * Determines whether the captured option update should be ignored.
	 *
	 *
	 * @param string $option_name Option name.
	 * @return bool Whether the option capture is ignored.
	 */
	protected function is_option_capture_ignored( $option_name ) {
		return ( 0 === strpos( $option_name, '_transient_' ) );
	}

	/**
	 * Retrieves captured widget option updates.
	 *
	 *
	 * @return array Array of captured options.
	 */
	protected function get_captured_options() {
		return $this->_captured_options;
	}

	/**
	 * Retrieves the option that was captured from being saved.
	 *
	 *
	 * @param string $option_name Option name.
	 * @param mixed  $default     Optional. Default value to return if the option does not exist. Default false.
	 * @return mixed Value set for the option.
	 */
	protected function get_captured_option( $option_name, $default = false ) {
		if ( array_key_exists( $option_name, $this->_captured_options ) ) {
			$value = $this->_captured_options[ $option_name ];
		} else {
			$value = $default;
		}
		return $value;
	}

	/**
	 * Retrieves the number of captured widget option updates.
	 *
	 *
	 * @return int Number of updated options.
	 */
	protected function count_captured_options() {
		return count( $this->_captured_options );
	}

	/**
	 * Begins keeping track of changes to widget options, caching new values.
	 *
	 */
	protected function start_capturing_option_updates() {
		if ( $this->_is_capturing_option_updates ) {
			return;
		}

		$this->_is_capturing_option_updates = true;

		add_filter( 'pre_update_option', array( $this, 'capture_filter_pre_update_option' ), 10, 3 );
	}

	/**
	 * Pre-filters captured option values before updating.
	 *
	 *
	 * @param mixed  $new_value   The new option value.
	 * @param string $option_name Name of the option.
	 * @param mixed  $old_value   The old option value.
	 * @return mixed Filtered option value.
	 */
	public function capture_filter_pre_update_option( $new_value, $option_name, $old_value ) {
		if ( $this->is_option_capture_ignored( $option_name ) ) {
			return $new_value;
		}

		if ( ! isset( $this->_captured_options[ $option_name ] ) ) {
			add_filter( "pre_option_{$option_name}", array( $this, 'capture_filter_pre_get_option' ) );
		}

		$this->_captured_options[ $option_name ] = $new_value;

		return $old_value;
	}

	/**
	 * Pre-filters captured option values before retrieving.
	 *
	 *
	 * @param mixed $value Value to return instead of the option value.
	 * @return mixed Filtered option value.
	 */
	public function capture_filter_pre_get_option( $value ) {
		$option_name = preg_replace( '/^pre_option_/', '', current_filter() );

		if ( isset( $this->_captured_options[ $option_name ] ) ) {
			$value = $this->_captured_options[ $option_name ];

			/** This filter is documented in gc-includes/option.php */
			$value = apply_filters( 'option_' . $option_name, $value, $option_name );
		}

		return $value;
	}

	/**
	 * Undoes any changes to the options since options capture began.
	 *
	 */
	protected function stop_capturing_option_updates() {
		if ( ! $this->_is_capturing_option_updates ) {
			return;
		}

		remove_filter( 'pre_update_option', array( $this, 'capture_filter_pre_update_option' ), 10 );

		foreach ( array_keys( $this->_captured_options ) as $option_name ) {
			remove_filter( "pre_option_{$option_name}", array( $this, 'capture_filter_pre_get_option' ) );
		}

		$this->_captured_options            = array();
		$this->_is_capturing_option_updates = false;
	}

	/**
	 * {@internal Missing Summary}
	 *
	 * See the {@see 'customize_dynamic_setting_args'} filter.
	 *
	 * @deprecated 4.2.0 Deprecated in favor of the {@see 'customize_dynamic_setting_args'} filter.
	 */
	public function setup_widget_addition_previews() {
		_deprecated_function( __METHOD__, '4.2.0', 'customize_dynamic_setting_args' );
	}

	/**
	 * {@internal Missing Summary}
	 *
	 * See the {@see 'customize_dynamic_setting_args'} filter.
	 *
	 * @deprecated 4.2.0 Deprecated in favor of the {@see 'customize_dynamic_setting_args'} filter.
	 */
	public function prepreview_added_sidebars_widgets() {
		_deprecated_function( __METHOD__, '4.2.0', 'customize_dynamic_setting_args' );
	}

	/**
	 * {@internal Missing Summary}
	 *
	 * See the {@see 'customize_dynamic_setting_args'} filter.
	 *
	 * @deprecated 4.2.0 Deprecated in favor of the {@see 'customize_dynamic_setting_args'} filter.
	 */
	public function prepreview_added_widget_instance() {
		_deprecated_function( __METHOD__, '4.2.0', 'customize_dynamic_setting_args' );
	}

	/**
	 * {@internal Missing Summary}
	 *
	 * See the {@see 'customize_dynamic_setting_args'} filter.
	 *
	 * @deprecated 4.2.0 Deprecated in favor of the {@see 'customize_dynamic_setting_args'} filter.
	 */
	public function remove_prepreview_filters() {
		_deprecated_function( __METHOD__, '4.2.0', 'customize_dynamic_setting_args' );
	}
}
