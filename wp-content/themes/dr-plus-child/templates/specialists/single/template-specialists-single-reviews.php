<?php

use MJ\Whitebox\Utils;

if ( ! defined( 'ABSPATH' ) ) exit;

extract( $args );

if ( Utils::to_bool( $options['single_specialist_show_reviews'] ) && comments_open() ) { ?>
	<section class="<?php echo $prefix ?>reviews" role="region" aria-label="<?php echo esc_html_e( 'User reviews', 'drplus' ) ?>">
		<?php
		if ( class_exists( 'Visital_Reviews' ) ) {
			echo Visital_Reviews::instance()->breakdown_html( get_the_ID() );
		}
		comments_template();
		?>
	</section>
<?php } ?>
