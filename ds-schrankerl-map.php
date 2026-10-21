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
	const TYPES         = [ 'none', 'link', 'popup' ];

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

		// Image URLs are only needed for the admin preview and are never trusted on save.
		$markers = array_map(
			function ( $marker ) {
				$marker['logo_url']     = $marker['logo_id'] ? (string) wp_get_attachment_image_url( $marker['logo_id'], 'medium' ) : '';
				$marker['popup_images'] = array_map(
					function ( $image_id ) {
						return [
							'id'  => $image_id,
							'url' => (string) wp_get_attachment_image_url( $image_id, 'thumbnail' ),
						];
					},
					$marker['popup_images']
				);
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
		wp_enqueue_editor();

		wp_enqueue_style(
			'ds-schrankerl-map-admin',
			plugins_url( 'src/admin/ds-schrankerl-map-admin.css', __FILE__ ),
			[],
			$this->asset_version( 'src/admin/ds-schrankerl-map-admin.css' )
		);

		wp_enqueue_script(
			'ds-schrankerl-map-admin',
			plugins_url( 'src/admin/ds-schrankerl-map-admin.js', __FILE__ ),
			[ 'jquery', 'jquery-ui-sortable', 'wplink' ],
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
					'type'          => __( 'Type', 'ds-schrankerl-map' ),
					'typeNone'      => __( 'None', 'ds-schrankerl-map' ),
					'typeLink'      => __( 'Link', 'ds-schrankerl-map' ),
					'typePopup'     => __( 'Popup', 'ds-schrankerl-map' ),
					'url'           => __( 'Link URL', 'ds-schrankerl-map' ),
					'popup'         => __( 'Popup', 'ds-schrankerl-map' ),
					'popupHint'     => __( 'All fields are optional. The button is shown once its link has a URL and a link text.', 'ds-schrankerl-map' ),
					'popupImages'   => __( 'Images', 'ds-schrankerl-map' ),
					'addImages'     => __( 'Add Images', 'ds-schrankerl-map' ),
					'removeImage'   => __( 'Remove image', 'ds-schrankerl-map' ),
					'imagesFrame'   => __( 'Select Popup Images', 'ds-schrankerl-map' ),
					'useImages'     => __( 'Use these images', 'ds-schrankerl-map' ),
					'popupTitle'    => __( 'Title', 'ds-schrankerl-map' ),
					'popupText'     => __( 'Text', 'ds-schrankerl-map' ),
					'button'        => __( 'Button', 'ds-schrankerl-map' ),
					'selectLink'    => __( 'Select Link', 'ds-schrankerl-map' ),
					'edit'          => __( 'Edit', 'ds-schrankerl-map' ),
					'remove'        => __( 'Remove', 'ds-schrankerl-map' ),
					'newTab'        => __( 'Opens in a new tab', 'ds-schrankerl-map' ),
					'noLinkText'    => __( 'No link text: the button is not shown.', 'ds-schrankerl-map' ),
					'width'         => __( 'Width (px)', 'ds-schrankerl-map' ),
					'widthMobile'   => __( 'Mobile width (px)', 'ds-schrankerl-map' ),
					'sameAsWidth'   => __( 'Same as width', 'ds-schrankerl-map' ),
					'fitCover'      => __( 'Cover marker', 'ds-schrankerl-map' ),
					'yes'           => __( 'Yes', 'ds-schrankerl-map' ),
					'no'            => __( 'No', 'ds-schrankerl-map' ),
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

		wp_register_script(
			'ds-schrankerl-map',
			plugins_url( 'src/frontend/ds-schrankerl-map-frontend.js', __FILE__ ),
			[],
			$this->asset_version( 'src/frontend/ds-schrankerl-map-frontend.js' ),
			true
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

		// Collected separately so the dialogs follow all markers inside the map.
		$popups = '';

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
						'class'   => 'ds-schrankerl-map__logo' . ( 'contain' === $marker['fit'] ? ' ds-schrankerl-map__logo--contain' : '' ),
						'alt'     => $name,
						'sizes'   => $marker['width_mobile'] ? sprintf( '(max-width: 767px) %dpx, %dpx', $marker['width_mobile'], $marker['width'] ) : $marker['width'] . 'px',
						'loading' => 'lazy',
					]
				);
				if ( ! $logo ) {
					continue;
				}

				// %F is locale-independent, so the decimal separator is always a dot.
				$style = sprintf( 'left:%.3F%%;top:%.3F%%;--ds-schrankerl-map-marker-width:%dpx;', $marker['x'], $marker['y'], $marker['width'] );
				if ( $marker['width_mobile'] ) {
					$style .= sprintf( '--ds-schrankerl-map-marker-width-mobile:%dpx;', $marker['width_mobile'] );
				}

				$type = $this->get_marker_type( $marker );
				?>
				<?php if ( 'link' === $type ) : ?>
					<a class="ds-schrankerl-map__marker" href="<?php echo esc_url( $marker['url'] ); ?>" target="_blank" rel="noopener" title="<?php echo esc_attr( $name ); ?>" style="<?php echo esc_attr( $style ); ?>"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a>
				<?php elseif ( 'popup' === $type ) : ?>
					<?php
					$popup_id = wp_unique_id( 'ds-schrankerl-map-popup-' );
					$popups  .= $this->render_popup( $marker, $popup_id, $name );
					?>
					<button type="button" class="ds-schrankerl-map__marker" aria-haspopup="dialog" aria-expanded="false" aria-controls="<?php echo esc_attr( $popup_id ); ?>" data-ds-schrankerl-map-popup title="<?php echo esc_attr( $name ); ?>" style="<?php echo esc_attr( $style ); ?>"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></button>
				<?php else : ?>
					<span class="ds-schrankerl-map__marker" title="<?php echo esc_attr( $name ); ?>" style="<?php echo esc_attr( $style ); ?>"><?php echo $logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
				<?php endif; ?>
			<?php endforeach; ?>
			<?php echo $popups; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in render_popup(). ?>
		</div>
		<?php
		if ( '' !== $popups ) {
			wp_enqueue_script( 'ds-schrankerl-map' );
		}

		return ob_get_clean();
	}

	private function render_popup( $marker, $popup_id, $label ) {
		$slides = [];
		foreach ( $marker['popup_images'] as $image_id ) {
			$slide = wp_get_attachment_image(
				$image_id,
				'large',
				false,
				[
					'class'   => 'ds-schrankerl-map-popup__image',
					'sizes'   => '(max-width: 600px) 100vw, 600px',
					'loading' => 'lazy',
				]
			);
			if ( $slide ) {
				$slides[] = $slide;
			}
		}

		$has_title  = '' !== $marker['popup_title'];
		$has_text   = '' !== $marker['popup_text'];
		$has_button = $this->has_popup_button( $marker );
		$title_id   = $popup_id . '-title';

		ob_start();
		?>
		<dialog class="ds-schrankerl-map-popup" id="<?php echo esc_attr( $popup_id ); ?>" <?php echo $has_title ? 'aria-labelledby="' . esc_attr( $title_id ) . '"' : 'aria-label="' . esc_attr( $label ) . '"'; ?>>
			<button type="button" class="ds-schrankerl-map-popup__close" data-ds-schrankerl-map-close aria-label="<?php esc_attr_e( 'Close', 'ds-schrankerl-map' ); ?>">
				<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
			</button>

			<?php if ( $slides ) : ?>
				<div class="ds-schrankerl-map-popup__slider" data-ds-schrankerl-map-slider>
					<div class="ds-schrankerl-map-popup__track" data-ds-schrankerl-map-track <?php echo count( $slides ) > 1 ? 'tabindex="0"' : ''; ?>>
						<?php foreach ( $slides as $slide ) : ?>
							<div class="ds-schrankerl-map-popup__slide"><?php echo $slide; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by wp_get_attachment_image(). ?></div>
						<?php endforeach; ?>
					</div>
					<?php if ( count( $slides ) > 1 ) : ?>
						<button type="button" class="ds-schrankerl-map-popup__arrow ds-schrankerl-map-popup__arrow--prev" data-ds-schrankerl-map-prev aria-label="<?php esc_attr_e( 'Previous image', 'ds-schrankerl-map' ); ?>" disabled>
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18" /></svg>
						</button>
						<button type="button" class="ds-schrankerl-map-popup__arrow ds-schrankerl-map-popup__arrow--next" data-ds-schrankerl-map-next aria-label="<?php esc_attr_e( 'Next image', 'ds-schrankerl-map' ); ?>">
							<svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" /></svg>
						</button>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( $has_title || $has_text || $has_button ) : ?>
				<div class="ds-schrankerl-map-popup__body">
					<?php if ( $has_title ) : ?>
						<h2 class="ds-schrankerl-map-popup__title" id="<?php echo esc_attr( $title_id ); ?>"><?php echo esc_html( $marker['popup_title'] ); ?></h2>
					<?php endif; ?>
					<?php if ( $has_text ) : ?>
						<div class="ds-schrankerl-map-popup__text"><?php echo wp_kses( $marker['popup_text'], $this->popup_text_allowed_html() ); ?></div>
					<?php endif; ?>
					<?php if ( $has_button ) : ?>
						<a class="ds-schrankerl-map-popup__button" href="<?php echo esc_url( $marker['popup_button_url'] ); ?>" <?php echo $marker['popup_new_tab'] ? 'target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html( $marker['popup_button_text'] ); ?></a>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</dialog>
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
			$mobile  = isset( $marker['width_mobile'] ) ? absint( $marker['width_mobile'] ) : 0;
			$url     = isset( $marker['url'] ) ? esc_url_raw( trim( (string) $marker['url'] ) ) : '';

			$type = isset( $marker['type'] ) && in_array( $marker['type'], self::TYPES, true ) ? $marker['type'] : '';
			if ( '' === $type ) {
				// Markers saved before the type select existed: a URL meant "link".
				$type = '' !== $url ? 'link' : 'none';
			}

			$popup_images = [];
			if ( isset( $marker['popup_images'] ) && is_array( $marker['popup_images'] ) ) {
				foreach ( $marker['popup_images'] as $image ) {
					// The admin sends { id, url } objects, the stored meta holds plain IDs.
					$image_id = is_array( $image ) && isset( $image['id'] ) ? $image['id'] : $image;
					$image_id = is_scalar( $image_id ) ? absint( $image_id ) : 0;
					if ( $image_id && wp_attachment_is_image( $image_id ) ) {
						$popup_images[] = $image_id;
					}
				}
			}

			$popup_text = wp_kses( $this->string_field( $marker, 'popup_text' ), $this->popup_text_allowed_html() );
			if ( '' === trim( wp_strip_all_tags( str_replace( '&nbsp;', ' ', $popup_text ) ) ) ) {
				$popup_text = '';
			}

			$clean[] = [
				'id'                => '' !== $id ? $id : wp_generate_password( 9, false ),
				'x'                 => round( min( 100, max( 0, (float) $marker['x'] ) ), 3 ),
				'y'                 => round( min( 100, max( 0, (float) $marker['y'] ) ), 3 ),
				'logo_id'           => $logo_id && wp_attachment_is_image( $logo_id ) ? $logo_id : 0,
				'type'              => $type,
				'url'               => $url,
				'width'             => $width ? min( $width, self::MAX_WIDTH ) : self::DEFAULT_WIDTH,
				// 0 means "same as width".
				'width_mobile'      => min( $mobile, self::MAX_WIDTH ),
				'fit'               => isset( $marker['fit'] ) && 'contain' === $marker['fit'] ? 'contain' : 'cover',
				'popup_images'      => array_values( array_unique( $popup_images ) ),
				'popup_title'       => sanitize_text_field( $this->string_field( $marker, 'popup_title' ) ),
				'popup_text'        => $popup_text,
				'popup_button_text' => sanitize_text_field( $this->string_field( $marker, 'popup_button_text' ) ),
				'popup_button_url'  => esc_url_raw( $this->string_field( $marker, 'popup_button_url' ) ),
				// Defaults to a new tab, like regular marker links.
				'popup_new_tab'     => ! isset( $marker['popup_new_tab'] ) || ! empty( $marker['popup_new_tab'] ),
			];
		}

		return $clean;
	}

	private function string_field( $marker, $key ) {
		return isset( $marker[ $key ] ) && is_scalar( $marker[ $key ] ) ? trim( (string) $marker[ $key ] ) : '';
	}

	private function popup_text_allowed_html() {
		return [
			'p'      => [],
			'br'     => [],
			'strong' => [],
			'b'      => [],
			'em'     => [],
			'i'      => [],
			'a'      => [
				'href'   => true,
				'target' => true,
				'rel'    => true,
			],
		];
	}

	// Falls back to "none" when the chosen type has nothing to show.
	private function get_marker_type( $marker ) {
		if ( 'link' === $marker['type'] && '' !== $marker['url'] ) {
			return 'link';
		}

		if ( 'popup' === $marker['type'] && ( $marker['popup_images'] || '' !== $marker['popup_title'] || '' !== $marker['popup_text'] || $this->has_popup_button( $marker ) ) ) {
			return 'popup';
		}

		return 'none';
	}

	private function has_popup_button( $marker ) {
		return '' !== $marker['popup_button_text'] && '' !== $marker['popup_button_url'];
	}

	private function asset_version( $relative_path ) {
		$mtime = filemtime( plugin_dir_path( __FILE__ ) . $relative_path );
		return $mtime ? self::VERSION . '.' . $mtime : self::VERSION;
	}
}

Plugin::get_instance();
