<?php
/**
 * Plugin Name: DS Schrankerl Map
 * Plugin URI: https://devsolution.at
 * Description: Place linked company logos as markers on an image map.
 * Version: 1.0.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: devsolution
 * Text Domain: ds-schrankerl-map
 */

namespace DSSchrankerlMap;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	const VERSION       = '1.0.0';
	const POST_TYPE     = 'ds_schrankerl_map';
	const SHORTCODE     = 'ds_schrankerl_map';
	const META_IMAGE    = '_ds_schrankerl_map_image_id';
	const META_MARKERS  = '_ds_schrankerl_map_markers';
	const NONCE_ACTION  = 'ds_schrankerl_map_save';
	const NONCE_NAME    = 'ds_schrankerl_map_nonce';
	const DEFAULT_WIDTH = 40;
	const MAX_WIDTH     = 2000;

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_shortcode' ] );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, [ $this, 'add_meta_box' ] );
		add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta_box' ] );
		add_action( 'wp_enqueue_scripts', [ $this, 'register_frontend_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'add_shortcode_column' ] );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_shortcode_column' ], 10, 2 );
	}

	public function register_post_type() {
		$labels = [
			'name'               => __( 'Schrankerl Maps', 'ds-schrankerl-map' ),
			'singular_name'      => __( 'Schrankerl Map', 'ds-schrankerl-map' ),
			'menu_name'          => __( 'Schrankerl Maps', 'ds-schrankerl-map' ),
			'add_new'            => __( 'Add New', 'ds-schrankerl-map' ),
			'add_new_item'       => __( 'Add New Schrankerl Map', 'ds-schrankerl-map' ),
			'edit_item'          => __( 'Edit Schrankerl Map', 'ds-schrankerl-map' ),
			'new_item'           => __( 'New Schrankerl Map', 'ds-schrankerl-map' ),
			'view_item'          => __( 'View Schrankerl Map', 'ds-schrankerl-map' ),
			'search_items'       => __( 'Search Schrankerl Maps', 'ds-schrankerl-map' ),
			'not_found'          => __( 'No Schrankerl Maps found', 'ds-schrankerl-map' ),
			'not_found_in_trash' => __( 'No Schrankerl Maps found in Trash', 'ds-schrankerl-map' ),
		];

		register_post_type(
			self::POST_TYPE,
			[
				'labels'       => $labels,
				'public'       => false, // Maps are only embedded via shortcode.
				'show_ui'      => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-location',
				'supports'     => [ 'title' ],
				'show_in_rest' => false, // Keeps the classic editor with our meta box.
			]
		);
	}

	public function register_shortcode() {
		add_shortcode( self::SHORTCODE, [ $this, 'render_shortcode' ] );
	}

	public function add_meta_box() {
		add_meta_box(
			'ds-schrankerl-map-editor',
			__( 'Schrankerl Map Editor', 'ds-schrankerl-map' ),
			[ $this, 'render_meta_box' ],
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );

		$image_id  = $this->get_image_id( $post->ID );
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'full' ) : '';

		// Logo URLs are only needed for the admin preview and are never trusted on save.
		$markers = array_map(
			function ( $marker ) {
				$marker['logo_url'] = $marker['logo_id'] ? (string) wp_get_attachment_image_url( $marker['logo_id'], 'medium' ) : '';
				return $marker;
			},
			$this->get_markers( $post->ID )
		);
		?>
		<div id="ds-schrankerl-map-admin" class="ds-schrankerl-map-admin">
			<p class="ds-schrankerl-map-admin__shortcode">
				<?php esc_html_e( 'Shortcode:', 'ds-schrankerl-map' ); ?>
				<code><?php echo esc_html( $this->get_shortcode( $post->ID ) ); ?></code>
			</p>

			<p class="ds-schrankerl-map-admin__image-actions">
				<button type="button" class="button" id="ds-schrankerl-map-select-image"><?php esc_html_e( 'Select Map Image', 'ds-schrankerl-map' ); ?></button>
				<button type="button" class="button-link button-link-delete" id="ds-schrankerl-map-remove-image" <?php echo $image_id ? '' : 'hidden'; ?>><?php esc_html_e( 'Remove Map Image', 'ds-schrankerl-map' ); ?></button>
				<input type="hidden" id="ds-schrankerl-map-image-id" name="<?php echo esc_attr( self::META_IMAGE ); ?>" value="<?php echo esc_attr( $image_id ?: '' ); ?>" />
			</p>

			<div class="ds-schrankerl-map-admin__editor" id="ds-schrankerl-map-editor-area" <?php echo $image_url ? '' : 'hidden'; ?>>
				<p class="description"><?php esc_html_e( 'Click on the image to place a marker. Drag a marker to move it.', 'ds-schrankerl-map' ); ?></p>
				<div class="ds-schrankerl-map-admin__canvas" id="ds-schrankerl-map-canvas">
					<img id="ds-schrankerl-map-base-image" src="<?php echo esc_url( (string) $image_url ); ?>" alt="" draggable="false" />
				</div>

				<h3><?php esc_html_e( 'Markers', 'ds-schrankerl-map' ); ?></h3>
				<p class="description" id="ds-schrankerl-map-empty"><?php esc_html_e( 'No markers yet.', 'ds-schrankerl-map' ); ?></p>
				<ol class="ds-schrankerl-map-admin__list" id="ds-schrankerl-map-list"></ol>
			</div>

			<input type="hidden" id="ds-schrankerl-map-markers" name="<?php echo esc_attr( self::META_MARKERS ); ?>" value="<?php echo esc_attr( wp_json_encode( $markers ) ); ?>" />
		</div>
		<?php
	}

	public function save_meta_box( $post_id ) {
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}

		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST[ self::META_IMAGE ] ) ) {
			$image_id = absint( $_POST[ self::META_IMAGE ] );
			if ( $image_id && wp_attachment_is_image( $image_id ) ) {
				update_post_meta( $post_id, self::META_IMAGE, $image_id );
			} else {
				delete_post_meta( $post_id, self::META_IMAGE );
			}
		}

		if ( isset( $_POST[ self::META_MARKERS ] ) ) {
			// Raw JSON: every field is sanitized individually in sanitize_markers().
			$decoded = json_decode( wp_unslash( $_POST[ self::META_MARKERS ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( is_array( $decoded ) ) {
				// update_post_meta() unslashes its value, so slash it first to keep it intact.
				update_post_meta( $post_id, self::META_MARKERS, wp_slash( $this->sanitize_markers( $decoded ) ) );
			}
		}
	}

	public function enqueue_admin_assets( $hook ) {
		if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'ds-schrankerl-map-admin',
			plugins_url( 'src/admin/ds-schrankerl-map-admin.css', __FILE__ ),
			[],
			$this->asset_version( 'src/admin/ds-schrankerl-map-admin.css' )
		);

		wp_enqueue_script(
			'ds-schrankerl-map-admin',
			plugins_url( 'src/admin/ds-schrankerl-map-admin.js', __FILE__ ),
			[ 'jquery' ],
			$this->asset_version( 'src/admin/ds-schrankerl-map-admin.js' ),
			true
		);

		wp_localize_script(
			'ds-schrankerl-map-admin',
			'dsSchrankerlMap',
			[
				'defaultWidth' => self::DEFAULT_WIDTH,
				'maxWidth'     => self::MAX_WIDTH,
				'i18n'         => [
					'marker'        => __( 'Marker %d', 'ds-schrankerl-map' ),
					'logo'          => __( 'Logo', 'ds-schrankerl-map' ),
					'selectLogo'    => __( 'Select Logo', 'ds-schrankerl-map' ),
					'changeLogo'    => __( 'Change Logo', 'ds-schrankerl-map' ),
					'noLogo'        => __( 'No logo selected. Markers without a logo are not shown on the website.', 'ds-schrankerl-map' ),
					'url'           => __( 'Link URL (optional)', 'ds-schrankerl-map' ),
					'width'         => __( 'Width (px)', 'ds-schrankerl-map' ),
					'delete'        => __( 'Delete', 'ds-schrankerl-map' ),
					'confirmDelete' => __( 'Delete this marker?', 'ds-schrankerl-map' ),
					'imageFrame'    => __( 'Select Map Image', 'ds-schrankerl-map' ),
					'logoFrame'     => __( 'Select Company Logo', 'ds-schrankerl-map' ),
					'useImage'      => __( 'Use this image', 'ds-schrankerl-map' ),
				],
			]
		);
	}

	public function register_frontend_assets() {
		wp_register_style(
			'ds-schrankerl-map',
			plugins_url( 'src/frontend/ds-schrankerl-map-frontend.css', __FILE__ ),
			[],
			$this->asset_version( 'src/frontend/ds-schrankerl-map-frontend.css' )
		);
	}

	public function render_shortcode( $atts ) {
		$atts    = shortcode_atts( [ 'id' => 0 ], $atts, self::SHORTCODE );
		$post_id = absint( $atts['id'] );

		if ( ! $post_id || self::POST_TYPE !== get_post_type( $post_id ) || 'publish' !== get_post_status( $post_id ) ) {
			return '';
		}

		$image_id = $this->get_image_id( $post_id );
		if ( ! $image_id ) {
			return '';
		}

		$image = wp_get_attachment_image(
			$image_id,
			'full',
			false,
			[
				'class' => 'ds-schrankerl-map__image',
				'alt'   => get_the_title( $post_id ),
			]
		);
		if ( ! $image ) {
			return '';
		}

		wp_enqueue_style( 'ds-schrankerl-map' );

		ob_start();
		?>
		<div class="ds-schrankerl-map">
			<?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_get_attachment_image(). ?>
			<?php foreach ( $this->get_markers( $post_id ) as $marker ) : ?>
				<?php
				if ( ! $marker['logo_id'] ) {
					continue;
				}

				$name = trim( (string) get_post_meta( $marker['logo_id'], '_wp_attachment_image_alt', true ) );
				if ( '' === $name ) {
					$name = get_the_title( $marker['logo_id'] );
				}

				$logo = wp_get_attachment_image(
					$marker['logo_id'],
					'full',
					false,
					[
						'class'   => 'ds-schrankerl-map__logo',
						'alt'     => $name,
						'sizes'   => $marker['width'] . 'px',
						'loading' => 'lazy',
					]
				);
				if ( ! $logo ) {
					continue;
				}

				// %F is locale-independent, so the decimal separator is always a dot.
				$style = sprintf( 'left:%.3F%%;top:%.3F%%;width:%dpx;', $marker['x'], $marker['y'], $marker['width'] );
				?>
				<?php if ( $marker['url'] ) : ?>
					<a class="ds-schrankerl-map__marker" href="<?php echo esc_url( $marker['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $name ); ?>" style="<?php echo esc_attr( $style ); ?>"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<?php else : ?>
					<span class="ds-schrankerl-map__marker" title="<?php echo esc_attr( $name ); ?>" style="<?php echo esc_attr( $style ); ?>"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	public function add_shortcode_column( $columns ) {
		$columns['ds_schrankerl_map_shortcode'] = __( 'Shortcode', 'ds-schrankerl-map' );
		return $columns;
	}

	public function render_shortcode_column( $column, $post_id ) {
		if ( 'ds_schrankerl_map_shortcode' === $column ) {
			echo '<code>' . esc_html( $this->get_shortcode( $post_id ) ) . '</code>';
		}
	}

	private function get_shortcode( $post_id ) {
		return sprintf( '[%s id="%d"]', self::SHORTCODE, $post_id );
	}

	private function get_image_id( $post_id ) {
		$image_id = absint( get_post_meta( $post_id, self::META_IMAGE, true ) );
		return $image_id && wp_attachment_is_image( $image_id ) ? $image_id : 0;
	}

	private function get_markers( $post_id ) {
		return $this->sanitize_markers( get_post_meta( $post_id, self::META_MARKERS, true ) );
	}

	private function sanitize_markers( $markers ) {
		if ( ! is_array( $markers ) ) {
			return [];
		}

		$clean = [];
		foreach ( $markers as $marker ) {
			if ( ! is_array( $marker ) || ! isset( $marker['x'], $marker['y'] ) || ! is_numeric( $marker['x'] ) || ! is_numeric( $marker['y'] ) ) {
				continue;
			}

			$id      = isset( $marker['id'] ) ? preg_replace( '/[^a-z0-9]/i', '', (string) $marker['id'] ) : '';
			$logo_id = isset( $marker['logo_id'] ) ? absint( $marker['logo_id'] ) : 0;
			$width   = isset( $marker['width'] ) ? absint( $marker['width'] ) : 0;

			$clean[] = [
				'id'      => '' !== $id ? $id : wp_generate_password( 9, false ),
				'x'       => round( min( 100, max( 0, (float) $marker['x'] ) ), 3 ),
				'y'       => round( min( 100, max( 0, (float) $marker['y'] ) ), 3 ),
				'logo_id' => $logo_id && wp_attachment_is_image( $logo_id ) ? $logo_id : 0,
				'url'     => isset( $marker['url'] ) ? esc_url_raw( trim( (string) $marker['url'] ) ) : '',
				'width'   => $width ? min( $width, self::MAX_WIDTH ) : self::DEFAULT_WIDTH,
			];
		}

		return $clean;
	}

	private function asset_version( $relative_path ) {
		$mtime = filemtime( plugin_dir_path( __FILE__ ) . $relative_path );
		return $mtime ? self::VERSION . '.' . $mtime : self::VERSION;
	}
}

Plugin::get_instance();
