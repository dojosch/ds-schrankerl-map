<?php
/**
 * Plugin Name: devsolution Click Maps
 * Plugin URI: https://devsolution.at
 * Description: Interactive click maps for WordPress using SVG and fslightbox.
 * Version: 1.0.0
 * Author: devsolution
 * Text Domain: devsolution-click-maps
 */

namespace ClickMaps;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {
	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', [ $this, 'register_cpt' ] );
		add_filter( 'use_block_editor_for_post_type', [ $this, 'disable_gutenberg' ], 10, 2 );
		add_action( 'add_meta_boxes', [ $this, 'add_meta_box' ] );
		add_action( 'save_post', [ $this, 'save_meta_box' ], 10, 2 );
		add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_frontend_assets' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin_assets' ] );
		add_shortcode( 'click_map', [ $this, 'render_shortcode' ] );
	}

	public function register_cpt() {
		$labels = [
			'name'               => 'Click Maps',
			'singular_name'      => 'Click Map',
			'menu_name'          => 'Click Maps',
			'add_new'            => 'Add New',
			'add_new_item'       => 'Add New Click Map',
			'edit_item'          => 'Edit Click Map',
			'new_item'           => 'New Click Map',
			'view_item'          => 'View Click Map',
			'search_items'       => 'Search Click Maps',
			'not_found'          => 'No Click Maps found',
			'not_found_in_trash' => 'No Click Maps found in Trash',
		];

		$args = [
			'labels'              => $labels,
			'public'              => false, // We don't need a single page on the frontend
			'show_ui'             => true,
			'show_in_menu'        => true,
			'menu_icon'           => 'dashicons-location',
			'capability_type'     => 'post',
			'supports'            => [ 'title', 'thumbnail' ], // Base Image uses Thumbnail
			'show_in_rest'        => false, // Disabled to enforce classic editor interface
		];

		register_post_type( 'click_map', $args );
	}

	public function disable_gutenberg( $current_status, $post_type ) {
		if ( 'click_map' === $post_type ) {
			return false;
		}
		return $current_status;
	}

	public function add_meta_box() {
		add_meta_box(
			'click_map_editor_box',
			'Click Map Editor',
			[ $this, 'render_meta_box' ],
			'click_map',
			'normal',
			'high'
		);
	}

	public function render_meta_box( $post ) {
		wp_nonce_field( 'click_map_save_data', 'click_map_nonce' );

		$data = get_post_meta( $post->ID, '_click_map_data', true );
		if ( empty( $data ) ) {
			$data = '[]';
		}

		$thumbnail_id = get_post_thumbnail_id( $post->ID );
		if ( ! $thumbnail_id ) {
			echo '<p><strong>' . esc_html__( 'Please set a Featured Image first and update the post.', 'devsolution-click-maps' ) . '</strong></p>';
			return;
		}

		$bg_color = get_post_meta( $post->ID, '_click_map_bg_color', true );

		$img_src = wp_get_attachment_image_url( $thumbnail_id, 'full' );
		?>
		<div id="click-map-admin-container">
			<div class="click-map-canvas-wrapper" style="position: relative; display: inline-block;">
				<img id="click-map-base-image" src="<?php echo esc_url( $img_src ); ?>" alt="Base Image" style="max-width: 100%; display: block;" />
				<svg id="click-map-svg" style="position: absolute; top:0; left:0; width:100%; height:100%;" viewBox="0 0 100 100" preserveAspectRatio="none">
					<!-- Existing maps will be rendered here via JS -->
				</svg>
			</div>

			<div class="click-map-controls">
				<div style="margin-bottom: 20px;">
					<label for="_click_map_bg_color"><strong><?php esc_html_e( 'Hover Background (HEX):', 'devsolution-click-maps' ); ?></strong></label><br>
					<input type="text" id="_click_map_bg_color" name="_click_map_bg_color" value="<?php echo esc_attr( $bg_color ); ?>" placeholder="#0064ff" style="width: 100%; margin-top: 5px;" />
					<p class="description"><?php esc_html_e( 'Applies to all maps on this image. Example: #ff0000', 'devsolution-click-maps' ); ?></p>
				</div>

				<h3><?php esc_html_e( 'Saved Maps', 'devsolution-click-maps' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Click on the image to draw a polygon. Double-click or click "Finish Shape" to complete it. Then assign a gallery.', 'devsolution-click-maps' ); ?></p>
				<button type="button" class="button" id="click-map-finish-shape" disabled><?php esc_html_e( 'Finish Current Shape', 'devsolution-click-maps' ); ?></button>
				<button type="button" class="button button-link-delete" id="click-map-cancel-shape" disabled style="color: #a00;"><?php esc_html_e( 'Cancel Shape', 'devsolution-click-maps' ); ?></button>
				
				<ul id="click-map-list"></ul>
			</div>

			<input type="hidden" id="_click_map_data" name="_click_map_data" value="<?php echo esc_attr( $data ); ?>" />
		</div>
		<?php
	}

	public function save_meta_box( $post_id, $post ) {
		if ( ! isset( $_POST['click_map_nonce'] ) || ! wp_verify_nonce( $_POST['click_map_nonce'], 'click_map_save_data' ) ) {
			return;
		}

		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['_click_map_data'] ) ) {
			// Sanitize JSON by decoding and re-encoding after sanitization
			$raw_data = wp_unslash( $_POST['_click_map_data'] );
			$decoded  = json_decode( $raw_data, true );
			
			if ( is_array( $decoded ) ) {
				$sanitized_data = [];
				foreach ( $decoded as $map ) {
					$clean_map = [
						'id'        => isset( $map['id'] ) ? sanitize_text_field( $map['id'] ) : '',
						'points'    => isset( $map['points'] ) ? wp_kses_post( $map['points'] ) : '', // Allows comma separated coordinates
						'gallery'   => [],
					];
					if ( isset( $map['gallery'] ) && is_array( $map['gallery'] ) ) {
						foreach ( $map['gallery'] as $attachment ) {
							$clean_map['gallery'][] = [
								'id'  => absint( $attachment['id'] ),
								'url' => esc_url_raw( $attachment['url'] )
							];
						}
					}
					$sanitized_data[] = $clean_map;
				}
				update_post_meta( $post_id, '_click_map_data', wp_json_encode( $sanitized_data ) );
			}
		}

		if ( isset( $_POST['_click_map_bg_color'] ) ) {
			update_post_meta( $post_id, '_click_map_bg_color', sanitize_text_field( $_POST['_click_map_bg_color'] ) );
		}
	}

	public function enqueue_admin_assets( $hook ) {
		global $post_type;
		if ( 'click_map' !== $post_type ) {
			return;
		}

		wp_enqueue_media();

		wp_enqueue_style(
			'click-maps-admin',
			plugin_dir_url( __FILE__ ) . 'src/admin/admin.css',
			[],
			'1.0.0'
		);

		wp_enqueue_script(
			'click-maps-admin',
			plugin_dir_url( __FILE__ ) . 'src/admin/admin.js',
			[],
			'1.0.0',
			true
		);
	}

	public function enqueue_frontend_assets() {
		// Only enqueue if shortcode is used or globally
		// We'll register them here, and enqueue in shortcode
		wp_register_style(
			'click-maps-frontend',
			plugin_dir_url( __FILE__ ) . 'src/frontend/frontend.css',
			[],
			'1.0.0'
		);

		wp_register_script(
			'fslightbox',
			plugin_dir_url( __FILE__ ) . 'vendor/fslightbox.js',
			[],
			'3.4.1',
			true
		);
	}

	public function render_shortcode( $atts ) {
		$atts = shortcode_atts( [ 'id' => '' ], $atts, 'click_map' );
		$post_id = intval( $atts['id'] );

		if ( ! $post_id || 'click_map' !== get_post_type( $post_id ) ) {
			return '';
		}

		$thumbnail_id = get_post_thumbnail_id( $post_id );
		if ( ! $thumbnail_id ) {
			return '';
		}
		
		$img_src = wp_get_attachment_image_url( $thumbnail_id, 'full' );
		if ( ! $img_src ) {
			return '';
		}

		$data = get_post_meta( $post_id, '_click_map_data', true );
		$maps = $data ? json_decode( $data, true ) : [];

		$bg_color = get_post_meta( $post_id, '_click_map_bg_color', true );
		$bg_style = '';
		if ( ! empty( $bg_color ) ) {
			$safe_color = esc_attr( $bg_color );
			if ( preg_match('/^#[a-f0-9]{6}$/i', $safe_color) ) {
				$hover_color = $safe_color . '80'; // 50% hover opacity
			} else {
				$hover_color = $safe_color;
			}
			$bg_style = '--hover-bg: ' . $hover_color . ';';
		}

		wp_enqueue_style( 'click-maps-frontend' );
		wp_enqueue_script( 'fslightbox' );

		ob_start();
		?>
		<div class="devsolution-click-map" style="position: relative; max-width: 100%; <?php echo $bg_style; ?>">
			<img src="<?php echo esc_url( $img_src ); ?>" alt="<?php echo esc_attr( get_the_title( $post_id ) ); ?>" style="width: 100%; display: block;" />
			<svg style="position: absolute; top:0; left:0; width:100%; height:100%;" viewBox="0 0 100 100" preserveAspectRatio="none">
				<?php 
				$hidden_links = '';
				foreach ( $maps as $map ) : 
					if ( empty( $map['points'] ) || empty( $map['gallery'] ) ) continue;
					
					$first_img_url = esc_url( $map['gallery'][0]['url'] );
					$gallery_id    = 'clickmap-' . esc_attr( $post_id ) . '-' . esc_attr( $map['id'] );
					$points        = esc_attr( $map['points'] );
					
					?>
					<a data-fslightbox="<?php echo $gallery_id; ?>" href="<?php echo $first_img_url; ?>">
						<polygon points="<?php echo $points; ?>" class="click-map-poly" stroke="rgba(255,255,255,0.8)" stroke-width="0.5" fill="transparent" />
					</a>
					<?php
					
					// Store hidden links for other images
					if ( count( $map['gallery'] ) > 1 ) {
						for ( $i = 1; $i < count( $map['gallery'] ); $i++ ) {
							$hidden_links .= sprintf(
								'<a data-fslightbox="%s" href="%s"></a>',
								$gallery_id,
								esc_url( $map['gallery'][$i]['url'] )
							);
						}
					}
				endforeach; 
				?>
			</svg>
			<div class="click-map-hidden-gallery" style="display: none;">
				<?php echo $hidden_links; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}
}

Plugin::get_instance();
