<?php

/**
 * Plugin Name: My Account Coupons for WooCommerce
 * Description: Pagina "I miei coupon" con sync Klaviyo, logic Birthday hide, ane User Meta update.
 * Version: 1.6.0
 * Author: Ngrid
 * Text Domain: mac-coupons
 */

if (!defined('ABSPATH')) {
    exit;
}

/* ---------------------------------------------------
 * 1. KLAVIYO SYNC LOGIC (SMART SYNC)
 * --------------------------------------------------- */

add_action('template_redirect', 'lb_sync_klaviyo_coupon_smartly');

function lb_sync_klaviyo_coupon_smartly()
{
    if (! is_user_logged_in() || ! is_account_page()) {
        return;
    }

    $user_id = get_current_user_id();

    if (get_transient('lb_klaviyo_check_' . $user_id)) {
        return;
    }

    $user  = wp_get_current_user();
    $email = $user->user_email;
    $api_key = 'pk_16e9846b249187b4a655c2f8b33dac478a'; 

    $args = array(
        'timeout' => 3,
        'headers' => array(
            'Authorization' => 'Klaviyo-API-Key ' . $api_key,
            'revision'      => '2024-02-15',
            'accept'        => 'application/json',
        ),
    );

    $filter_email = 'equals(email,"' . $email . '")';
    $url_profile  = 'https://a.klaviyo.com/api/profiles/?filter=' . urlencode($filter_email);

    $response_profile = wp_remote_get($url_profile, $args);

    if (is_wp_error($response_profile)) {
        return;
    }

    $body_profile = wp_remote_retrieve_body($response_profile);
    $data_profile = json_decode($body_profile, true);

    if (!empty($data_profile['data']) && isset($data_profile['data'][0]['id'])) {
        $profile_id = $data_profile['data'][0]['id'];

        $filter_coupon = 'equals(profile.id,"' . $profile_id . '")';
        $url_coupon    = 'https://a.klaviyo.com/api/coupon-codes/?filter=' . urlencode($filter_coupon);

        $response_coupon = wp_remote_get($url_coupon, $args);

        if (!is_wp_error($response_coupon)) {
            $body_coupon = wp_remote_retrieve_body($response_coupon);
            $data_coupon = json_decode($body_coupon, true);

            if (!empty($data_coupon['data'])) {
                foreach ($data_coupon['data'] as $k_coupon) {
                    $coupon_code = isset($k_coupon['attributes']['unique_code']) ? $k_coupon['attributes']['unique_code'] : '';

                    if ($coupon_code) {
                        $coupon_id = wc_get_coupon_id_by_code($coupon_code);
                        if ($coupon_id) {
                            $coupon = new WC_Coupon($coupon_id);
                            $allowed_emails = $coupon->get_email_restrictions();

                            if (! in_array($email, $allowed_emails)) {
                                $allowed_emails[] = $email;
                                $coupon->set_email_restrictions($allowed_emails);
                                $coupon->save();
                            }
                        }
                    }
                }
            }
        }
    }

    set_transient('lb_klaviyo_check_' . $user_id, 'checked', 12 * HOUR_IN_SECONDS);
}

/* ---------------------------------------------------
 * Endpoint + rewrite
 * --------------------------------------------------- */
function mac_add_my_coupons_endpoint()
{
    add_rewrite_endpoint('my-coupons', EP_ROOT | EP_PAGES);
}
add_action('init', 'mac_add_my_coupons_endpoint');

register_activation_hook(__FILE__, function () {
    mac_add_my_coupons_endpoint();
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});

/* ---------------------------------------------------
 * My Account menu
 * --------------------------------------------------- */
add_filter('woocommerce_account_menu_items', function ($items) {
    $items['my-coupons'] = __('I miei coupon', 'mac-coupons');
    return $items;
});

/* ---------------------------------------------------
 * Page content
 * --------------------------------------------------- */
function mac_my_coupons_content()
{
    if (!is_user_logged_in()) {
        echo "<h3>Please login first</h3>";
        return;
    }

    $user_id    = get_current_user_id();
    $user       = wp_get_current_user();
    $user_email = strtolower($user->user_email);
    $today_ts   = strtotime(date('Y-m-d'));

    echo '<div class="my-coupons-wrapper">';
    echo '<h2>' . esc_html__('I MIEI SCONTI', 'mac-coupons') . '</h2>';

    /* ---------- KLAVIYO BIRTHDAY & USER META UPDATE ---------- */
    $api_key_klaviyo = 'pk_2b1f7c6f30cdc36e87eda85ec55d7e9eb2';
    $headers = [
        'Authorization' => 'Klaviyo-API-Key ' . $api_key_klaviyo,
        'revision' => '2024-10-15',
        'accept' => 'application/json',
        'Content-Type' => 'application/json',
    ];

    $filter_email = 'equals(email,"' . $user_email . '")';
    $response = wp_remote_get(
        'https://a.klaviyo.com/api/profiles/?filter=' . urlencode($filter_email),
        ['headers' => $headers]
    );

    $coupons_found = false;

    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (!empty($data['data'][0]['id'])) {
            $profile_id = $data['data'][0]['id'];

            // Check for Klaviyo coupons
            $coupon_check_url = 'https://a.klaviyo.com/api/coupon-codes/?filter=' . urlencode('equals(profile.id,"'.$profile_id.'")');
            $coupon_check_res = wp_remote_get($coupon_check_url, ['headers' => $headers]);
            
            if (!is_wp_error($coupon_check_res)) {
                $coupon_check_data = json_decode(wp_remote_retrieve_body($coupon_check_res), true);
                if (!empty($coupon_check_data['data'])) {
                    $coupons_found = true;
                }
            }

            // ONLY show birthday form IF no coupons found
            if (!$coupons_found) {
                
                if (!empty($_POST['birthday'])) {
                    $new_birthday = sanitize_text_field($_POST['birthday']);
                    
                    // 1. Update Klaviyo
                    $payload = [
                        "data" => [
                            "type" => "profile",
                            "id" => $profile_id,
                            "attributes" => [
                                "properties" => ["Compleanno" => $new_birthday]
                            ]
                        ]
                    ];
                    wp_remote_request("https://a.klaviyo.com/api/profiles/$profile_id", [
                        'method'  => 'PATCH',
                        'headers' => $headers,
                        'body'    => json_encode($payload),
                    ]);

                    // 2. Update WordPress User Meta (_billing_birthdate)
                    update_user_meta($user_id, '_billing_birthdate', $new_birthday);

                    echo "<p style='color:green;'>Data di nascita aggiornata in Klaviyo e profilo✔</p>";
                }

                // Get current value from Klaviyo
                $response_profile = wp_remote_get("https://a.klaviyo.com/api/profiles/$profile_id", ['headers' => $headers]);
                $profile_data = json_decode(wp_remote_retrieve_body($response_profile), true);
                $birthday = $profile_data['data']['attributes']['properties']['Compleanno'] ?? '';
                ?>
                <div class="klaviyo-birthday-form" style="margin-bottom: 30px; padding: 20px; border: 1px solid #eee; background: #fdfdfd;">
                    <form method="post">
                        <label><strong>Se vuoi ricevere il 25% di sconto per il tuo compleanno, inserisci la tua data di nascita</strong></label><br><br>
                        <input type="date" name="birthday" value="<?php echo esc_attr($birthday); ?>" required>
                        <button type="submit" class="button">Aggiungi data di nascita</button>
                    </form>
                </div>
                <?php
            }
        }
    }
    /* ---------- END BIRTHDAY LOGIC ---------- */

    echo '<p>' . esc_html__('Questi coupon sono riservati al tuo account. Copiali e usali al checkout.', 'mac-coupons') . '</p>';

    $coupon_ids = get_posts([
        'post_type'      => 'shop_coupon',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'fields'         => 'ids',
        'meta_query'     => [
            [
                'key'     => 'customer_email',
                'value'   => '"' . $user_email . '"',
                'compare' => 'LIKE',
            ],
        ],
    ]);

    if (empty($coupon_ids)) {
        echo '<p>' . esc_html__('Nessun coupon disponibile per il tuo account.', 'mac-coupons') . '</p>';
    } else {
        echo '<table class="shop_table shop_table_responsive my_account_coupons">
                <thead>
                    <tr>
                        <th>Codice coupon</th>
                        <th>Sconto</th>
                        <th>Scadenza</th>
                        <th>Azione</th>
                    </tr>
                </thead>
                <tbody>';

        foreach ($coupon_ids as $coupon_id) {
            $coupon = new WC_Coupon($coupon_id);
            $expiry = $coupon->get_date_expires();
            if ($expiry && $expiry->getTimestamp() < $today_ts) continue;

            $usage_count = $coupon->get_usage_count();
            $usage_limit = $coupon->get_usage_limit();
            $is_fully_used = ($usage_limit > 0 && $usage_count >= $usage_limit);

            $amount = $coupon->get_amount();
            $discount = ($coupon->get_discount_type() === 'percent') ? $amount.'%' : wc_price($amount);

            echo '<tr>
                    <td><strong>' . esc_html($coupon->get_code()) . '</strong></td>
                    <td>' . $discount . '</td>
                    <td>' . ($expiry ? esc_html($expiry->date('d/m/Y')) : 'Nessuna') . '</td>
                    <td>';
            
            if ($is_fully_used) {
                echo '<button type="button" class="button disabled" disabled style="opacity:0.6;">UTILIZZATO</button>';
            } else {
                echo '<button type="button" class="button copy-coupon" data-coupon="'.esc_attr($coupon->get_code()).'" data-copy="Copia" data-copied="Copiato ✓">Copia</button>';
            }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    echo '</div>';
}
add_action('woocommerce_account_my-coupons_endpoint', 'mac_my_coupons_content');

/* ---------------------------------------------------
 * JS + UX feedback
 * --------------------------------------------------- */
add_action('wp_footer', function () {
    if (!is_account_page()) return;
?>
    <script>
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.copy-coupon');
            if (!btn || btn.hasAttribute('disabled')) return;
            e.preventDefault();

            const code = btn.dataset.coupon;
            navigator.clipboard.writeText(code).then(() => {
                const originalText = btn.innerText;
                btn.innerText = btn.dataset.copied;
                btn.classList.add('copied-success');
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.classList.remove('copied-success');
                }, 2000);
            });
        });
    </script>
    <style>
        .copy-coupon.copied-success { background-color: #2ecc71 !important; color: #fff !important; }
        
    </style>
<?php
});


