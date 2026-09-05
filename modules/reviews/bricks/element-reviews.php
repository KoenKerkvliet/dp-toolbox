<?php
/**
 * Bricks-element: de volledige reviewsectie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DP_Reviews_Bricks_Element extends \Bricks\Element {

	public $category = 'general';
	public $name     = 'dp-reviews';
	public $icon     = 'ti-star';

	public function get_label() {
		return esc_html__( 'Reviews', 'dp-toolbox' );
	}

	public function set_controls() {
		$this->controls['heading'] = [
			'tab'         => 'content',
			'label'       => esc_html__( 'Kop', 'dp-toolbox' ),
			'type'        => 'text',
			'default'     => esc_html__( 'Beoordelingen', 'dp-toolbox' ),
			'placeholder' => esc_html__( 'Beoordelingen', 'dp-toolbox' ),
		];

		$this->controls['showForm'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Formulier tonen', 'dp-toolbox' ),
			'type'    => 'checkbox',
			'inline'  => true,
			'default' => true,
		];

		$this->controls['info'] = [
			'tab'     => 'content',
			'type'    => 'info',
			'content' => esc_html__( 'Toont de beoordelingen van het product waar dit element op staat. Zet het op een productsjabloon, niet op een losse pagina.', 'dp-toolbox' ),
		];
	}

	public function render() {
		if ( ! function_exists( 'dp_reviews_render' ) ) {
			return $this->render_element_placeholder( [
				'title' => esc_html__( 'De module Reviews staat uit.', 'dp-toolbox' ),
			] );
		}

		$post_id = $this->post_id ?: get_the_ID();

		if ( ! dp_reviews_enabled_for( $post_id ) ) {
			// In de editor wél iets tonen, anders lijkt het element stuk.
			if ( ! $this->is_frontend ) {
				return $this->render_element_placeholder( [
					'title' => esc_html__( 'Reviews staan niet aan voor dit berichttype. Zet dat aan bij DP Toolbox → Modules → Reviews.', 'dp-toolbox' ),
				] );
			}
			return;
		}

		$html = dp_reviews_render( [
			'post_id' => $post_id,
			'titel'   => $this->settings['heading'] ?? esc_html__( 'Beoordelingen', 'dp-toolbox' ),
			'form'    => empty( $this->settings['showForm'] ) ? 'nee' : 'ja',
		] );

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() escapet zelf
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $html . '</div>';
	}
}
