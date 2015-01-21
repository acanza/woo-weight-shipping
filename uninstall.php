<?php
/**
* Woo Weight Shipping Uninstall
*
* Uninstalling Woo Weight Shipping deletes options, and pages.
*/
if( ! defined( 'WP_UNINSTALL_PLUGIN' ) )
exit();

//Delete plugin options from wp-options table
delete_option( 'woocommerce_increase_rates' );
delete_option( 'woocommerce_classes_increase_rates' );
delete_option( 'woocommerce_special_increase_rate' );
?>