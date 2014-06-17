<?php
/*
Plugin Name: Woo Weight Shipping
Plugin URI: http://www.todoinstagram.com
Description: Woo Weight Shipping is a WooCommerce add-on which allow you setting up shipping rate depend on the weight of purchase and customer post code.
Version: 1.0
Author: Woodemia
Author URI: http://woodemia.com
License: GPL2
*/

// Derive the current path and load up WooCommerce
if(class_exists('Woocommerce') != true)
	require_once(WP_PLUGIN_DIR.'/woocommerce/woocommerce.php');

/**
 * Check if WooCommerce is active
 **/
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
   
   
	class WooWeightShipping extends WC_Shipping_Flat_Rate{

		/*
		 *	Some required plugin information
		*/
		var $version = '1.0';

	
		/*
		 *	Required __construct() function that initalizes the WooWeightShipping
		*/
		function __construct() {

			//Register plugin text domain for translations files
			load_plugin_textdomain( 'wooweightshipping', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

			$this->id = 'increase_rate';
			$this->method_title = __( 'Woo Weight Shipping', 'wooweightshipping' );
			$this->increase_rate_option = 'woocommerce_increase_rates';
			$this->special_increase_rate_option = 'woocommerce_special_increase_rate';
			
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_admin_options' ) );
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_increase_rates' ) );
			add_action( 'woocommerce_update_options_shipping_' . $this->id, array( $this, 'process_special_regions_rates' ) );
			add_filter( 'woocommerce_settings_api_sanitized_fields_' . $this->id, array( $this, 'save_default_data' ) );
			
			$this->init();
		}
		
		/*
		 *	init function
		*/
		function init() {
		
			$this->init_form_fields();
			$this->init_settings();
			
			// Define user set variables
			$this->title 		  = $this->get_option( 'title' );
			$this->availability   = $this->get_option( 'availability' );
			$this->countries 	  = $this->get_option( 'countries' );
			$this->type 		  = $this->get_option( 'type' );
			$this->tax_status	  = $this->get_option( 'tax_status' );
			$this->tax_per_kg    = $this->get_option( 'tax_per_kg' );
			$this->increase = $this->get_option( 'increase' );
			
			// Load Increase Rates
			$this->get_increase_rates();
			
			// Load Special Increase Rates
			$this->get_special_increase_rates();
		}
	
		/*
		 *	Run during the activation of the plugin
		*/
		function activate() {
		
			if ( ! current_user_can( 'activate_plugins' ) )
				return;
			$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
			check_admin_referer( "activate-plugin_{$plugin}" );
		
			# Uncomment the following line to see the function in action
			# exit( var_dump( $_GET ) );
		}
	
		/**
		 * 
		 */
		public static function deactivate()
		{
			if ( ! current_user_can( 'activate_plugins' ) )
				return;
			$plugin = isset( $_REQUEST['plugin'] ) ? $_REQUEST['plugin'] : '';
			check_admin_referer( "deactivate-plugin_{$plugin}" );
	
			# Uncomment the following line to see the function in action
			# exit( var_dump( $_GET ) );
		}
	
		
		function calculate_shipping( $package = array() ) {
			global $woocommerce;
			$total_weight = 0;
			$final_increase = 0;
			$regionID = NULL;
			
			$this->rates 		= array();

			if ( $this->type == 'order' ) {

				$shipping_total = $this->order_shipping( $package );
				
				//Get total weight of package
				$total_weight = $this->get_package_weight( $package );
				
				$regionID = $this->is_special_region( $package[ 'destination' ][ 'postcode' ] );
				
				if ( isset( $regionID ) ) {
					
					$shippingCosts = $this->special_increase_rates[ $regionID ];
					$values = $shippingCosts[ taxes ];
					
					if( $values ){
					
						// Get max weight of table
						$value = end( $values );
						$max_rate = current( $values );
						$max_weight = $max_rate[ 'weight' ];
				
						if( $total_weight > $max_weight ){
							$final_increase =  $max_rate[ 'cost' ] + ( ( $total_weight - $max_weight ) * $shippingCosts[ tax_per_kg ] );
						}else{
							$pos = 0;
							foreach( $shippingCosts[ taxes ] as $key => $rate ){
								if( $total_weight <= $rate[ 'weight' ] ){
									
									if( $pos == 0 ) $pos++;
									$final_increase = $shippingCosts[ taxes ][ $pos-1 ][ 'cost' ];
									break;
								}

								$pos++;
							}	
						}	
					}
				}else{
					$values = $this->increase_rates;
					
					if( $values ){
					
						// Get max weight of table
						ksort( $values );
						$value = end( $values );
						$max_rate = current( $values );
						$max_weight = $max_rate[ 'weight' ];
					
						if( $total_weight > $max_weight ){
							$final_increase =  $max_rate[ 'cost' ] + ( ( $total_weight - $max_weight ) * $this->tax_per_kg );
						}else{
							$count = 0;
							foreach( $this->increase_rates as $key => $rate ){
								
								if( $total_weight <= $rate[ 'weight' ] ){
									
									if( $count == 0 ) $count++;
									$increaseRate = $this->increase_rates[ $count-1 ];
									
									$final_increase = $increaseRate[ 'cost' ];
									break;
								}
								
								$count++;
							}	
						}	
					}
				}
				
				//#########################################################################
				//$this->final_increase_rate = $final_increase;
				//#########################################################################

				if ( ! is_null( $shipping_total ) )
				$rate = array(
					'id' 	=> $this->id,
					'label' => $this->title,
					'cost' 	=> $final_increase
				);

			}

				if ( isset( $rate ) )
					$this->add_rate( $rate );
		}
		
		/**
		* Calculate the total package weight
		*/
		function get_package_weight( $package = array() ){
			$total_weight = 0;

			// Add up weight of each product
			if ( sizeof( $package['contents'] ) > 0 ) {
				foreach ( $package['contents'] as $item_id => $values ) {
					if ( $values['data']->has_weight() ) {
						$products_weight = $values['data']->get_weight() * $values['quantity'];
						$total_weight = $total_weight + $products_weight;
					}
				}
			}

			return $total_weight;
		}
		
		 /**
		* Initialise Gateway Settings Form Fields
		*/
		function init_form_fields() {
			global $woocommerce;
			
			$this->form_fields = array(
			'enabled' => array(
							'title' 		=> __( 'Enable/Disable', 'woocommerce' ),
							'type' 			=> 'checkbox',
							'label' 		=> __( 'Enable this shipping method', 'woocommerce' ),
							'default' 		=> 'no'
						),
			'title' => array(
							'title' 		=> __( 'Method Title', 'woocommerce' ),
							'type' 			=> 'text',
							'description' 	=> __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
							'default'		=> __( 'Increase Delivery', 'woocommerce' ),
							'desc_tip'      => true,
						),
			'availability' => array(
							'title' 		=> __( 'Availability', 'woocommerce' ),
							'type' 			=> 'select',
							'description' 	=> '',
							'default' 		=> 'including',
							'options' 		=> array(
								'including' 	=> __( 'Selected countries', 'woocommerce' ),
								'excluding' 	=> __( 'Excluding selected countries', 'woocommerce' ),
							)
						),
			'countries' => array(
							'title' 		=> __( 'Countries', 'woocommerce' ),
							'type' 			=> 'multiselect',
							'class'			=> 'chosen_select',
							'css'			=> 'width: 450px;',
							'default' 		=> '',
							'options'		=> $woocommerce->countries->countries
						),
			'tax_status' => array(
							'title' 		=> __( 'Tax Status', 'woocommerce' ),
							'type' 			=> 'select',
							'default' 		=> 'taxable',
							'options'		=> array(
								'taxable' 	=> __( 'Taxable', 'woocommerce' ),
								'none' 		=> __( 'None', 'woocommerce' )
							)
						),
			'type' => array(
							'title' 		=> __( 'Cost Added...', 'woocommerce' ),
							'type' 			=> 'select',
							'default' 		=> 'order',
							'options' 		=> array(
								'order' 	=> __( 'Per Order - charge shipping for the entire order as a whole', 'woocommerce' )
							),
						),
			'tax_per_kg' => array(
							'title' 		=> __( 'Cost per additional Kg', 'wooweightshipping' ),
							'type' 			=> 'number',
							'custom_attributes' => array(
								'step'	=> 'any',
								'min'	=> '0'
							),
							'description'	=> __( 'Adding a tax per Kg, when the order is above max weight.', 'wooweightshipping' ),
							'default' 		=> '',
							'desc_tip'      => true,
							'placeholder'	=> '0.00'
						),
			'table_of_costs' => array(
							'title'         => __( 'Main shipping table rates', 'wooweightshipping' ),
							'type'          => 'title',
							'description'   => __( 'Delivery costs depending on the order weight.', 'wooweightshipping' )
						),
			'delivery_costs_table' => array(
							'type'          => 'delivery_costs_table'
						),
			'special_rate' => array(
							'title'         => __( 'Special shipping rates', 'wooweightshipping' ),
							'type'          => 'title',
							'description'   => __( 'Special shipping rates for some regions depending on the post codes.', 'wooweightshipping' )
					       ),
			'delivery_special_rate_table' => array(
							'type'		=> 'delivery_special_rate_table'
							)
			);
		} // End init_form_fields()
		
		/**
		* generate_delivery_costs_table_html function.
		*
		* @access public
		* @return void
		*/
		function generate_delivery_costs_table_html() {
			global $woocommerce;
			ob_start();
			?>
			<tr valign="top">
			<th scope="row" class="titledesc"><?php _e( 'Costs', 'wooweightshipping' ); ?>:</th>
			<td class="forminp" id="<?php echo $this->id; ?>_flat_rates">
					<table class="shippingrows widefat" cellspacing="0">
						<thead>
							<tr>
								<th class="check-column"><input type="checkbox"></th>
								<th class="shipping_class"><?php _e( 'From [X] Kg', 'wooweightshipping' ); ?></th>
							<th><?php _e( 'Cost', 'wooweightshipping' ); ?> <a class="tips" data-tip="<?php _e( 'Cost, excluding tax.', 'wooweightshipping' ); ?>">[?]</a></th>
							</tr>
						</thead>
						<tfoot>
							<tr>
								<th colspan="4"><a href="#" class="add button"><?php _e( 'Add Cost', 'wooweightshipping' ); ?></a> <a href="#" class="remove button"><?php _e( 'Delete selected costs', 'wooweightshipping' ); ?></a></th>
							</tr>
						</tfoot>
						<tbody class="flat_rates">
							<tr>
								<td></td>
								<td class="flat_rate_class"><?php _e( 'Any weight', 'wooweightshipping' ); ?></td>
								<td><input type="number" step="any" min="0" value="<?php echo esc_attr( $this->cost ); ?>" name="default_cost" placeholder="<?php _e( 'N/A', 'wooweightshipping' ); ?>" size="4" /></td>
							</tr>
						<?php
						$i = -1;
						if ( $this->increase_rates ) {
							foreach ( $this->increase_rates as $class => $rate ) {
								$i++;
		
								echo '<tr class="flat_rate">
									<th class="check-column"><input type="checkbox" name="select" /></th>
									<td><input type="number" step="1" min="0" value="' . esc_attr( $rate['weight'] ) . '" name="' . esc_attr( $this->id .'_weight[' . $i . ']' ) . '" placeholder="'.__( '0', 'wooweightshipping' ).'" size="4" /></td>
									<td><input type="number" step="any" min="0" value="' . esc_attr( $rate['cost'] ) . '" name="' . esc_attr( $this->id .'_cost[' . $i . ']' ) . '" placeholder="'.__( '0.00', 'wooweightshipping' ).'" size="4" /></td>
									</tr>';
							}
						}
						?>
						</tbody>
					</table>		
					<script type="text/javascript">
							jQuery(function() {
	
								jQuery('#<?php echo $this->id; ?>_flat_rates').on( 'click', 'a.add', function(){

									var size = jQuery('#<?php echo $this->id; ?>_flat_rates tbody .flat_rate').size();

									jQuery('<tr class="flat_rate">\
										<th class="check-column"><input type="checkbox" name="select" /></th>\<td><input type="number" step="any" min="0" name="<?php echo $this->id; ?>_weight[' + size + ']" placeholder="0.00" size="4" /></td>\
										<td><input type="number" step="any" min="0" name="<?php echo $this->id; ?>_cost[' + size + ']" placeholder="0.00" size="4" /></td>\
										</tr>').appendTo('#<?php echo $this->id; ?>_flat_rates table tbody');
	
									return false;
								});

								// Remove row
								jQuery('#<?php echo $this->id; ?>_flat_rates').on( 'click', 'a.remove', function(){
									var answer = confirm("<?php _e( 'Delete the selected rates?', 'wooweightshipping' ); ?>")
									if (answer) {
										jQuery('#<?php echo $this->id; ?>_flat_rates table tbody tr th.check-column input:checked').each(function(i, el){
											jQuery(el).closest('tr').remove();
										});
									}
									return false;
								});
		
							});
						</script>
			</td>
			</tr>
			<?php
			return ob_get_clean();
		}
		
		/**
		* generate_delivery_special_rate_table_html function.
		*
		* @access public
		* @return void
		*/
		function generate_delivery_special_rate_table_html() {
			global $woocommerce;
					
						//####################################################################
						//ob_start();
						//var_dump( $this->special_increase_rates );
						//$structure = ob_get_contents();
						//ob_end_clean();
						//####################################################################
			
			ob_start();
			?>
			<table id="special_rate_table" class="form-table">
				<tbody class="regions_list">
					<?php
						$i = -1;
						if ( $this->special_increase_rates ) {
							foreach ( $this->special_increase_rates as $region => $data ) {
								$i++;
		
								echo '<tr valign="top" class="special_region">
									<th scope="row" class="titledesc" style="font-size: 12pt; font-weight: bold; color: #77ab48;"><input type="checkbox" name="select" />  '. $data[region_name] .'</th>
									<td class="forminp">
										<table>
											<tbody>
												<tr valign="top">
													<td class="forminp">
														<fieldset>
															<legend><span>'. __( 'Posts Codes', 'wooweightshipping' ) .'</span></legend>
															<textarea rows="3" cols="50" class="input-text wide-input " placeholder="12345, 56789 etc" name="'. $this->id. '_regions['. $i .'][posts_codes]" id="woocommerce_region_'. $i .'_delivery_codes" style="">'. $data[posts_codes] .'</textarea><br>
														</fieldset>
													</td>
												</tr>
												<tr valign="top">
													<td class="forminp">
														<fieldset>
															<legend><span>'. __( 'Cost per additional Kg', 'wooweightshipping' ) .'</span></legend>
															<input type="number" step="any" min="0" value="' . esc_attr( $data[ tax_per_kg ] ) . '" name="'. $this->id .'_regions['. $i .'][tax_per_kg]" placeholder="0.00" size="4" /></td><br>
														</fieldset>
													</td>
												</tr>
												<tr>
													<td class="forminp">
														<table id="'. $this->id. '_region_'. $i .'" class="shippingrows widefat" cellspacing="0">
															<thead>
																<tr>
																	<th class="check-column"><input type="checkbox"></th>
																	<th class="shipping_class">'. __( 'From [X] Kg', 'wooweightshipping' ) .'</th>
																	<th>'. __( "Cost", "woocommerce" ) .'<a class="tips" data-tip="'. __( "Cost, excluding tax.", "woocommerce" ) .'">[&euro;]</a></th>
																</tr>
															</thead>
															<tfoot>
																<tr>
																	<th colspan="4"><a href="#" class="add button" onclick="add_new_cost_row('. $i .'); return false;" id="'. $this->id. '_region_'. $i .'_add_button">'. __( 'Add Cost', 'wooweightshipping' ) .'</a> <a href="#" class="remove button" onclick="remove_cost_row('. $i .'); return false;">'. __( 'Delete selected costs', 'wooweightshipping' ) .'</a></th>
																</tr>
															</tfoot>
															<tbody class="price_table">';
								$j = -1;
								if( $data[taxes] ){
									foreach ( $data[taxes] as $key => $value ) {
										$j++;
		
										echo '<tr class="flat_rate">
											<th class="check-column"><input type="checkbox" name="select" /></th>
											<td><input type="number" step="1" min="0" value="' . esc_attr( $value[ weight ] ) . '" name="' . esc_attr( $this->id .'_regions['. $i .'][weight]['. $j .']' ) . '" placeholder="'.__( '0', 'wooweightshipping' ).'" size="4" /></td>
											<td><input type="number" step="any" min="0" value="' . esc_attr( $value[ cost ] ) . '" name="' . esc_attr( $this->id .'_regions['. $i .'][cost]['. $j .']' ) . '" placeholder="'.__( '0.00', 'wooweightshipping' ).'" size="4" /></td>
											</tr>';
									}					
								}
								echo '</tbody>
									</table>
									</td>
									</tr>
									</tbody>
									</table>
										<input type="hidden" name= "'. $this->id .'_regions['. $i .'][region_name]" value="'. $data[region_name] .'" />
									</td>
								</tr>';
							}
						}
					?>
				</tbody>
				<tfoot>
					<tr>
						<th colspan="4"><a href="#" class="add button"><?php _e( '+ Add Region', 'wooweightshipping' ); ?></a> <a href="#" class="remove button" id="remove_region_button"><?php _e( ' Delete Selected Regions', 'wooweightshipping' ); ?></a></th>
					</tr>
					
					<!-- ####################################### DEBUG ############################################ -->
					<!-- <tr>
						<th colspan="4"><textarea rows="15" cols="40" class="input-text wide-input " placeholder="12345, 56789 etc" name="array_structure" id="show_array_structure"><?php echo $structure; ?></textarea></th>
					</tr> -->
					<!-- ################################################################################### -->
					
				</tfoot>
			</table>
			
					<script type="text/javascript">
							jQuery(function() {
	
								// Adding Regions Row
								jQuery('#special_rate_table > tfoot').on( 'click', 'a.add', function(){

									var size = jQuery('#special_rate_table tbody .special_region').size();

									jQuery('<tr valign="top" class="special_region">\
										<th scope="row" class="titledesc"><input type="checkbox" name="select" /><?php echo __( ' Region ', 'wooweightshipping' ); ?>' + size + '</th>\
										<td class="forminp">\
											<table>\
												<tbody>\
													<tr valign="top">\
														<td class="forminp">\
															<fieldset>\
																<legend><span><?php echo __( 'Name of Region ', 'wooweightshipping' ); ?></span></legend>\
																<input type="text" name="<?php echo $this->id; ?>_regions[' + size + '][region_name]" placeholder="<?php echo __( 'Canarias', 'wocommerce' ); ?>" size="20" /><br>\
															</fieldset>\
														</td>\
													</tr>\
													<tr valign="top">\
														<td class="forminp">\
															<fieldset>\
																<legend><span><?php echo __( 'Posts Codes ', 'wooweightshipping' ); ?></span></legend>\
																<textarea rows="3" cols="50" class="input-text wide-input " placeholder="12345, 56789 etc" name="<?php echo $this->id; ?>_regions[' + size + '][posts_codes]" id="woocommerce_special_region_delivery_codes" style=""></textarea><br>\
															</fieldset>\
														</td>\
													</tr>\
													<tr valign="top">\
														<td class="forminp">\
															<fieldset>\
																<legend><span><?php echo __( 'Cost per additional Kg ', 'wooweightshipping' ); ?></span></legend>\
																<input type="number" step="any" min="0" name="<?php echo $this->id; ?>_regions[' + size + '][tax_per_kg]" placeholder="0.00" size="4" /><br>\
															</fieldset>\
														</td>\
													<tr>\
													<tr valign="top">\
														<td class="forminp">\
															<table class="shippingrows widefat" cellspacing="0">\
																<thead>\
																	<tr>\
																		<th class="shipping_class"><?php echo __( 'From [X] Kg', 'wooweightshipping' ); ?></th>\
																		<th><?php echo __( 'Cost', 'wooweightshipping' ); ?> <a class="tips" data-tip="<?php echo __( 'Cost, excluding tax.', 'wooweightshipping' ); ?>">[&euro;]</a></th>\
																	</tr>\
																</thead>\
																<tfoot>\
																	<tr>\
																		<th colspan="4"><a href="#" class="add button" onclick="add_new_cost_row(' + size + '); return false;" id="<?php echo $this->id; ?>_region_' + size + '_add_button"><?php echo __( '+ Add Cost', 'wooweightshipping' ); ?></a> <a href="#" class="remove button" onclick="remove_cost_row(' + size + '); return false;"><?php echo __( 'Delete selected costs', 'wooweightshipping' ); ?></a></th>\
																	</tr>\
																</tfoot>\
																<tbody class="flat_rates">\
																</tbody>\
															</table>\
														</td>\
													</tr>\
												</tbody>\
											</table>\
										</td>\
										</tr>').appendTo('#special_rate_table tbody.regions_list');
	
									return false;
								});
								
								// Remove Regions Row
								jQuery('#special_rate_table tfoot').on( 'click', 'a#remove_region_button', function(){
									var answer = confirm("<?php _e( 'Are you sure to delete the selected regions?', 'wooweightshipping' ); ?>")
									if (answer) {
										jQuery('#special_rate_table tbody.regions_list tr th input:checked').each(function(i, el){
											jQuery(el).closest('tr').remove();
										});
									}
									return false;
								});
								
							});
							
							//Adding price per weight row
							function add_new_cost_row( idRegion ) {
							
								var size = jQuery('#special_rate_table tbody table#increase_rate_region_' + idRegion +' tbody .flat_rate').size();
							
								jQuery('<tr class="flat_rate">\
									<th class="check-column"><input type="checkbox" name="select" /></th>\<td><input type="number" step="any" min="0" name="<?php echo $this->id; ?>_regions[' + idRegion + '][weight][' + size + ']" placeholder="0.00" size="4" /></td>\
									<td><input type="number" step="any" min="0" name="<?php echo $this->id; ?>_regions[' + idRegion + '][cost][' + size + ']" placeholder="0.00" size="4" /></td>\
									</tr>').appendTo('#special_rate_table tbody table#increase_rate_region_' + idRegion +' tbody');
							
								return false;
							}
							
							// Remove price per weight row
							function remove_cost_row( idRegion ) {
								var answer = confirm("<?php _e( 'Delete the selected rates?', 'wooweightshipping' ); ?>")
								if (answer) {
									jQuery('#special_rate_table tbody table#increase_rate_region_' + idRegion +' tbody tr th.check-column input:checked').each(function(i, el){
										jQuery(el).closest('tr').remove();
									});
								}
								return false;
							}
							
						</script>
			<?php
			return ob_get_clean();
		}
		
		/**
		* process_increase_rates function.
		*
		* @access public
		* @return void
		*/
		function process_increase_rates() {
			// Save the rates
			$order_weight = array();
			$increase_cost = array();
			$increase_rates = array();
		
			if ( isset( $_POST[ $this->id . '_weight'] ) )  $order_weight  = array_map( 'woocommerce_clean', $_POST[ $this->id . '_weight'] );
			if ( isset( $_POST[ $this->id . '_cost'] ) )   $increase_cost   = array_map( 'woocommerce_clean', $_POST[ $this->id . '_cost'] );

			// Get max key
			$values = $order_weight;
			ksort( $values );
			$value = end( $values );
			$key = key( $values );
		
			for ( $i = 0; $i <= $key; $i++ ) {
				if ( isset( $order_weight[ $i ] ) && isset( $increase_cost[ $i ] ) ) {
	
					$increase_cost[ $i ] = number_format($increase_cost[ $i ], 2,  '.', '');
		
					// Add to increae rates array
					$increase_rates[ $i ] = array(
						'weight' => $order_weight[ $i ],
						'cost'  => $increase_cost[ $i ],
					);
				}
			}
	
			update_option( $this->increase_rate_option, $increase_rates );
	
			$this->get_increase_rates();
		}
		
		/**
		* process_special_regions_rates function.
		*
		* @access public
		* @return void
		*/
		function process_special_regions_rates() {
			//Save the rates
			$regions_list = array();
			$new_regions_list = array();
			
			if( isset( $_POST[ $this->id . '_regions'] ) ){
				
				$regions_list = $_POST[ $this->id . '_regions'];
				foreach ( $regions_list as $region => $data ){
					
					$new_regions_list[ $region ] = array( 'region_name' => $data[region_name], 'posts_codes' => '', 'tax_per_kg' => $data[tax_per_kg], 'taxes' => '' );
					
					//Checking if there are ranges of zip code and then generate the list of zip codes
					$postsCodes = $this->generate_posts_codes_list_by_range( $data[posts_codes] );
					$new_regions_list[ $region ][ posts_codes ] = $postsCodes;
					
					$max = count( $data[weight] );
					
					$cont = 0;
					for($i=0; $i<=$max; $i++){
						
						if( isset( $data[cost][$i] ) & ( $data[cost][$i] > 0 ) ){
							$new_regions_list[ $region ][taxes][ $cont ] = array( 'weight' => $data[weight][$i], 'cost' => $data[cost][$i] );
							$cont++;
						}
					}
				}
			}
			
			$special_increase_rates = $new_regions_list;
			
			update_option( $this->special_increase_rate_option, $special_increase_rates );
			
			$this->get_special_increase_rates();
		}
		
		/**
		* is_special_region function.
		*
		* @access public
		* @param mixed $values
		* @return void
		*/
		function is_special_region( $postcode = null ) {
			$regionID = NULL;
					
			if ( isset( $postcode ) ) {
				
				foreach( $this->special_increase_rates as $region => $data ){
					
					if( isset( $regionID ) ){
						break;
					}else{
						$postsCodes = explode( ',', $data[posts_codes] );
						foreach( $postsCodes as $key => $value ){
						
							if( strcmp( $postcode, trim( $value ) ) === 0 ){
								$regionID = $region;
								break;
							}
						}
						
					}
				}
			}
			
			return $regionID;
		}
		
		/**
		* generate_posts_codes_list_by_range function.
		*
		* @access public
		* @param mixed $values
		* @return void
		*/
		function generate_posts_codes_list_by_range( $postsCodes = null ){
			$postCodeList = array();
			$finalPostCodeList = array();
			
			
			if( isset( $postsCodes ) && ( strpos( $postsCodes, '-' ) === false) ){
				
				return $postsCodes;
			}else{
				$postCodeList = explode( ',', $postsCodes );
				foreach( $postCodeList as $key => $value ){
					
					if( strpos( trim( $value ), '-' ) ){
						$postCodeRange = explode( '-', $value );
						$firstPostCode = $postCodeRange[0];
						$secondPostCode = $postCodeRange[1];
						
						if( $firstPostCode > $secondPostCode ){
							$postCodeRange = range( $secondPostCode, $firstPostCode );
						}else{
							$postCodeRange = range( $firstPostCode, $secondPostCode );
						}
						
						$finalPostCodeList = array_merge( $finalPostCodeList, $postCodeRange );
					}else{
					
						array_push( $finalPostCodeList, $value );
					}
				}
				
				$finalPostCodeList = implode( ',', $finalPostCodeList );
				return $finalPostCodeList;
			}
		}
		
		/**
		* save_default_data function.
		*
		* @access public
		* @param mixed $values
		* @return void
		*/
		function save_default_data( $fields ) {
		 	$default_cost = woocommerce_clean( $_POST['default_cost'] );

			$fields['cost']  = $default_cost;

			return $fields;
		}
		
		/**
		* get_increase_rates function.
		*
		* @access public
		* @return void
		*/
		function get_increase_rates() {
			$this->increase_rates = array_filter( (array) get_option( $this->increase_rate_option ) );
		}
		
		/**
		* get_special_increase_rates function.
		*
		* @access public
		* @return void
		*/
		function get_special_increase_rates() {
			$this->special_increase_rates = array_filter( (array) get_option( $this->special_increase_rate_option ) );
		}
		
		/**
		* is_available function.
		*
		* @access public
		* @param mixed $package
		* @return bool
		*/
		function is_available( $package ) {
			global $woocommerce;

			if ($this->enabled=="no") return false;

				if ($this->availability=='including') :

					if (is_array($this->countries)) :
						if ( ! in_array( $package['destination']['country'], $this->countries) ) return false;
					endif;

				else :

					if (is_array($this->countries)) :
						if ( in_array( $package['destination']['country'], $this->countries) ) return false;
					endif;

				endif;

			return apply_filters( 'woocommerce_shipping_' . $this->id . '_is_available', true );
		}
	}
	

	/**
	* Inicializamos plugin
	* 
	**/
	if (class_exists('WooWeightShipping')){
		// Initialize the your plugin
		$WooWeightShipping = new WooWeightShipping();

		if (isset($WooWeightShipping))
		{
			// Add an activation hook
			register_activation_hook( __FILE__, array( $WooWeightShipping, 'activate' ) );
			register_deactivation_hook( __FILE__, array( $WooWeightShipping, 'deactivate' ) );
		}
		
		function add_increase_rate_method( $methods ) {
			$methods[] = 'WooWeightShipping'; return $methods;
		}

		add_filter('woocommerce_shipping_methods', 'add_increase_rate_method' );
	}
   
}


?>