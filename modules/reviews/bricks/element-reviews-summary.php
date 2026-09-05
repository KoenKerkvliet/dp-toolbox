<?php
/**
 * Bricks-element: de compacte sterrenregel, bedoeld onder de producttitel.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DP_Reviews_Summary_Bricks_Element extends \Bricks\Element {

	public $category = 'general';
	public $name     = 'dp-reviews-summary';
	public $icon     = 'ti-star';

	public function get_label() {
		return esc_html__( 'Reviews samenvatting', 'dp-toolbox' );
	}

	public function set_controls() {
		$this->controls['whenEmpty'] = [
			'tab'     => 'content',
			'label'   => esc_html__( 'Zonder beoordelingen', 'dp-toolbox' ),
			'type'    => 'select',
			'options' => [
				'verbergen'  => esc_html__( 'Niets tonen', 'dp-toolbox' ),
				'uitnodigen' => esc_html__( 'Uitnodigen om de eerste te schrijven', 'dp-toolbox' ),
			],
			'inline'  => true,
			'default' => 'verbergen',
		];

		$this->controls['info'] = [
			'tab'     => 'content',
			'type'    => 'info',
			'content' => esc_html__( 'Springt naar de reviewsectie op dezelfde pagina. Zet daar het element Reviews neer, anders wijst de link nergens heen.', 'dp-toolbox' ),
		];
	}

	public function render() {
		if ( ! function_exists( 'dp_reviews_summary_render' ) ) {
			return $this->render_element_placeholder( [
				'title' => esc_html__( 'De module Reviews staat uit.', 'dp-toolbox' ),
			] );
		}

		$post_id = $this->post_id ?: get_the_ID();

		if ( ! dp_reviews_enabled_for( $post_id ) ) {
			if ( ! $this->is_frontend ) {
				return $this->render_element_placeholder( [
					'title' => esc_html__( 'Reviews staan niet aan voor dit berichttype.', 'dp-toolbox' ),
				] );
			}
			return;
		}

		$html = dp_reviews_summary_render( [
			'post_id' => $post_id,
			'leeg'    => $this->settings['whenEmpty'] ?? 'verbergen',
		] );

		if ( $html === '' ) {
			if ( ! $this->is_frontend ) {
				return $this->render_element_placeholder( [
					'title' => esc_html__( 'Nog geen beoordelingen — dit element blijft op de site leeg.', 'dp-toolbox' ),
				] );
			}
			return;
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_attributes() escapet zelf
		echo '<div ' . $this->render_attributes( '_root' ) . '>' . $html . '</div>';
	}
}
