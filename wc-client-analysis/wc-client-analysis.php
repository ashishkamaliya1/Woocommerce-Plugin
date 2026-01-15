<?php

/**
 * Plugin Name: WooCommerce Client & Product Analysis
 * Description: Month-wise client analysis (New/Returning) and Product Performance (Units/Net Sales) with comparison and filtering.
 * Version: 5.6
 * Author: NGrid dev
 * Text Domain: wc-client-analysis
 */

if (!defined('ABSPATH')) {
    exit;
}

class WC_Client_Analysis_Optimized
{
    private $table_prefix;
    private $date_format = 'Y-m-d H:i:s';
    private $order_statuses = array('wc-completed', 'wc-processing');

    public function __construct()
    {
        global $wpdb;
        $this->table_prefix = $wpdb->prefix;
        add_action('init', array($this, 'increase_limits'));
        add_action('admin_menu', array($this, 'register_admin_page'));
        add_action('admin_post_wc_client_analysis_export', array($this, 'export_csv'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
    }

    public function increase_limits()
    {
        if (is_admin() && isset($_GET['page']) && $_GET['page'] === 'wc-client-analysis') {
            @set_time_limit(300);
            @ini_set('memory_limit', '512M');
        }
    }

    public function enqueue_admin_scripts($hook)
    {
        if ($hook !== 'woocommerce_page_wc-client-analysis') {
            return;
        }
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-css', 'https://code.jquery.com/ui/1.12.1/themes/base/jquery-ui.css');
    }

    public function register_admin_page()
    {
        add_submenu_page(
            'woocommerce',
            'Analytics Report',
            'Client & Product Analysis',
            'manage_woocommerce',
            'wc-client-analysis',
            array($this, 'admin_page_html')
        );
    }

    // --- UTILITIES ---

    private function get_payment_gateway_title($gateway_id)
    {
        if ($gateway_id === 'all_credit_cards_combined') {
            return 'Credit / Debit Card (All Types)';
        }
        $gateways = WC()->payment_gateways()->payment_gateways();
        return isset($gateways[$gateway_id]) ? $gateways[$gateway_id]->get_title() : $gateway_id;
    }

    private function combine_payment_methods($payment_methods_raw)
    {
        $combined_methods = array();
        $all_credit_cards_total = 0;
        $methods_by_display_name = array();

        foreach ($payment_methods_raw as $pm) {
            $gateway_name = $this->get_payment_gateway_title($pm->payment_method);
            $original_id = $pm->payment_method;
            $gateway_name_lower = strtolower($gateway_name);
            $original_id_lower = strtolower($original_id);

            if (
                strpos($gateway_name_lower, 'carta di credito') !== false ||
                strpos($original_id_lower, 'carta_di_credito') !== false ||
                $original_id === 'carta_di_credito' ||
                strpos($gateway_name_lower, 'credit') !== false ||
                strpos($gateway_name_lower, 'debit') !== false ||
                strpos($original_id_lower, 'credit') !== false ||
                strpos($original_id_lower, 'debit') !== false ||
                strpos($gateway_name_lower, 'payplug') !== false ||
                strpos($original_id_lower, 'payplug') !== false ||
                $original_id === 'payplug' ||
                strpos($gateway_name_lower, 'american express') !== false ||
                strpos($gateway_name_lower, 'amex') !== false ||
                strpos($original_id_lower, 'american_express') !== false ||
                strpos($original_id_lower, 'amex') !== false ||
                $original_id === 'american_express'
            ) {
                $all_credit_cards_total += $pm->count;
            } else {
                if (!isset($methods_by_display_name[$gateway_name])) {
                    $methods_by_display_name[$gateway_name] = array(
                        'original_id' => $original_id,
                        'count' => 0
                    );
                }
                $methods_by_display_name[$gateway_name]['count'] += $pm->count;
            }
        }

        foreach ($methods_by_display_name as $display_name => $data) {
            $obj = new stdClass();
            $obj->payment_method = $data['original_id'];
            $obj->count = $data['count'];
            $combined_methods[] = $obj;
        }

        if ($all_credit_cards_total > 0) {
            $combined_credit = new stdClass();
            $combined_credit->payment_method = 'all_credit_cards_combined';
            $combined_credit->count = $all_credit_cards_total;
            $combined_methods[] = $combined_credit;
        }

        usort($combined_methods, function ($a, $b) {
            return $b->count - $a->count;
        });

        return $combined_methods;
    }

    private function get_date_range($preset, $custom_start = null, $custom_end = null)
    {
        $today = current_time('timestamp');
        $result = array();

        if (empty($custom_start)) $custom_start = null;
        if (empty($custom_end)) $custom_end = null;

        switch ($preset) {
            case 'today':
                $result['start'] = date('Y-m-d 00:00:00', $today);
                $result['end'] = date('Y-m-d 23:59:59', $today);
                $result['name'] = 'Today';
                break;
            case 'yesterday':
                $yesterday = $today - 86400;
                $result['start'] = date('Y-m-d 00:00:00', $yesterday);
                $result['end'] = date('Y-m-d 23:59:59', $yesterday);
                $result['name'] = 'Yesterday';
                break;
            case 'week_to_date':
                $week_start = strtotime('monday this week', $today);
                $result['start'] = date('Y-m-d 00:00:00', $week_start);
                $result['end'] = date('Y-m-d 23:59:59', $today);
                $result['name'] = 'Week to Date';
                break;
            case 'last_week':
                $week_start = strtotime('monday last week', $today);
                $week_end = strtotime('sunday last week', $today);
                $result['start'] = date('Y-m-d 00:00:00', $week_start);
                $result['end'] = date('Y-m-d 23:59:59', $week_end);
                $result['name'] = 'Last Week';
                break;
            case 'month_to_date':
                $result['start'] = date('Y-m-01 00:00:00', $today);
                $result['end'] = date('Y-m-d 23:59:59', $today);
                $result['name'] = 'Month to Date';
                break;
            case 'last_month':
                $first_day_last_month = strtotime('first day of last month', $today);
                $last_day_last_month = strtotime('last day of last month', $today);
                $result['start'] = date('Y-m-d 00:00:00', $first_day_last_month);
                $result['end'] = date('Y-m-d 23:59:59', $last_day_last_month);
                $result['name'] = 'Last Month';
                break;
            case 'quarter_to_date':
                $current_quarter = ceil(date('n', $today) / 3);
                $quarter_start_month = ($current_quarter - 1) * 3 + 1;
                $result['start'] = date('Y-' . str_pad($quarter_start_month, 2, '0', STR_PAD_LEFT) . '-01 00:00:00', $today);
                $result['end'] = date('Y-m-d 23:59:59', $today);
                $result['name'] = 'Quarter to Date';
                break;
            case 'last_quarter':
                $current_quarter = ceil(date('n', $today) / 3);
                $last_quarter = $current_quarter - 1;
                if ($last_quarter < 1) {
                    $last_quarter = 4;
                    $year = date('Y', $today) - 1;
                } else {
                    $year = date('Y', $today);
                }
                $quarter_start_month = ($last_quarter - 1) * 3 + 1;
                $quarter_end_month = $quarter_start_month + 2;
                $result['start'] = $year . '-' . str_pad($quarter_start_month, 2, '0', STR_PAD_LEFT) . '-01 00:00:00';
                $result['end'] = date('Y-m-t 23:59:59', strtotime($year . '-' . $quarter_end_month . '-01'));
                $result['name'] = 'Last Quarter';
                break;
            case 'year_to_date':
                $result['start'] = date('Y-01-01 00:00:00', $today);
                $result['end'] = date('Y-m-d 23:59:59', $today);
                $result['name'] = 'Year to Date';
                break;
            case 'last_year':
                $year = date('Y', $today) - 1;
                $result['start'] = $year . '-01-01 00:00:00';
                $result['end'] = $year . '-12-31 23:59:59';
                $result['name'] = 'Last Year';
                break;
            case 'custom':
                if ($custom_start && $custom_end) {
                    $start_time = strtotime($custom_start);
                    $end_time = strtotime($custom_end);
                    if ($start_time && $end_time) {
                        $result['start'] = date('Y-m-d 00:00:00', $start_time);
                        $result['end'] = date('Y-m-d 23:59:59', $end_time);
                        $result['name'] = 'Custom (' . date('M d, Y', $start_time) . ' - ' . date('M d, Y', $end_time) . ')';
                    } else {
                        $result['start'] = date('Y-m-01 00:00:00', $today);
                        $result['end'] = date('Y-m-d 23:59:59', $today);
                        $result['name'] = 'Month to Date (Fallback)';
                    }
                } else {
                    $result['start'] = date('Y-m-01 00:00:00', $today);
                    $result['end'] = date('Y-m-d 23:59:59', $today);
                    $result['name'] = 'Month to Date';
                }
                break;
            default:
                $result['start'] = date('Y-m-01 00:00:00', $today);
                $result['end'] = date('Y-m-d 23:59:59', $today);
                $result['name'] = 'Month to Date';
        }
        return $result;
    }

    private function get_comparison_date_range($compare_preset, $main_preset, $main_start, $main_end, $custom_start = null, $custom_end = null)
    {
        if (!$compare_preset) {
            return null;
        }

        $result = array();
        if (empty($custom_start)) $custom_start = null;
        if (empty($custom_end)) $custom_end = null;

        $main_start_time = strtotime($main_start);
        $main_end_time = strtotime($main_end);

        if ($compare_preset === 'custom' && $custom_start && $custom_end) {
            $start_time = strtotime($custom_start);
            $end_time = strtotime($custom_end);
            if ($start_time && $end_time) {
                $result['start'] = date('Y-m-d 00:00:00', $start_time);
                $result['end'] = date('Y-m-d 23:59:59', $end_time);
                $result['name'] = 'Custom (' . date('M d, Y', $start_time) . ' - ' . date('M d, Y', $end_time) . ')';
            }
        } elseif ($compare_preset === 'previous_year' && $main_start_time && $main_end_time) {
            $result['start'] = date('Y-m-d 00:00:00', strtotime('-1 year', $main_start_time));
            $result['end'] = date('Y-m-d 23:59:59', strtotime('-1 year', $main_end_time));
            $result['name'] = 'Previous Year';
        } elseif ($compare_preset === 'previous_period' && $main_start_time && $main_end_time) {
            $main_diff = $main_end_time - $main_start_time;
            $result['start'] = date('Y-m-d 00:00:00', $main_start_time - $main_diff - 86400);
            $result['end'] = date('Y-m-d 23:59:59', $main_start_time - 1);
            $result['name'] = 'Previous Period';
        }

        return !empty($result) ? $result : null;
    }

    // --- ANALYTICS LOGIC ---

    private function get_segment_key_for_order_number($order_number)
    {
        if ($order_number <= 1) return 'new_customers';
        if ($order_number === 2) return 'returning_2';
        if ($order_number >= 3 && $order_number <= 10) return 'repeat_' . $order_number;
        return 'loyal_customers';
    }

    // Get Analytics for PRODUCTS based on client filter
    private function get_product_analytics_data($start_date, $end_date, $filter_type = 'all')
    {
        global $wpdb;

        // 1. Get All Orders in Period first (ID and Email)
        // We use this to calculate "Order Count within Period" for each email
        $period_orders = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, LOWER(pm.meta_value) as email
            FROM {$this->table_prefix}posts p
            JOIN {$this->table_prefix}postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_billing_email'
            WHERE p.post_type = 'shop_order'
            AND p.post_status IN ('" . implode("','", $this->order_statuses) . "')
            AND p.post_date BETWEEN %s AND %s
            AND pm.meta_value != ''
        ", $start_date, $end_date));

        if (empty($period_orders)) return [];

        // 2. Count orders per email WITHIN THIS PERIOD ONLY
        // New Logic: 
        // - "New Client" = Has exactly 1 order in this period (ignore lifetime).
        // - "Returning Client" = Has > 1 order in this period.

        $email_period_counts = [];
        foreach ($period_orders as $order) {
            if (!isset($email_period_counts[$order->email])) {
                $email_period_counts[$order->email] = 0;
            }
            $email_period_counts[$order->email]++;
        }

        // 3. Filter Order IDs based on the Count Logic
        $target_order_ids = [];
        foreach ($period_orders as $order) {
            $count_in_period = $email_period_counts[$order->email];

            if ($filter_type === 'all') {
                $target_order_ids[] = $order->ID;
            } elseif ($filter_type === 'new') {
                // Logic: Only 1 order in this period
                if ($count_in_period === 1) {
                    $target_order_ids[] = $order->ID;
                }
            } elseif ($filter_type === 'returning') {
                // Logic: More than 1 order in this period
                if ($count_in_period > 1) {
                    $target_order_ids[] = $order->ID;
                }
            }
        }

        if (empty($target_order_ids)) {
            return [];
        }

        // 4. Query Items based on Filtered Order IDs
        $ids_placeholder = implode(',', array_map('intval', $target_order_ids));

        $sql = "
            SELECT 
                item_meta_product.meta_value as product_id,
                MAX(items.order_item_name) as product_name,
                SUM( CAST( item_meta_qty.meta_value AS UNSIGNED ) ) as units_sold,
                SUM( CAST( item_meta_total.meta_value AS DECIMAL(10,2) ) ) as net_sales
            FROM {$this->table_prefix}woocommerce_order_items as items
            JOIN {$this->table_prefix}woocommerce_order_itemmeta as item_meta_product ON items.order_item_id = item_meta_product.order_item_id AND item_meta_product.meta_key = '_product_id'
            LEFT JOIN {$this->table_prefix}woocommerce_order_itemmeta as item_meta_qty ON items.order_item_id = item_meta_qty.order_item_id AND item_meta_qty.meta_key = '_qty'
            LEFT JOIN {$this->table_prefix}woocommerce_order_itemmeta as item_meta_total ON items.order_item_id = item_meta_total.order_item_id AND item_meta_total.meta_key = '_line_total'
            WHERE items.order_id IN ($ids_placeholder)
            AND items.order_item_type = 'line_item'
            GROUP BY item_meta_product.meta_value
            ORDER BY net_sales DESC
        ";

        $results = $wpdb->get_results($sql);

        // Re-key by product ID for easy comparison later
        $data = [];
        foreach ($results as $row) {
            // Try to get variation name if it's a variation
            $product = wc_get_product($row->product_id);
            $name = $row->product_name;
            if ($product) {
                $name = $product->get_name(); // Gets "Product - Variation Name"
            }

            $data[$row->product_id] = [
                'name' => $name,
                'units' => (int)$row->units_sold,
                'sales' => (float)$row->net_sales
            ];
        }

        return $data;
    }

    // Get analytics data with optimized queries (Existing Client Analysis - KEEPING ORIGINAL LOGIC HERE)
    private function get_analytics_data($start_date, $end_date)
    {
        global $wpdb;

        $default_data = array(
            'total_orders' => 0,
            'unique_clients' => 0,
            'new_clients' => 0,
            'customer_segments' => array(),
            'customer_segments_range' => array(),
            'payment_methods' => array(),
            'total_revenue' => 0,
            'gross_revenue' => 0,
            'total_tax' => 0,
            'revenue_new_customers' => 0,
            'revenue_returning_customers' => 0,
            'average_order_value' => 0
        );

        try {
            $total_orders = $wpdb->get_var($wpdb->prepare("
                SELECT COUNT(ID) FROM {$this->table_prefix}posts
                WHERE post_type = 'shop_order' AND post_status IN ('" . implode("','", $this->order_statuses) . "')
                AND post_date BETWEEN %s AND %s
            ", $start_date, $end_date));

            if (!$total_orders || $total_orders == 0) return $default_data;

            $data = $default_data;
            $data['total_orders'] = intval($total_orders);

            $orders_query = $wpdb->prepare("
                SELECT p.ID, LOWER(pm_email.meta_value) as email, pm_payment.meta_value as payment_method,
                pm_total.meta_value as order_total, pm_tax.meta_value as order_tax,
                pm_shipping_tax.meta_value as shipping_tax, pm_cart_tax.meta_value as cart_tax
                FROM {$this->table_prefix}posts p
                LEFT JOIN {$this->table_prefix}postmeta pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
                LEFT JOIN {$this->table_prefix}postmeta pm_payment ON p.ID = pm_payment.post_id AND pm_payment.meta_key = '_payment_method'
                LEFT JOIN {$this->table_prefix}postmeta pm_total ON p.ID = pm_total.post_id AND pm_total.meta_key = '_order_total'
                LEFT JOIN {$this->table_prefix}postmeta pm_tax ON p.ID = pm_tax.post_id AND pm_tax.meta_key = '_order_tax'
                LEFT JOIN {$this->table_prefix}postmeta pm_shipping_tax ON p.ID = pm_shipping_tax.post_id AND pm_shipping_tax.meta_key = '_order_shipping_tax'
                LEFT JOIN {$this->table_prefix}postmeta pm_cart_tax ON p.ID = pm_cart_tax.post_id AND pm_cart_tax.meta_key = '_cart_tax'
                WHERE p.post_type = 'shop_order' AND p.post_status IN ('" . implode("','", $this->order_statuses) . "')
                AND p.post_date BETWEEN %s AND %s AND pm_email.meta_value IS NOT NULL AND pm_email.meta_value != ''
                LIMIT 1000000
            ", $start_date, $end_date);

            $orders = $wpdb->get_results($orders_query);
            if (!$orders) return $default_data;

            $first_order_query = $wpdb->prepare("
                SELECT LOWER(pm_email.meta_value) as email, MIN(p.post_date) as first_order_date
                FROM {$this->table_prefix}posts p
                LEFT JOIN {$this->table_prefix}postmeta pm_email ON p.ID = pm_email.post_id AND pm_email.meta_key = '_billing_email'
                WHERE p.post_type = 'shop_order' AND p.post_status IN ('" . implode("','", $this->order_statuses) . "')
                AND pm_email.meta_value IS NOT NULL AND pm_email.meta_value != ''
                GROUP BY LOWER(pm_email.meta_value) HAVING first_order_date < %s
                LIMIT 1000000
            ", $start_date);

            $first_order_results = $wpdb->get_results($first_order_query, ARRAY_A);
            $existing_customers = array();
            if ($first_order_results) {
                foreach ($first_order_results as $row) {
                    if (!empty($row['email'])) $existing_customers[$row['email']] = true;
                }
            }

            $unique_customers = array();
            $new_customers = array();
            $payment_counts = array();
            $gross_revenue = 0;
            $total_tax = 0;
            $customer_revenue_new = array();
            $customer_revenue_returning = array();

            foreach ($orders as $order) {
                $email = $order->email;
                if (empty($email)) continue;

                if (!isset($unique_customers[$email])) {
                    $unique_customers[$email] = true;
                    if (!isset($existing_customers[$email])) $new_customers[$email] = true;
                }

                if ($order->payment_method) {
                    if (!isset($payment_counts[$order->payment_method])) $payment_counts[$order->payment_method] = 0;
                    $payment_counts[$order->payment_method]++;
                }

                $total = floatval($order->order_total ?: 0);
                $tax = floatval($order->order_tax ?: 0);
                $shipping_tax = floatval($order->shipping_tax ?: 0);
                $cart_tax = floatval($order->cart_tax ?: 0);

                $gross_revenue += $total;
                $total_tax += $tax + $shipping_tax + $cart_tax;
                $net_revenue = $total - ($tax + $shipping_tax + $cart_tax);
                $data['total_revenue'] += $net_revenue;

                if (!isset($existing_customers[$email])) {
                    if (!isset($customer_revenue_new[$email])) $customer_revenue_new[$email] = 0;
                    $customer_revenue_new[$email] += $net_revenue;
                } else {
                    if (!isset($customer_revenue_returning[$email])) $customer_revenue_returning[$email] = 0;
                    $customer_revenue_returning[$email] += $net_revenue;
                }
            }

            foreach ($customer_revenue_new as $rev) $data['revenue_new_customers'] += $rev;
            foreach ($customer_revenue_returning as $rev) $data['revenue_returning_customers'] += $rev;

            $data['unique_clients'] = count($unique_customers);
            $data['new_clients'] = count($new_customers);
            $data['gross_revenue'] = $gross_revenue;
            $data['total_tax'] = $total_tax;

            $payment_methods_raw = array();
            foreach ($payment_counts as $method => $count) {
                $obj = new stdClass();
                $obj->payment_method = $method;
                $obj->count = $count;
                $payment_methods_raw[] = $obj;
            }
            $data['payment_methods'] = $this->combine_payment_methods($payment_methods_raw);

            if ($data['total_orders'] > 0) {
                $data['average_order_value'] = round($data['total_revenue'] / $data['total_orders'], 2);
            }

            $data['customer_segments'] = $this->get_customer_segments_lifetime($start_date, $end_date);
            $data['customer_segments_range'] = $this->get_customer_segments_period_only($start_date, $end_date);

            return $data;
        } catch (Exception $e) {
            error_log('WC Client Analysis Error: ' . $e->getMessage());
            return $default_data;
        }
    }

    private function get_customer_segments_lifetime($start_date, $end_date)
    {
        global $wpdb;
        $customer_lifetime_orders = $wpdb->get_results("
            SELECT LOWER(pm.meta_value) as email, COUNT(p.ID) as lifetime_order_count
            FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_billing_email' AND p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed','wc-processing') AND pm.meta_value != ''
            GROUP BY LOWER(pm.meta_value)
        ");

        $customer_period_orders = $wpdb->get_results($wpdb->prepare("
            SELECT LOWER(pm.meta_value) as email, COUNT(p.ID) as period_order_count
            FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_billing_email' AND p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed','wc-processing') AND p.post_date BETWEEN %s AND %s
            AND pm.meta_value != '' GROUP BY LOWER(pm.meta_value) ORDER BY period_order_count DESC
        ", $start_date, $end_date));

        $lifetime_order_map = array();
        foreach ($customer_lifetime_orders as $c) if (!empty($c->email)) $lifetime_order_map[$c->email] = intval($c->lifetime_order_count);

        $segments = $this->get_empty_segments_array();

        foreach ($customer_period_orders as $customer) {
            $email = $customer->email;
            if (!$email) continue;
            $lifetime_order_count = isset($lifetime_order_map[$email]) ? $lifetime_order_map[$email] : 0;
            if ($lifetime_order_count <= 0) continue;
            $segment_key = $this->get_segment_key_for_order_number($lifetime_order_count);
            if ($segment_key && isset($segments[$segment_key])) $segments[$segment_key]['count']++;
            else $segments['loyal_customers']['count']++;
        }

        $total = count($customer_period_orders);
        foreach ($segments as &$segment) $segment['percentage'] = $total > 0 ? round(($segment['count'] / $total) * 100, 1) : 0;
        return $segments;
    }

    private function get_customer_segments_period_only($start_date, $end_date)
    {
        global $wpdb;
        $customer_period_orders = $wpdb->get_results($wpdb->prepare("
            SELECT LOWER(pm.meta_value) as email, COUNT(p.ID) as period_order_count
            FROM {$wpdb->prefix}postmeta pm
            INNER JOIN {$wpdb->prefix}posts p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_billing_email' AND p.post_type = 'shop_order'
            AND p.post_status IN ('wc-completed','wc-processing') AND p.post_date BETWEEN %s AND %s
            AND pm.meta_value != '' GROUP BY LOWER(pm.meta_value) ORDER BY period_order_count DESC
        ", $start_date, $end_date));

        $segments = $this->get_empty_segments_array();
        foreach ($customer_period_orders as $customer) {
            $cnt = intval($customer->period_order_count);
            if ($cnt <= 0) continue;
            $segment_key = $this->get_segment_key_for_order_number($cnt);
            if ($segment_key && isset($segments[$segment_key])) $segments[$segment_key]['count']++;
            else $segments['loyal_customers']['count']++;
        }
        $total = count($customer_period_orders);
        foreach ($segments as &$segment) $segment['percentage'] = $total > 0 ? round(($segment['count'] / $total) * 100, 1) : 0;
        return $segments;
    }

    private function get_empty_segments_array()
    {
        return array(
            'new_customers' => array('label' => 'New Customers', 'range' => 'First-time customers', 'min' => 1, 'max' => 1, 'count' => 0),
            'returning_2' => array('label' => 'Returning Customers', 'range' => '2nd order overall', 'min' => 2, 'max' => 2, 'count' => 0),
            'repeat_3' => array('label' => 'Returning Customers', 'range' => '3rd order', 'min' => 3, 'max' => 3, 'count' => 0),
            'repeat_4' => array('label' => 'Returning Customers', 'range' => '4th order', 'min' => 4, 'max' => 4, 'count' => 0),
            'repeat_5' => array('label' => 'Returning Customers', 'range' => '5th order', 'min' => 5, 'max' => 5, 'count' => 0),
            'repeat_6' => array('label' => 'Returning Customers', 'range' => '6th order', 'min' => 6, 'max' => 6, 'count' => 0),
            'repeat_7' => array('label' => 'Returning Customers', 'range' => '7th order', 'min' => 7, 'max' => 7, 'count' => 0),
            'repeat_8' => array('label' => 'Returning Customers', 'range' => '8th order', 'min' => 8, 'max' => 8, 'count' => 0),
            'repeat_9' => array('label' => 'Returning Customers', 'range' => '9th order', 'min' => 9, 'max' => 9, 'count' => 0),
            'repeat_10' => array('label' => 'Returning Customers', 'range' => '10th order', 'min' => 10, 'max' => 10, 'count' => 0),
            'loyal_customers' => array('label' => 'Returning Customers', 'range' => '10+ orders', 'min' => 11, 'max' => 9999, 'count' => 0)
        );
    }

    private function calculate_change($current, $previous)
    {
        if ($previous == 0) return $current > 0 ? 100 : 0;
        return round((($current - $previous) / $previous) * 100, 1);
    }

    private function get_segment_color($segment_label)
    {
        $colors = array(
            'New Customers' => '#00a32a',
            'Returning Customers' => '#2271b1',
            'Repeat Customers' => '#d63638',
            'Returning Customers' => '#946638',
        );
        return isset($colors[$segment_label]) ? $colors[$segment_label] : '#666';
    }

    private function render_change_badge($change)
    {
        if ($change === null) return '';
        $color = $change > 0 ? '#00a32a' : ($change < 0 ? '#d63638' : '#666');
        $arrow = $change > 0 ? '▲' : ($change < 0 ? '▼' : '');
        $sign = $change > 0 ? '+' : '';
        return '<span style="color: ' . $color . '; font-weight: bold; font-size: 1.1em;">' . $arrow . ' ' . $sign . esc_html($change) . '%</span>';
    }

    // --- ADMIN PAGE RENDER ---

    public function admin_page_html()
    {
        if (!current_user_can('manage_woocommerce')) wp_die(__('You do not have sufficient permissions to access this page.'));

        $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'client';
        $preset = isset($_GET['preset']) ? sanitize_text_field($_GET['preset']) : 'last_month';
        $custom_start = isset($_GET['custom_start']) ? sanitize_text_field($_GET['custom_start']) : '';
        $custom_end = isset($_GET['custom_end']) ? sanitize_text_field($_GET['custom_end']) : '';
        $compare_to = isset($_GET['compare_to']) ? sanitize_text_field($_GET['compare_to']) : '';
        $compare_custom_start = isset($_GET['compare_custom_start']) ? sanitize_text_field($_GET['compare_custom_start']) : '';
        $compare_custom_end = isset($_GET['compare_custom_end']) ? sanitize_text_field($_GET['compare_custom_end']) : '';
        $client_filter = isset($_GET['client_filter']) ? sanitize_text_field($_GET['client_filter']) : 'all'; // New Filter

        echo '<div class="wrap">';
        echo '<h1>Analytics Report</h1>';

        // TABS
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="' . admin_url('admin.php?page=wc-client-analysis&tab=client&preset=' . $preset) . '" class="nav-tab ' . ($active_tab == 'client' ? 'nav-tab-active' : '') . '">Client Analysis</a>';
        echo '<a href="' . admin_url('admin.php?page=wc-client-analysis&tab=products&preset=' . $preset) . '" class="nav-tab ' . ($active_tab == 'products' ? 'nav-tab-active' : '') . '">Product Analysis</a>';
        echo '</h2>';

        $main_range = $this->get_date_range($preset, $custom_start, $custom_end);
        $comparison_range = $compare_to ? $this->get_comparison_date_range($compare_to, $preset, $main_range['start'], $main_range['end'], $compare_custom_start, $compare_custom_end) : null;

        $this->render_date_selection_form($active_tab, $preset, $custom_start, $custom_end, $compare_to, $compare_custom_start, $compare_custom_end, $client_filter);

        echo '<hr style="margin: 30px 0;">';
        echo '<div id="loading-progress" style="display: none; background: #fff; border-left: 4px solid #2271b1; padding: 10px 15px; margin: 20px 0;">
                <p style="margin: 0; font-weight: bold;">📊 Processing data... Please wait.</p>
              </div>';
        echo '<script>document.addEventListener("DOMContentLoaded", function() { var form = document.getElementById("date-range-form"); if (form) { form.addEventListener("submit", function() { document.getElementById("loading-progress").style.display = "block"; }); } });</script>';

        @set_time_limit(300);
        @ini_set('memory_limit', '512M');
        ob_flush();
        flush();

        if ($active_tab === 'products') {
            // PRODUCT ANALYSIS TAB
            $main_data = $this->get_product_analytics_data($main_range['start'], $main_range['end'], $client_filter);
            $comparison_data = $comparison_range ? $this->get_product_analytics_data($comparison_range['start'], $comparison_range['end'], $client_filter) : null;

            echo '<script>document.getElementById("loading-progress").style.display = "none";</script>';
            $this->render_product_analytics_display($main_range, $main_data, $comparison_range, $comparison_data, $client_filter);
        } else {
            // CLIENT ANALYSIS TAB
            $main_data = $this->get_analytics_data($main_range['start'], $main_range['end']);
            $comparison_data = $comparison_range ? $this->get_analytics_data($comparison_range['start'], $comparison_range['end']) : null;

            echo '<script>document.getElementById("loading-progress").style.display = "none";</script>';
            $this->render_analytics_display($main_range, $main_data, $comparison_range, $comparison_data);
        }

        // Export button
        $this->render_export_form($active_tab, $preset, $custom_start, $custom_end, $compare_to, $compare_custom_start, $compare_custom_end, $client_filter);

        echo '</div>';
    }

    private function render_date_selection_form($active_tab, $preset, $custom_start, $custom_end, $compare_to, $compare_custom_start, $compare_custom_end, $client_filter)
    {
?>
        <style>
            .date-tab-content.active .custom-date-inputs div {
                display: flex;
                gap: 15px;
                align-items: center;
            }

            .tax-table table tbody tr:nth-child(2) {
                display: none;
            }

            #compare-custom-dates .custom-date-inputs div {
                display: flex !important;
                gap: 20px !important;
                flex-direction: column;
            }

            #compare-custom-dates .custom-date-inputs label {
                font-weight: 600;
            }

            .compare-options.active {
                margin-top: 20px;
            }

            .zi-export-excel-btn input[type="submit"] {
                background: linear-gradient(135deg, #d63638 0%, #b32d2e 100%) !important;
                border: 2px solid #d63638 !important;
                color: white !important;
                font-weight: bold !important;
                text-shadow: 0 1px 1px rgba(0, 0, 0, 0.2) !important;
                padding: 12px 30px !important;
                border-radius: 4px !important;
                transition: all 0.3s ease !important;
                box-shadow: 0 3px 5px rgba(214, 54, 56, 0.3) !important;
                font-size: 16px !important;
                /* Restored Font Size */
                height: auto !important;
                line-height: 1.5 !important;
                cursor: pointer !important;
                display: inline-block !important;
            }

            .zi-export-excel-btn input[type="submit"]:active {
                transform: translateY(0);
                box-shadow: 0 2px 3px rgba(214, 54, 56, 0.3) !important;
            }

            .zi-export-excel-btn input[type="submit"]:focus {
                outline: none;
                box-shadow: 0 0 0 2px rgba(214, 54, 56, 0.3) !important;
            }

            /* Restored Font Sizes for Tables */
            .zi-table table tr td,
            .zi-table table tr th {
                font-size: 15px !important;
                padding: 12px 15px;
                /* Added Padding for better spacing */
            }

            /* Restored Main Title Fonts */
            .zi-main-title h2,
            .zi-main-title-two h3 {
                font-size: 1.8em !important;
                font-weight: 600;
                margin: 0;
            }

            /* Restored Header Weights */
            .zi-table table thead th {
                font-weight: 700 !important;
            }

            .zi-main-title {
                display: flex;
                gap: 15px;
            }

            /* Tooltip wrapper */
            .zi-main-title {
                display: flex;
                gap: 15px;
            }

            .zi-customer-type table tbody tr:nth-child(n+3):nth-child(-n+10) td:last-child span {
                background-color: #d63638 !important;
                /* WordPress blue */
                color: #ffffff;
            }

            .zi-tooltip {
                position: relative;
                display: inline-flex;
                cursor: pointer;
                font-size: 14px;
                /* align-items: center; */
                width: auto;
                /* default */
            }

            .zi-tooltip img {
                height: 25px !important;
                width: 20px !important;
            }

            /* ONLY on hover */
            .zi-tooltip:hover {
                width: 50%;
            }

            /* Tooltip box (UPDATED) */
            .zi-tooltip-box {
                position: absolute;
                bottom: 130%;
                left: 2%;
                transform: translateX(-50%);
                background-color: #1f2937;
                color: #ffffff;
                padding: 10px 12px;
                border-radius: 6px;
                font-size: 18px;
                line-height: 1.5;
                /* 🔑 FIX FOR LONG CONTENT */
                max-width: 280px;
                white-space: normal;
                word-break: break-word;
                text-align: left;
                opacity: 0;
                visibility: hidden;
                transition: opacity 0.25s ease, bottom 0.25s ease;
                z-index: 9999;
            }

            /* Tooltip arrow */
            .zi-tooltip-box::after {
                content: "";
                position: absolute;
                top: 100%;
                left: 50%;
                transform: translateX(-50%);
                border-width: 6px;
                border-style: solid;
                border-color: #1f2937 transparent transparent transparent;
            }

            /* Hover effect (UPDATED) */
            .zi-tooltip:hover .zi-tooltip-box {
                opacity: 1;
                visibility: visible;
                bottom: 100%;
            }

            /* Title styles (unchanged) */
            .zi-main-title-two {
                display: flex;
                border-bottom: 2px solid #2271b1 !important;
                gap: 15px;
            }

            .zi-main-title-two .insight-header {
                border-bottom: none !important;
            }


            .date-selector-wrapper {
                background: #fff;
                border: 1px solid #ccd0d4;
                padding: 20px;
                margin: 20px 0;
            }

            .date-tabs {
                display: flex;
                border-bottom: 1px solid #ddd;
                margin-bottom: 20px;
            }

            .date-tab {
                padding: 10px 20px;
                cursor: pointer;
                border: none;
                background: none;
                font-size: 14px;
                color: #555;
            }

            .date-tab.active {
                color: #2271b1;
                border-bottom: 2px solid #2271b1;
            }

            .date-tab-content {
                display: none;
            }

            .date-tab-content.active {
                display: block;
            }

            .preset-grid {
                display: grid;
                grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
                gap: 10px;
                margin-bottom: 20px;
            }

            .preset-btn {
                padding: 12px;
                border: 1px solid #ddd;
                background: #fff;
                cursor: pointer;
            }

            .preset-btn.selected {
                background: #f0f6fc;
                border-color: #2271b1;
                font-weight: 600;
            }

            .custom-date-inputs {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 15px;
                margin-bottom: 20px;
            }

            /* Customer Type Styles */
            .zi-customer-type table tbody tr:nth-child(n+3):nth-child(-n+10) td:last-child span {
                background-color: #d63638 !important;
                color: #ffffff;
            }

            .zi-main-title-two {
                display: flex;
                border-bottom: 2px solid #2271b1 !important;
                gap: 15px;
            }

            .zi-main-title-two .insight-header {
                border-bottom: none !important;
            }

            .insight-header {
                color: #2271b1;
                margin-bottom: 15px;
                font-size: 18px;
                font-weight: 600;
                border-bottom: 2px solid #2271b1;
                padding-bottom: 10px;
            }

            .period-label {
                font-size: 20px;
                color: #666;
                margin-bottom: 10px;
                padding-top: 15px;
                font-weight: 500;
            }

            .insights-wrapper {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin-top: 20px;
                max-width: 100% !important;
            }

            .insight-card {
                background: #fff;
                border: 1px solid #ccd0d4;
                box-shadow: 0 1px 1px rgba(0, 0, 0, .04);
                padding: 20px;
            }

            tr[onclick]:hover {
                background-color: #f0f6fc !important;
                transition: background-color 0.2s;
            }

            @media (max-width: 768px) {
                .insights-wrapper {
                    grid-template-columns: 1fr;
                }
            }

            .custom-tax {
                display: none;
            }

            .no-data-msg {
                text-align: center;
                color: #777;
                font-style: italic;
                padding: 20px;
                background: #f9f9f9;
                border-radius: 4px;
                border: 1px dashed #ddd;
                margin-top: 10px;
            }
        </style>
        <div class="date-selector-wrapper">
            <h3>Select Date Range & Filters</h3>
            <form method="get" id="date-range-form">
                <input type="hidden" name="page" value="wc-client-analysis">
                <input type="hidden" name="tab" value="<?php echo esc_attr($active_tab); ?>">
                <input type="hidden" name="preset" id="preset-input" value="<?php echo esc_attr($preset); ?>">

                <?php if ($active_tab === 'products'): ?>
                    <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
                        <label for="client_filter" style="font-weight: bold; font-size: 15px;">Filter by Customer Type:</label>
                        <select name="client_filter" id="client_filter" style="margin-left: 10px; min-width: 200px;">
                            <option value="all" <?php selected($client_filter, 'all'); ?>>All Clients</option>
                            <option value="new" <?php selected($client_filter, 'new'); ?>>New Clients Only</option>
                            <option value="returning" <?php selected($client_filter, 'returning'); ?>>Returning Clients Only</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div class="date-tabs">
                    <button type="button" class="date-tab <?php echo ($preset !== 'custom') ? 'active' : ''; ?>" onclick="switchTab('presets')">Presets</button>
                    <button type="button" class="date-tab <?php echo ($preset === 'custom') ? 'active' : ''; ?>" onclick="switchTab('custom')">Custom</button>
                </div>

                <div id="presets-tab" class="date-tab-content <?php echo ($preset !== 'custom') ? 'active' : ''; ?>">
                    <div class="preset-grid">
                        <?php
                        $presets = ['today' => 'Today', 'yesterday' => 'Yesterday', 'week_to_date' => 'Week to Date', 'last_week' => 'Last Week', 'month_to_date' => 'Month to Date', 'last_month' => 'Last Month', 'quarter_to_date' => 'Quarter to Date', 'last_quarter' => 'Last Quarter', 'year_to_date' => 'Year to Date', 'last_year' => 'Last Year'];
                        foreach ($presets as $key => $label) {
                            $selected = ($preset === $key) ? 'selected' : '';
                            echo '<button type="button" class="preset-btn ' . $selected . '" onclick="selectPreset(\'' . $key . '\')">' . esc_html($label) . '</button>';
                        }
                        ?>
                    </div>
                </div>

                <div id="custom-tab" class="date-tab-content <?php echo ($preset === 'custom') ? 'active' : ''; ?>">
                    <div class="custom-date-inputs">
                        <div><label>Start Date</label><input type="text" name="custom_start" class="datepicker" value="<?php echo esc_attr($custom_start); ?>"></div>
                        <div><label>End Date</label><input type="text" name="custom_end" class="datepicker" value="<?php echo esc_attr($custom_end); ?>"></div>
                    </div>
                </div>

                <div class="compare-section">
                    <div class="compare-checkbox">
                        <label><input type="checkbox" id="enable-compare" <?php echo $compare_to ? 'checked' : ''; ?> onchange="toggleCompare()"> <strong>Compare To</strong></label>
                    </div>
                    <div class="compare-options <?php echo $compare_to ? 'active' : ''; ?>" id="compare-options" style="<?php echo $compare_to ? 'display:block' : 'display:none'; ?>; margin-left: 20px;">
                        <input type="hidden" name="compare_to" id="compare-to-input" value="<?php echo esc_attr($compare_to); ?>">
                        <div class="preset-grid">
                            <button type="button" class="preset-btn <?php echo ($compare_to === 'previous_period') ? 'selected' : ''; ?>" onclick="selectCompare('previous_period')">Previous Period</button>
                            <button type="button" class="preset-btn <?php echo ($compare_to === 'previous_year') ? 'selected' : ''; ?>" onclick="selectCompare('previous_year')">Previous Year</button>
                            <button type="button" class="preset-btn <?php echo ($compare_to === 'custom') ? 'selected' : ''; ?>" onclick="selectCompare('custom')">Custom</button>
                        </div>
                        <div id="compare-custom-dates" style="<?php echo ($compare_to === 'custom') ? '' : 'display:none;'; ?> margin-top: 15px;">
                            <div class="custom-date-inputs">
                                <div><label>Compare Start Date</label><input placeholder="YYYY-MM-DD" type="text" name="compare_custom_start" class="datepicker" value="<?php echo esc_attr($compare_custom_start); ?>"></div>
                                <div><label>
                                        Compare End Date</label><input placeholder="YYYY-MM-DD" type="text" name="compare_custom_end" class="datepicker" value="<?php echo esc_attr($compare_custom_end); ?>"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="form-actions" style="margin-top: 20px;">
                    <button type="submit" class="button button-primary button-large">📊 Generate Report</button>
                    <a href="<?php echo admin_url('admin.php?page=wc-client-analysis&tab=' . $active_tab); ?>" class="button button-secondary">Reset</a>
                </div>
            </form>
        </div>
        <script>
            jQuery(document).ready(function($) {
                $('.datepicker').datepicker({
                    dateFormat: 'yy-mm-dd',
                    maxDate: 0
                });
            });

            function switchTab(tab) {
                document.querySelectorAll('.date-tab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.date-tab-content').forEach(c => c.classList.remove('active'));
                if (tab === 'presets') {
                    document.querySelectorAll('.date-tab')[0].classList.add('active');
                    document.getElementById('presets-tab').classList.add('active');
                } else {
                    document.querySelectorAll('.date-tab')[1].classList.add('active');
                    document.getElementById('custom-tab').classList.add('active');
                    document.getElementById('preset-input').value = 'custom';
                }
            }

            function selectPreset(preset) {
                document.querySelectorAll('#presets-tab .preset-btn').forEach(btn => btn.classList.remove('selected'));
                event.target.classList.add('selected');
                document.getElementById('preset-input').value = preset;
            }

            function toggleCompare() {
                const enabled = document.getElementById('enable-compare').checked;
                const options = document.getElementById('compare-options');
                if (enabled) {
                    options.style.display = 'block';
                    if (!document.getElementById('compare-to-input').value) selectCompare('previous_period');
                } else {
                    options.style.display = 'none';
                    document.getElementById('compare-to-input').value = '';
                }
            }

            function selectCompare(type) {
                document.querySelectorAll('#compare-options .preset-btn').forEach(btn => btn.classList.remove('selected'));
                event.target.classList.add('selected');
                document.getElementById('compare-to-input').value = type;
                document.getElementById('compare-custom-dates').style.display = (type === 'custom') ? 'block' : 'none';
            }
        </script>
<?php
    }

private function render_product_analytics_display($main_range, $main_data, $comparison_range, $comparison_data, $client_filter)
    {
        $currency = get_woocommerce_currency_symbol();
        $filter_label = ($client_filter === 'new') ? 'New Clients Only' : (($client_filter === 'returning') ? 'Returning Clients Only' : 'All Clients');

        // --- CSS FOR SORTING ---
        echo '<style>
            .sortable-header {
                cursor: pointer;
                position: relative;
                padding-right: 20px !important; 
                user-select: none;
            }
            .sortable-header:hover {
                background-color: #f0f0f1;
                color: #2271b1 !important;
            }
            .sort-icon {
                font-size: 10px;
                margin-left: 5px;
                color: #ccc;
                position: absolute;
                right: 5px;
                top: 50%;
                transform: translateY(-50%);
            }
            .sortable-header.asc .sort-icon::after {
                content: "▲";
                color: #2271b1;
                font-size: 12px;
            }
            .sortable-header.desc .sort-icon::after {
                content: "▼";
                color: #2271b1;
                font-size: 12px;
            }
            /* Default state (unsorted) */
            .sortable-header:not(.asc):not(.desc) .sort-icon::after {
                content: "▼"; 
                opacity: 0.3;
            }
        </style>';

        echo '<div class="card zi-table" style="max-width: 100% !important;">';
        echo '<div class="zi-main-title">';
        echo '<h2 style="color: #2271b1;">🛒 Product Analysis</h2>';
        echo '<span class="zi-tooltip">ℹ️ <span class="zi-tooltip-box">Ranking of products sold based on the selected customer filter. Click columns to sort.</span></span>';
        echo '</div>';

        echo '<p style="font-size: 16px; margin: 10px 0;"><strong>Period:</strong> ' . esc_html($main_range['name']) . ' <span style="background:#eee; padding:2px 6px; border-radius:4px; margin-left:10px;">Filter: ' . $filter_label . '</span></p>';
        if ($comparison_range) {
            echo '<p style="font-size: 16px; color: #666; margin-bottom: 20px;"><strong>vs.</strong> ' . esc_html($comparison_range['name']) . '</p>';
        }

        if (empty($main_data)) {
            echo '<div class="no-data-msg"><p><strong>No products found.</strong><br>No items were sold during this period matching the selected filter (' . $filter_label . ').</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped" id="product-analysis-table">';
        echo '<thead><tr style="background: #f8f9fa;">';
        echo '<th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1;">Product</th>';

        // Main Headers
        echo '<th colspan="2" style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: center; border-left:1px solid #ddd;">Current Period</th>';

        if ($comparison_data) {
            echo '<th colspan="2" style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: center; border-left:1px solid #ddd;">Comparison Period</th>';
            echo '<th colspan="2" style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: center; border-left:1px solid #ddd;">Variation</th>';
        }
        echo '</tr>';

        // Sub Headers - ADDED SORTING CLASSES AND ONCLICK EVENTS
        // Note: Col Index 0 is Name.
        // Col Index 1 = Cur Units, 2 = Cur Sales
        // Col Index 3 = Prev Units, 4 = Prev Sales
        // Col Index 5 = Diff Qty, 6 = Diff Sales
        echo '<tr style="background: #fff;">';
        echo '<th></th>';
        
        // Current Period Columns
        echo '<th class="sortable-header" onclick="sortTable(1, this)" style="text-align:center; color:#666; font-size:12px; border-left:1px solid #ddd;">Elements Sold <span class="sort-icon"></span></th>';
        echo '<th class="sortable-header" onclick="sortTable(2, this)" style="text-align:center; color:#666; font-size:12px;">Net Sales <span class="sort-icon"></span></th>';
        
        if ($comparison_data) {
            // Comparison Columns
            echo '<th class="sortable-header" onclick="sortTable(3, this)" style="text-align:center; color:#666; font-size:12px; border-left:1px solid #ddd;">Elements Sold <span class="sort-icon"></span></th>';
            echo '<th class="sortable-header" onclick="sortTable(4, this)" style="text-align:center; color:#666; font-size:12px;">Net Sales <span class="sort-icon"></span></th>';
            
            // Variation Columns
            echo '<th class="sortable-header" onclick="sortTable(5, this)" style="text-align:center; color:#666; font-size:12px; border-left:1px solid #ddd;">Qty % <span class="sort-icon"></span></th>';
            echo '<th class="sortable-header" onclick="sortTable(6, this)" style="text-align:center; color:#666; font-size:12px;">Sales % <span class="sort-icon"></span></th>';
        }
        echo '</tr></thead><tbody>';

        foreach ($main_data as $pid => $data) {
            $comp_units = 0;
            $comp_sales = 0;
            if ($comparison_data && isset($comparison_data[$pid])) {
                $comp_units = $comparison_data[$pid]['units'];
                $comp_sales = $comparison_data[$pid]['sales'];
            }

            echo '<tr>';
            // Product Name
            echo '<td style="padding: 10px;"><strong>' . esc_html($data['name']) . '</strong></td>';

            // Current Period (Index 1 & 2)
            // Added data-val attribute for simpler numeric sorting
            echo '<td style="text-align:center; border-left:1px solid #ddd;" data-val="' . $data['units'] . '">' . number_format($data['units']) . '</td>';
            echo '<td style="text-align:center;" data-val="' . $data['sales'] . '"><strong>' . $currency . number_format($data['sales'], 2) . '</strong></td>';

            // Comparison & Variation
            if ($comparison_data) {
                // Comparison (Index 3 & 4)
                echo '<td style="text-align:center; color:#666; border-left:1px solid #ddd;" data-val="' . $comp_units . '">' . number_format($comp_units) . '</td>';
                echo '<td style="text-align:center; color:#666;" data-val="' . $comp_sales . '">' . $currency . number_format($comp_sales, 2) . '</td>';

                $diff_qty = $this->calculate_change($data['units'], $comp_units);
                $diff_sales = $this->calculate_change($data['sales'], $comp_sales);

                // Variation (Index 5 & 6)
                echo '<td style="text-align:center; border-left:1px solid #ddd;" data-val="' . $diff_qty . '">' . $this->render_change_badge($diff_qty) . '</td>';
                echo '<td style="text-align:center;" data-val="' . $diff_sales . '">' . $this->render_change_badge($diff_sales) . '</td>';
            }
            echo '</tr>';
        }

        echo '</tbody></table></div>';

        // --- JAVASCRIPT FOR SORTING ---
        echo '<script>
        function sortTable(n, header) {
            var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
            table = document.getElementById("product-analysis-table");
            switching = true;
            dir = "desc"; // Set the default sorting direction to descending (Highest first)

            // Reset other headers
            var headers = document.querySelectorAll(".sortable-header");
            headers.forEach(h => {
                if(h !== header) {
                    h.classList.remove("asc", "desc");
                }
            });

            while (switching) {
                switching = false;
                rows = table.rows;
                // Loop through all table rows (except the first two header rows)
                for (i = 2; i < (rows.length - 1); i++) {
                    shouldSwitch = false;
                    
                    // Get cells
                    x = rows[i].getElementsByTagName("TD")[n];
                    y = rows[i + 1].getElementsByTagName("TD")[n];

                    // Get values from data-val if available (clean numeric), else text
                    var xVal = x.getAttribute("data-val") ? parseFloat(x.getAttribute("data-val")) : 0;
                    var yVal = y.getAttribute("data-val") ? parseFloat(y.getAttribute("data-val")) : 0;

                    if (dir == "asc") {
                        if (xVal > yVal) {
                            shouldSwitch = true;
                            break;
                        }
                    } else if (dir == "desc") {
                        if (xVal < yVal) {
                            shouldSwitch = true;
                            break;
                        }
                    }
                }
                if (shouldSwitch) {
                    rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                    switching = true;
                    switchcount++;
                } else {
                    if (switchcount == 0 && dir == "desc") {
                        dir = "asc";
                        switching = true;
                    }
                }
            }
            
            // Update visual class
            if (dir === "asc") {
                header.classList.remove("desc");
                header.classList.add("asc");
            } else {
                header.classList.remove("asc");
                header.classList.add("desc");
            }
        }
        </script>';
    }

    private function render_analytics_display($main_range, $main_data, $comparison_range, $comparison_data)
    {
        $currency_symbol = get_woocommerce_currency_symbol();

        echo '<div class="card zi-table" style="margin-bottom: 20px; max-width: 100% !important;">';
        echo '<div class="zi-main-title"><h2 style="color: #2271b1;">📈 Client Analysis Report</h2><span class="zi-tooltip">ℹ️<span class="zi-tooltip-box">New customers: first order placed during the selected timeframe<br>Unique customers: number of distinct customers who placed one or plus orders within the selected timeframe</span></span></div>';
        echo '<p style="font-size: 20px; margin-bottom: 10px;"><strong>Period:</strong> ' . esc_html($main_range['name']) . '</p>';
        if ($comparison_range) echo '<p style="font-size: 20px; color: #666; margin-bottom: 20px;"><strong>vs.</strong> ' . esc_html($comparison_range['name']) . '</p>';

        // Metrics Table
        echo '<table class="widefat striped" style="margin-bottom: 20px;"><thead><tr style="background: #f8f9fa;"><th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1;">Key Metrics</th><th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: right;">Current Period</th>';
        if ($comparison_data) echo '<th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: right;">Comparison Period</th><th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: center;">Change</th>';
        echo '</tr></thead><tbody>';

        $metrics = [
            ['Total Orders', $main_data['total_orders'], ($comparison_data ? $comparison_data['total_orders'] : 0)],
            ['Unique Customers', $main_data['unique_clients'], ($comparison_data ? $comparison_data['unique_clients'] : 0)],
            ['New Customers', $main_data['new_clients'], ($comparison_data ? $comparison_data['new_clients'] : 0)],
            ['Returning Customers', $main_data['unique_clients'] - $main_data['new_clients'], ($comparison_data ? $comparison_data['unique_clients'] - $comparison_data['new_clients'] : 0)]
        ];

        foreach ($metrics as $m) {
            echo '<tr><td style="padding: 12px 15px;"><strong>' . $m[0] . '</strong></td><td style="padding: 12px 15px; text-align: right;"><span style="font-size: 1.2em; font-weight: bold; color: #2271b1;">' . number_format($m[1]) . '</span></td>';
            if ($comparison_data) {
                $change = $this->calculate_change($m[1], $m[2]);
                echo '<td style="padding: 12px 15px; text-align: right;">' . number_format($m[2]) . '</td><td style="padding: 12px 15px; text-align: center;">' . $this->render_change_badge($change) . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // REVENUE ANALYSIS
        echo '<div class="card zi-table tax-table" style="margin-bottom: 20px; max-width: 100% !important;">';
        echo '<div class="zi-main-title"><h2 style="color: #2271b1; margin-bottom: 20px;">💰 Revenue Analysis</h2><span class="zi-tooltip">ℹ️<span class="zi-tooltip-box">Net sales + shipping<br>VAT excluded</span></span></div>';
        echo '<table class="widefat striped" style="margin-bottom: 20px;"><thead><tr style="background: #f8f9fa;"><th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1;">Revenue Metrics</th><th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: right;">Amount</th><th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: right;">Percentage</th>';
        if ($comparison_data) echo '<th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: right;">Comparison Period</th><th style="padding: 15px; font-weight: bold; border-bottom: 2px solid #2271b1; text-align: center;">Change</th>';
        echo '</tr></thead><tbody>';

        $new_rev_pct = $main_data['total_revenue'] > 0 ? round(($main_data['revenue_new_customers'] / $main_data['total_revenue']) * 100, 1) : 0;
        $ret_rev_pct = $main_data['total_revenue'] > 0 ? round(($main_data['revenue_returning_customers'] / $main_data['total_revenue']) * 100, 1) : 0;

        $rev_rows = [
            ['Total Revenue (Net)', $main_data['total_revenue'], '100%', ($comparison_data ? $comparison_data['total_revenue'] : 0), null, '#2271b1'],
            ['Total Tax', $main_data['total_tax'], '-', ($comparison_data ? $comparison_data['total_tax'] : 0), null, '#000'],
            ['New Customer Revenue (Net)', $main_data['revenue_new_customers'], $new_rev_pct . '%', ($comparison_data ? $comparison_data['revenue_new_customers'] : 0), '#00a32a', '#00a32a'],
            ['Returning Customer Revenue (Net)', $main_data['revenue_returning_customers'], $ret_rev_pct . '%', ($comparison_data ? $comparison_data['revenue_returning_customers'] : 0), '#2271b1', '#2271b1'],
        ];

        foreach ($rev_rows as $row) {
            echo '<tr><td style="padding: 12px 15px;"><strong>' . $row[0] . '</strong></td>';
            echo '<td style="padding: 12px 15px; text-align: right;"><span style="font-size: 1.2em; font-weight: bold; color: ' . $row[5] . ';">' . $currency_symbol . number_format($row[1], 2) . '</span></td>';
            echo '<td style="padding: 12px 15px; text-align: right;">' . ($row[4] ? '<span style="background: ' . $row[4] . '; color: white; padding: 2px 8px; border-radius: 10px; font-weight: bold;">' . $row[2] . '</span>' : $row[2]) . '</td>';

            if ($comparison_data) {
                $change = ($row[0] === 'Total Tax') ? null : $this->calculate_change($row[1], $row[3]);
                echo '<td style="padding: 12px 15px; text-align: right;">' . $currency_symbol . number_format($row[3], 2) . '</td>';
                echo '<td style="padding: 12px 15px; text-align: center;">' . ($change !== null ? $this->render_change_badge($change) : '-') . '</td>';
            }
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        // CUSTOMER INSIGHTS
        if ($main_data['unique_clients'] > 0) {
            $cols = $comparison_data ? '1fr 1fr' : '1fr';
            echo '<style>.insights-wrapper { display: grid; grid-template-columns: ' . $cols . '; gap: 20px; margin-top: 20px; max-width: 100% !important; }</style>';
            echo '<div class="insights-wrapper zi-table">';

            // Loop for Current and Comparison if exists
            $periods = [['data' => $main_data, 'label' => 'Current Period: ' . $main_range['name']]];
            if ($comparison_data && $comparison_data['unique_clients'] > 0) $periods[] = ['data' => $comparison_data, 'label' => 'Comparison Period: ' . $comparison_range['name']];

            foreach ($periods as $p) {
                $d = $p['data'];
                $ret_count = $d['unique_clients'] - $d['new_clients'];
                $new_pct = $d['unique_clients'] > 0 ? round(($d['new_clients'] / $d['unique_clients']) * 100, 1) : 0;
                $ret_pct = 100 - $new_pct;
                $avg_new = $d['new_clients'] > 0 ? round($d['revenue_new_customers'] / $d['new_clients'], 2) : 0;
                $avg_ret = $ret_count > 0 ? round($d['revenue_returning_customers'] / $ret_count, 2) : 0;

                echo '<div class="insight-card"><div class="zi-main-title-two"><h3 class="insight-header">📊 Customer Insights</h3></div>';
                echo '<div class="period-label">' . $p['label'] . '</div>';
                echo '<table class="widefat striped"><thead><tr style="background: #f8f9fa;"><th>Customer Type</th><th style="text-align:center">Count</th><th style="text-align:center">Avg Revenue</th><th style="text-align:center">Percentage</th></tr></thead><tbody>';
                echo '<tr><td><strong>🆕 New Customers</strong></td><td style="text-align:center">' . number_format($d['new_clients']) . '</td><td style="text-align:center; color:#00a32a; font-weight:bold;">' . $currency_symbol . $avg_new . '</td><td style="text-align:center"><span style="background:#00a32a;color:white;padding:2px 8px;border-radius:10px;font-weight:bold;">' . $new_pct . '%</span></td></tr>';
                echo '<tr><td><strong>🔄 Returning Customers</strong></td><td style="text-align:center">' . number_format($ret_count) . '</td><td style="text-align:center; color:#2271b1; font-weight:bold;">' . $currency_symbol . $avg_ret . '</td><td style="text-align:center"><span style="background:#2271b1;color:white;padding:2px 8px;border-radius:10px;font-weight:bold;">' . $ret_pct . '%</span></td></tr>';
                echo '</tbody></table></div>';
            }
            echo '</div>';
        }

        // CUSTOMER SEGMENTATION (LIFETIME)
        echo '<div class="insights-wrapper zi-table" style="margin-top: 20px;">';
        $seg_periods = [['data' => $main_data, 'label' => 'Current Period: ' . $main_range['name']]];
        if ($comparison_data && isset($comparison_data['customer_segments'])) $seg_periods[] = ['data' => $comparison_data, 'label' => 'Comparison Period: ' . $comparison_range['name']];

        foreach ($seg_periods as $p) {
            echo '<div class="insight-card zi-customer-type"><div class="zi-main-title-two"><h3 class="insight-header">🎯 Customer Type</h3><span class="zi-tooltip">ℹ️<span class="zi-tooltip-box">Shows a picture of the selected timeframe, considering orders placed since year 1 of Live Better ecommerce</span></span></div>';
            echo '<div class="period-label">' . $p['label'] . '</div>';

            if (empty($p['data']['customer_segments'])) {
                echo '<div class="no-data-msg">No segmentation data available.</div>';
                echo '</div>';
                continue;
            }

            echo '<table class="widefat striped"><thead><tr style="background: #f8f9fa;"><th>Customer Type</th><th>Range</th><th style="text-align:center">Count</th><th style="text-align:center">Percentage</th></tr></thead><tbody>';
            foreach ($p['data']['customer_segments'] as $segment) {
                $segment_url = site_url('/wp-admin/admin.php?page=wc-admin&path=%2Fcustomers&filter=advanced&orderby=date_last_active&order=asc');
                if ($segment['min'] === $segment['max']) $segment_url .= '&orders_count_between%5B0%5D=' . $segment['min'] . '&orders_count_between%5B1%5D=' . $segment['max'];
                else $segment_url .= '&orders_count_min=' . $segment['min'];

                echo '<tr style="cursor: pointer;" onclick="window.open(\'' . esc_js($segment_url) . '\', \'_self\')">';
                echo '<td><strong>' . esc_html($segment['label']) . '</strong></td><td>' . esc_html($segment['range']) . '</td><td style="text-align:center">' . number_format($segment['count']) . '</td><td style="text-align:center"><span style="background:' . $this->get_segment_color($segment['label']) . ';color:white;padding:2px 8px;border-radius:10px;font-weight:bold;">' . $segment['percentage'] . '%</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // CUSTOMER SEGMENTATION (RANGE / PERIOD ONLY)
        echo '<div class="insights-wrapper zi-table" style="margin-top: 20px;">';
        foreach ($seg_periods as $p) {
            if (!isset($p['data']['customer_segments_range'])) continue;
            echo '<div class="insight-card zi-customer-type"><div class="zi-main-title-two"><h3 class="insight-header">📊 Customer Type Range</h3></div>';
            echo '<div class="period-label">' . $p['label'] . '</div>';

            if (empty($p['data']['customer_segments_range'])) {
                echo '<div class="no-data-msg">No range data available.</div>';
                echo '</div>';
                continue;
            }

            echo '<table class="widefat striped"><thead><tr style="background: #f8f9fa;"><th>Customer Type</th><th>Range</th><th style="text-align:center">Count</th><th style="text-align:center">Percentage</th></tr></thead><tbody>';
            foreach ($p['data']['customer_segments_range'] as $segment) {
                // Link logic same as above
                $segment_url = site_url('/wp-admin/admin.php?page=wc-admin&path=%2Fcustomers&filter=advanced&orderby=date_last_active&order=asc');
                if ($segment['min'] === $segment['max']) $segment_url .= '&orders_count_between%5B0%5D=' . $segment['min'] . '&orders_count_between%5B1%5D=' . $segment['max'];
                else $segment_url .= '&orders_count_min=' . $segment['min'];

                echo '<tr style="cursor: pointer;" onclick="window.open(\'' . esc_js($segment_url) . '\', \'_self\')">';
                echo '<td><strong>' . esc_html($segment['label']) . '</strong></td><td>' . esc_html($segment['range']) . '</td><td style="text-align:center">' . number_format($segment['count']) . '</td><td style="text-align:center"><span style="background:' . $this->get_segment_color($segment['label']) . ';color:white;padding:2px 8px;border-radius:10px;font-weight:bold;">' . $segment['percentage'] . '%</span></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';

        // PAYMENT METHODS
        echo '<div class="insights-wrapper zi-table" style="margin-top: 20px;">';
        foreach ($seg_periods as $p) {
            echo '<div class="insight-card"><h3 class="insight-header">💳 Payment Methods</h3>';
            echo '<div class="period-label">' . $p['label'] . '</div>';
            if ($p['data']['payment_methods']) {
                echo '<table class="widefat striped"><thead><tr style="background: #f8f9fa;"><th>Method</th><th style="text-align:center">Orders</th><th style="text-align:center">Percentage</th></tr></thead><tbody>';
                foreach ($p['data']['payment_methods'] as $pm) {
                    $pct = $p['data']['total_orders'] > 0 ? round(($pm->count / $p['data']['total_orders']) * 100, 1) : 0;
                    echo '<tr><td><strong>' . $this->get_payment_gateway_title($pm->payment_method) . '</strong></td><td style="text-align:center">' . number_format($pm->count) . '</td><td style="text-align:center"><span style="background:#2271b1;color:white;padding:2px 8px;border-radius:10px;font-weight:bold;">' . $pct . '%</span></td></tr>';
                }
                echo '</tbody></table>';
            } else {
                echo '<div class="no-data-msg">No payment method data available.</div>';
            }
            echo '</div>';
        }
        echo '</div>';
    }

    // --- EXPORT LOGIC ---

    private function render_export_form($active_tab, $preset, $custom_start, $custom_end, $compare_to, $compare_custom_start, $compare_custom_end, $client_filter)
    {
        echo '<div class="card zi-export-excel-btn" style="margin-top: 20px; text-align: center; max-width: 100% !important;">';
        echo '<form method="post" action="' . admin_url('admin-post.php') . '">';
        echo '<input type="hidden" name="action" value="wc_client_analysis_export">';
        echo '<input type="hidden" name="tab" value="' . esc_attr($active_tab) . '">';
        echo '<input type="hidden" name="preset" value="' . esc_attr($preset) . '">';
        echo '<input type="hidden" name="custom_start" value="' . esc_attr($custom_start) . '">';
        echo '<input type="hidden" name="custom_end" value="' . esc_attr($custom_end) . '">';
        echo '<input type="hidden" name="compare_to" value="' . esc_attr($compare_to) . '">';
        echo '<input type="hidden" name="compare_custom_start" value="' . esc_attr($compare_custom_start) . '">';
        echo '<input type="hidden" name="compare_custom_end" value="' . esc_attr($compare_custom_end) . '">';
        echo '<input type="hidden" name="client_filter" value="' . esc_attr($client_filter) . '">';
        wp_nonce_field('wc_client_analysis_export', 'wc_client_analysis_nonce');
        echo '<input type="submit" class="button button-primary button-large" value="📥 Export ' . ucfirst($active_tab) . ' Report as CSV">';
        echo '</form></div>';
    }

    public function export_csv()
    {
        if (!isset($_POST['wc_client_analysis_nonce']) || !wp_verify_nonce($_POST['wc_client_analysis_nonce'], 'wc_client_analysis_export')) wp_die('Security check failed.');
        if (!current_user_can('manage_woocommerce')) wp_die('Permission denied.');

        // Clear buffer to prevent corruption
        if (ob_get_length()) ob_end_clean();

        try {
            $tab = isset($_POST['tab']) ? sanitize_text_field($_POST['tab']) : 'client';
            $preset = isset($_POST['preset']) ? sanitize_text_field($_POST['preset']) : 'last_month';
            $custom_start = isset($_POST['custom_start']) ? sanitize_text_field($_POST['custom_start']) : '';
            $custom_end = isset($_POST['custom_end']) ? sanitize_text_field($_POST['custom_end']) : '';
            $compare_to = isset($_POST['compare_to']) ? sanitize_text_field($_POST['compare_to']) : '';
            $compare_custom_start = isset($_POST['compare_custom_start']) ? sanitize_text_field($_POST['compare_custom_start']) : '';
            $compare_custom_end = isset($_POST['compare_custom_end']) ? sanitize_text_field($_POST['compare_custom_end']) : '';
            $client_filter = isset($_POST['client_filter']) ? sanitize_text_field($_POST['client_filter']) : 'all';

            $main_range = $this->get_date_range($preset, $custom_start, $custom_end);
            $comparison_range = $compare_to ? $this->get_comparison_date_range($compare_to, $preset, $main_range['start'], $main_range['end'], $compare_custom_start, $compare_custom_end) : null;

            $filename = 'report-' . $tab . '-' . date('Y-m-d') . '.csv';
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            $output = fopen('php://output', 'w');
            fputs($output, "\xEF\xBB\xBF"); // BOM

            if ($tab === 'products') {
                // ... (Product Logic Same as before) ...
                $main_data = $this->get_product_analytics_data($main_range['start'], $main_range['end'], $client_filter);
                $comparison_data = $comparison_range ? $this->get_product_analytics_data($comparison_range['start'], $comparison_range['end'], $client_filter) : null;

                fputcsv($output, ['Product Analysis Report']);
                fputcsv($output, ['Filter: ' . $client_filter]);
                fputcsv($output, ['Period: ' . $main_range['name']]);
                fputcsv($output, []);

                $headers = ['Product Name', 'Current Units', 'Current Revenue'];
                if ($comparison_data) {
                    $headers = array_merge($headers, ['Previous Units', 'Previous Revenue', 'Units Change %', 'Revenue Change %']);
                }
                fputcsv($output, $headers);

                foreach ($main_data as $pid => $data) {
                    $row = [$data['name'], $data['units'], $data['sales']];
                    if ($comparison_data) {
                        $comp_units = isset($comparison_data[$pid]) ? $comparison_data[$pid]['units'] : 0;
                        $comp_sales = isset($comparison_data[$pid]) ? $comparison_data[$pid]['sales'] : 0;
                        $row[] = $comp_units;
                        $row[] = $comp_sales;
                        $row[] = $this->calculate_change($data['units'], $comp_units) . '%';
                        $row[] = $this->calculate_change($data['sales'], $comp_sales) . '%';
                    }
                    fputcsv($output, $row);
                }
            } else {
                // --- CLIENT ANALYSIS EXPORT ---
                $main_data = $this->get_analytics_data($main_range['start'], $main_range['end']);
                $comparison_data = $comparison_range ? $this->get_analytics_data($comparison_range['start'], $comparison_range['end']) : null;

                fputcsv($output, ['Client Analysis Report']);
                fputcsv($output, ['Period: ' . $main_range['name']]);
                if ($comparison_range) fputcsv($output, ['Comparison Period: ' . $comparison_range['name']]);
                fputcsv($output, []);

                // 1. KEY METRICS
                $headers = ['Metric', 'Current Period'];
                if ($comparison_data) {
                    $headers[] = 'Comparison Period';
                    $headers[] = 'Change (%)';
                }
                fputcsv($output, $headers);

                $metrics = [
                    ['Total Orders', 'total_orders'],
                    ['Unique Clients', 'unique_clients'],
                    ['New Clients', 'new_clients'],
                ];

                foreach ($metrics as $m) {
                    $row = [$m[0], $main_data[$m[1]]];
                    if ($comparison_data) {
                        $row[] = $comparison_data[$m[1]];
                        $row[] = $this->calculate_change($main_data[$m[1]], $comparison_data[$m[1]]) . '%';
                    }
                    fputcsv($output, $row);
                }

                // Add Returning Clients row specifically
                $ret_curr = $main_data['unique_clients'] - $main_data['new_clients'];
                $row_ret = ['Returning Clients', $ret_curr];
                if ($comparison_data) {
                    $ret_comp = $comparison_data['unique_clients'] - $comparison_data['new_clients'];
                    $row_ret[] = $ret_comp;
                    $row_ret[] = $this->calculate_change($ret_curr, $ret_comp) . '%';
                }
                fputcsv($output, $row_ret);


                // 2. REVENUE ANALYSIS
                fputcsv($output, []);
                fputcsv($output, ['Revenue Analysis']);
                $rev_headers = ['Metric', 'Current Amount'];
                if ($comparison_data) {
                    $rev_headers[] = 'Comparison Amount';
                    $rev_headers[] = 'Change (%)';
                }
                fputcsv($output, $rev_headers);

                $rev_rows = [
                    ['Total Revenue (Net)', 'total_revenue'],
                    ['Total Tax', 'total_tax'],
                    ['New Customer Revenue', 'revenue_new_customers'],
                    ['Returning Customer Revenue', 'revenue_returning_customers'],
                ];

                foreach ($rev_rows as $r) {
                    $curr = $main_data[$r[1]];
                    $row = [$r[0], number_format($curr, 2)];
                    if ($comparison_data) {
                        $comp = $comparison_data[$r[1]];
                        $row[] = number_format($comp, 2);
                        if ($r[0] == 'Total Tax') $row[] = '-';
                        else $row[] = $this->calculate_change($curr, $comp) . '%';
                    }
                    fputcsv($output, $row);
                }

                // 3. CUSTOMER INSIGHTS (Comparison Added)
                fputcsv($output, []);
                fputcsv($output, ['Customer Insights - Current Period: ' . $main_range['name']]);
                fputcsv($output, ['Type', 'Count', 'Avg Revenue', 'Percentage']);

                // Current Data Helper
                $this->export_insight_rows($output, $main_data);

                if ($comparison_data) {
                    fputcsv($output, []); // Spacer
                    fputcsv($output, ['Customer Insights - Comparison Period: ' . $comparison_range['name']]);
                    fputcsv($output, ['Type', 'Count', 'Avg Revenue', 'Percentage']);
                    // Comparison Data Helper
                    $this->export_insight_rows($output, $comparison_data);
                }

                // 4. SEGMENTS LIFETIME (Comparison Added)
                fputcsv($output, []);
                fputcsv($output, ['Segments (Lifetime) - Current Period: ' . $main_range['name']]);
                fputcsv($output, ['Customer Type', 'Range', 'Count', 'Percentage']);
                foreach ($main_data['customer_segments'] as $s) {
                    fputcsv($output, [$s['label'], $s['range'], $s['count'], $s['percentage'] . '%']);
                }

                if ($comparison_data && isset($comparison_data['customer_segments'])) {
                    fputcsv($output, []);
                    fputcsv($output, ['Segments (Lifetime) - Comparison Period: ' . $comparison_range['name']]);
                    fputcsv($output, ['Customer Type', 'Range', 'Count', 'Percentage']);
                    foreach ($comparison_data['customer_segments'] as $s) {
                        fputcsv($output, [$s['label'], $s['range'], $s['count'], $s['percentage'] . '%']);
                    }
                }

                // 5. SEGMENTS RANGE (Comparison Added)
                if (isset($main_data['customer_segments_range'])) {
                    fputcsv($output, []);
                    fputcsv($output, ['Segments (Selected Period) - Current Period: ' . $main_range['name']]);
                    fputcsv($output, ['Customer Type', 'Range', 'Count', 'Percentage']);
                    foreach ($main_data['customer_segments_range'] as $s) {
                        fputcsv($output, [$s['label'], $s['range'], $s['count'], $s['percentage'] . '%']);
                    }

                    if ($comparison_data && isset($comparison_data['customer_segments_range'])) {
                        fputcsv($output, []);
                        fputcsv($output, ['Segments (Selected Period) - Comparison Period: ' . $comparison_range['name']]);
                        fputcsv($output, ['Customer Type', 'Range', 'Count', 'Percentage']);
                        foreach ($comparison_data['customer_segments_range'] as $s) {
                            fputcsv($output, [$s['label'], $s['range'], $s['count'], $s['percentage'] . '%']);
                        }
                    }
                }

                // 6. PAYMENT METHODS (Comparison Added)
                fputcsv($output, []);
                fputcsv($output, ['Payment Methods - Current Period: ' . $main_range['name']]);
                fputcsv($output, ['Method', 'Count', 'Percentage']);
                foreach ($main_data['payment_methods'] as $p) {
                    $pct = $main_data['total_orders'] > 0 ? round(($p->count / $main_data['total_orders']) * 100, 1) : 0;
                    fputcsv($output, [$this->get_payment_gateway_title($p->payment_method), $p->count, $pct . '%']);
                }

                if ($comparison_data && isset($comparison_data['payment_methods'])) {
                    fputcsv($output, []);
                    fputcsv($output, ['Payment Methods - Comparison Period: ' . $comparison_range['name']]);
                    fputcsv($output, ['Method', 'Count', 'Percentage']);
                    foreach ($comparison_data['payment_methods'] as $p) {
                        $pct = $comparison_data['total_orders'] > 0 ? round(($p->count / $comparison_data['total_orders']) * 100, 1) : 0;
                        fputcsv($output, [$this->get_payment_gateway_title($p->payment_method), $p->count, $pct . '%']);
                    }
                }
            }

            fclose($output);
            exit;
        } catch (Exception $e) {
            wp_die('Export failed.');
        }
    }

    // Helper function for cleaner code inside export_csv
    private function export_insight_rows($output, $data)
    {
        $avg_new = $data['new_clients'] > 0 ? round($data['revenue_new_customers'] / $data['new_clients'], 2) : 0;
        $pct_new = $data['unique_clients'] > 0 ? round(($data['new_clients'] / $data['unique_clients']) * 100, 1) : 0;
        fputcsv($output, ['New Customers', $data['new_clients'], $avg_new, $pct_new . '%']);

        $ret_count = $data['unique_clients'] - $data['new_clients'];
        $avg_ret = $ret_count > 0 ? round($data['revenue_returning_customers'] / $ret_count, 2) : 0;
        $pct_ret = 100 - $pct_new;
        fputcsv($output, ['Returning Customers', $ret_count, $avg_ret, $pct_ret . '%']);
    }
}

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        new WC_Client_Analysis_Optimized();
    }
});
