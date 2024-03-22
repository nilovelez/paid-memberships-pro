<?php
/**
 * Render the Membership Cancel block on the frontend.
 */
$output = pmpro_loadTemplate( 'cancel', 'local', 'pages' );
?>
<div <?php echo get_block_wrapper_attributes(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>>
	<?php echo wp_kses_post( $output ); ?>
</div>
