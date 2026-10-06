<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$visital_parent_template = get_template_directory() . '/templates/booking/template-booking-step-receipt.php';

if ( ! file_exists( $visital_parent_template ) ) {
	return;
}

$visital_split = function_exists( 'visital_receipt_tax_split' ) ? visital_receipt_tax_split( $args ?? [] ) : null;

if ( $visital_split && isset( $args['book_data'] ) && is_array( $args['book_data'] ) ) {
	$args['book_data']['commission_value'] = $visital_split['base'];

	ob_start();
	include $visital_parent_template;
	echo visital_receipt_inject_tax_row( ob_get_clean(), $visital_split );
} else {
	include $visital_parent_template;
}
