<?php
/*
Plugin Name: Woo Referral Rewards System
Description: Custom referral system where users get a unique random code. Friends get a discount, and the referrer gets a reward coupon. Settings are dynamic.
Version: 1.1
Author: NGRID
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

/**
 * ==============================================================
 * PART 1: ADMIN SETTINGS (Make Amounts Dynamic)
 * ==============================================================
 */

// 1. Add Submenu under WooCommerce
function ref_add_admin_menu() {
    add_submenu_page(
        'woocommerce', 
        'Referral Settings', 
        'Referral Settings', 
        'manage_options', 
        'ref-settings', 
        'ref_settings_page_html'
    );
}
add_action( 'admin_menu', 'ref_add_admin_menu' );

// 2. Register Settings
function ref_register_settings() {
    register_setting( 'ref_settings_group', 'ref_friend_discount' );
    register_setting( 'ref_settings_group', 'ref_referrer_reward' );
}
add_action( 'admin_init', 'ref_register_settings' );

// 3. Settings Page HTML
function ref_settings_page_html() {
    ?>
    <div class="wrap">
        <h1>Referral System Settings</h1>
        <form method="post" action="options.php">
            <?php settings_fields( 'ref_settings_group' ); ?>
            <?php do_settings_sections( 'ref_settings_group' ); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Friend Discount Amount (€)</th>
                    <td>
                        <input type="number" name="ref_friend_discount" value="<?php echo esc_attr( get_option('ref_friend_discount', 10) ); ?>" step="0.01" />
                        <p class="description">Amount of discount the friend gets when using the code.</p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row">Referrer Reward Amount (€)</th>
                    <td>
                        <input type="number" name="ref_referrer_reward" value="<?php echo esc_attr( get_option('ref_referrer_reward', 10) ); ?>" step="0.01" />
                        <p class="description">Amount of coupon reward the referrer gets after a successful order.</p>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}


/**
 * ==============================================================
 * PART 2: REGISTER "I MIEI SCONTI" ENDPOINT & MENU
 * ==============================================================
 */

// 1. URL Endpoint register
function custom_register_sconti_endpoint() {
    add_rewrite_endpoint( 'i-miei-sconti', EP_ROOT | EP_PAGES );
}
add_action( 'init', 'custom_register_sconti_endpoint' );

// 2. Menu Item add
function custom_add_sconti_link_my_account( $items ) {
    $logout = $items['customer-logout'];
    unset( $items['customer-logout'] );
    $items['i-miei-sconti'] = 'I MIEI SCONTI'; 
    $items['customer-logout'] = $logout;
    return $items;
}
add_filter( 'woocommerce_account_menu_items', 'custom_add_sconti_link_my_account' );


/**
 * ==============================================================
 * PART 3: FRONTEND - RANDOM CODE GENERATION & DISPLAY
 * ==============================================================
 */

function custom_sconti_content() {
    $user_id = get_current_user_id();
    
    // Get Dynamic Amounts from Settings
    $friend_discount_amt = get_option( 'ref_friend_discount', 10 );
    $referrer_reward_amt = get_option( 'ref_referrer_reward', 10 );

    // 1. Check if User already has a generated code
    $my_unique_code = get_user_meta( $user_id, 'my_unique_referral_code', true );

    // 2. If NO code exists, Generate NEW Random Code
    if ( empty( $my_unique_code ) ) {
        
        // Generate Random 8 Digit Code
        $random_code = strtoupper( wp_generate_password( 8, false ) );
        
        // Ensure unique
        while ( wc_get_coupon_id_by_code( $random_code ) ) {
            $random_code = strtoupper( wp_generate_password( 8, false ) );
        }

        // --- Create WooCommerce Coupon ---
        $new_coupon = new WC_Coupon();
        $new_coupon->set_code( $random_code );
        $new_coupon->set_discount_type( 'fixed_cart' ); 
        $new_coupon->set_amount( $friend_discount_amt ); // Dynamic Amount
        $new_coupon->set_individual_use( true );
        $new_coupon->set_usage_limit( 0 ); 
        $new_coupon->set_usage_limit_per_user( 1 );
        
        // Link to User ID
        $new_coupon->update_meta_data( '_referrer_user_id', $user_id );
        $new_coupon->save();

        update_user_meta( $user_id, 'my_unique_referral_code', $random_code );
        $my_unique_code = $random_code;

    } else {
        // [SMART UPDATE] If code exists, check if admin changed the discount amount
        // If amount in settings is different from coupon amount, update the coupon.
        $existing_coupon = new WC_Coupon( $my_unique_code );
        if ( $existing_coupon->get_id() && $existing_coupon->get_amount() != $friend_discount_amt ) {
            $existing_coupon->set_amount( $friend_discount_amt );
            $existing_coupon->save();
        }
    }

    echo '<div class="woocommerce-MyAccount-content">';
    
    // --- Display The Random Code ---
    echo '<h3>Share this Code with Friends</h3>';
    echo '<p style="background:#eef; padding:15px; border:1px dashed #66c; font-size:24px; font-weight:bold; letter-spacing: 2px; display:inline-block;">' . esc_html($my_unique_code) . '</p>';
    
    // Dynamic Text
    echo '<p><small>Give this code to your friends. They get ' . wc_price($friend_discount_amt) . ' discount, and you get a ' . wc_price($referrer_reward_amt) . ' coupon reward!</small></p>';
    
    echo '<hr style="margin: 20px 0;">';

    // --- My Rewards List ---
    echo '<h3>My Earned Coupons (Rewards)</h3>';
    
    $earned_coupons = get_user_meta( $user_id, 'earned_referral_coupons', true );

    if ( empty( $earned_coupons ) || ! is_array( $earned_coupons ) ) {
        echo '<div class="woocommerce-info">You haven\'t earned any coupons yet. Share your code!</div>';
    } else {
        echo '<table class="woocommerce-orders-table shop_table shop_table_responsive" style="width:100%; text-align:left;">';
        echo '<thead><tr><th  style="font-size:24px;">Coupon Code</th><th style="font-size:24px;">Amount</th><th style="font-size:24px;">Expires</th><th style="font-size:24px;">Status</th></tr></thead>';
        echo '<tbody>';

        foreach ( array_reverse($earned_coupons) as $code ) {
            $coupon = new WC_Coupon( $code );
            
            if ( $coupon->get_id() ) {
                $amount = wc_price( $coupon->get_amount() );
                $expiry_date = $coupon->get_date_expires();
                $expiry_display = $expiry_date ? $expiry_date->date_i18n( 'd M Y' ) : 'Lifetime';

                if ( $coupon->get_usage_count() > 0 ) {
                    $status = '<span style="color:red; font-weight:bold;">USED</span>';
                } elseif ( $expiry_date && time() > $expiry_date->getTimestamp() ) {
                    $status = '<span style="color:gray; font-weight:bold;">EXPIRED</span>';
                } else {
                    $status = '<span style="color:green; font-weight:bold;">AVAILABLE</span>';
                }
                
                echo '<tr>';
                echo '<td style="padding:10px; border-bottom:1px solid #eee; font-weight:bold;">' . esc_html( $code ) . '</td>';
                echo '<td style="padding:10px; border-bottom:1px solid #eee;">' . $amount . '</td>';
                echo '<td style="padding:10px; border-bottom:1px solid #eee;">' . $expiry_display . '</td>';
                echo '<td style="padding:10px; border-bottom:1px solid #eee;">' . $status . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
    }
    echo '</div>';
}
add_action( 'woocommerce_account_i-miei-sconti_endpoint', 'custom_sconti_content' );


/**
 * ==============================================================
 * PART 4: BACKEND - REWARD LOGIC (DYNAMIC)
 * ==============================================================
 */

function generate_referral_reward_dynamic( $order_id ) {
    $order = wc_get_order( $order_id );
    
    // Prevent Duplicate
    if ( get_post_meta( $order_id, '_referral_reward_processed', true ) ) {
        return;
    }

    $applied_coupons = $order->get_coupon_codes();
    if ( empty( $applied_coupons ) ) return;

    // Get Dynamic Reward Amount
    $referrer_reward_amt = get_option( 'ref_referrer_reward', 10 );

    foreach ( $applied_coupons as $coupon_code ) {
        
        $coupon_obj = new WC_Coupon( $coupon_code );
        
        // Get Referrer ID from Coupon
        $referrer_user_id = $coupon_obj->get_meta( '_referrer_user_id' );

        if ( ! $referrer_user_id ) continue;

        $referrer_user = get_user_by( 'id', $referrer_user_id );

        // Self-Referral Check
        if ( $referrer_user && $referrer_user->ID != $order->get_user_id() ) {
            
            // --- Generate Reward ---
            $new_code = 'GIFT-' . strtoupper( wp_generate_password( 6, false ) );
            
            $coupon = new WC_Coupon();
            $coupon->set_code( $new_code );
            $coupon->set_discount_type( 'fixed_cart' );
            $coupon->set_amount( $referrer_reward_amt ); // Dynamic Amount
            $coupon->set_individual_use( true );
            $coupon->set_usage_limit( 1 ); 
            
            // Client Email Restriction
            $coupon->set_email_restrictions( array( $referrer_user->user_email ) );
            
            // Expiry 60 Days
            $coupon->set_date_expires( time() + ( 60 * DAY_IN_SECONDS ) );

            $coupon->save();

            // --- Save Data ---
            $current_coupons = get_user_meta( $referrer_user->ID, 'earned_referral_coupons', true );
            if ( ! is_array( $current_coupons ) ) $current_coupons = array();
            $current_coupons[] = $new_code;
            update_user_meta( $referrer_user->ID, 'earned_referral_coupons', $current_coupons );

            update_post_meta( $order_id, '_referral_reward_processed', 'yes' );
            
            $order->add_order_note( "Referral Reward ($new_code) of amount $referrer_reward_amt sent to: " . $referrer_user->user_login );

            break; 
        }
    }
}
add_action( 'woocommerce_order_status_completed', 'generate_referral_reward_dynamic' );