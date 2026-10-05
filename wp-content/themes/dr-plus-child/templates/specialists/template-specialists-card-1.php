<?php

use DrPlus\Utils;
use DrPlus\Utils\Booking;
use DrPlus\Utils\Options;
use DrPlus\Utils\SubscriptionPlans;
use DrPlus\Utils\UtilsSpecialists;

if( !defined( 'ABSPATH' ) ) exit;

if( empty( $args['specialist'] ) || empty( $args['mode'] ) ) return;

$specialist = $args['specialist'];
$mode = Utils::ensure_values_in_array( sanitize_text_field( $args['mode'] ), ['offline_visits', 'online_visits'] );
if( !$mode ) return;
$options = Options::get_options( [
	'offline_reserve_time_text'	=> esc_html__( 'Book an appointment', 'drplus' ),
	'online_reserve_time_text'	=> esc_html__( 'Request Consultation', 'drplus' ),
	'view_specialist_btn_text'	=> esc_html__( 'View Specialist', 'drplus' ),
] );

$args = Utils::check_default( $args, [
	'name-tag'		=> 'h2',
	'short_bio-tag'	=> 'div',
	'verified-text'	=> '',
] );

// Check for subscription plan
if( !SubscriptionPlans::is_specialist_plan_active( $specialist->user_id ) ) {
	$mode = 'view_only';
} else {
	if( $mode == 'online_visits' && !Utils::to_bool( $specialist->online_visit ) || $mode == 'offline_visits' && !Utils::to_bool( $specialist->offline_visit ) ) $mode = 'view_only';
}

$page_link = UtilsSpecialists::get_page_link( $specialist );

// Retrieve avatar: 1. check ahura_avatar_url, 2. fallback to get_avatar
$avatar_url = get_post_meta( $specialist->post_id, 'ahura_avatar_url', true );
if ( ! empty( $avatar_url ) ) {
    $avatar_html = '<img src="' . esc_url( $avatar_url ) . '" class="avatar avatar-90 photo" width="90" height="90" alt="' . esc_attr( $specialist->display_name ) . '" loading="lazy" />';
} else {
    $avatar_html = get_avatar( $specialist->user_id, 90, '', $specialist->display_name );
}
?>

<div class="specialist-avatar-wrap">
	<a href="<?php echo $page_link ?>" title="<?php echo esc_attr( $specialist->display_name ) ?>"><?php echo $avatar_html; ?></a>
</div>
<div class="specialist-name-wrap">
	<<?php echo tag_escape( $args['name-tag'] ) ?> class="specialist-name line-clamp line-clamp-1">
		<a href="<?php echo $page_link ?>" title="<?php echo esc_attr( $specialist->display_name ) ?>"><?php echo esc_html( $specialist->display_name ) ?></a>
	</<?php echo tag_escape( $args['name-tag'] ) ?>>

	<?php if( Utils::to_bool( $specialist->subtitle ) ) { ?>
		<<?php echo tag_escape( $args['short_bio-tag'] ) ?> class="specialist-short_bio line-clamp line-clamp-1">
			<a href="<?php echo $page_link ?>" title="<?php echo esc_attr( $specialist->display_name ) ?>"><?php echo esc_html( $specialist->subtitle ) ?></a>
		</<?php echo tag_escape( $args['short_bio-tag'] ) ?>>
	<?php } ?>

	<?php if( Utils::to_bool( $specialist->is_verified ) && !empty( $args['verified-text'] ) ) { ?>
		<div class="specialist-is-verified">
			<i class="drplus-icon-verify-fill"></i>
			<span class="specialist-is-verified-text"><?php echo esc_html( $args['verified-text'] ) ?></span>
		</div>
	<?php } ?>
</div>
<div class="specialist-meta-wrap">
	<?php get_template_part( "templates/specialists/template-specialists-meta", $mode, [
		'specialist'	=> $specialist,
		'page_link'		=> $page_link,
	] ); ?>
</div>
<?php
if ( function_exists( 'visital_next_slot_badge' ) ) {
	visital_next_slot_badge( $specialist );
}

$button_text = $options['view_specialist_btn_text'];
$button_link = $page_link;
get_template_part( "templates/components/template-components-button", null, [
	'icon'			=> is_rtl() ? 'drplus-icon-arrow-up-left-square' : 'drplus-icon-arrow-up-right-square',
	'text'			=> $button_text,
	'link'			=> $button_link,
	'icon_align'	=> 'end',
	'align'			=> 'center',
	'classes'		=> ['specialist-btn'],
	'fullwidth'		=> true,
	'small'			=> true,
] );
