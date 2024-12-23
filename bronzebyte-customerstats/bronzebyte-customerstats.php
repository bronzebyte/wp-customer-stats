<?php
/**
 * Plugin Name: BronzeByte CustomerStats
 * Description: A plugin to add custom account statistics to the WooCommerce customer dashboard with Total Orders, Total Sales, Etc.. Wishlist, and Graphs.
 * Version: 1.2
 * Author: BronzeByte CustomerStats
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', 'bb_check_woocommerce_dependency', 10);
function bb_check_woocommerce_dependency() {
    if (!class_exists('WooCommerce')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            esc_html_e('BronzeByte CustomerStats requires WooCommerce to be installed and activated. The plugin has been deactivated.', 'bronzebyte');
            echo '</p></div>';
        });
        add_action('admin_init', function () {
            deactivate_plugins(plugin_basename(__FILE__));
            if (isset($_GET['activate'])) {
                unset($_GET['activate']);
            }
        });
        return;
    }
    bb_initialize_plugin();
}

function bb_initialize_plugin() {
    add_action('init', 'bb_add_custom_dashboard_endpoint');
    add_filter('woocommerce_account_menu_items', 'bb_add_custom_dashboard_link');
    add_action('woocommerce_account_customer-stats_endpoint', 'bb_custom_dashboard_content');
    add_action('template_redirect', 'bb_handle_export_request');
    register_activation_hook(__FILE__, 'bb_flush_rewrite_rules');
    register_deactivation_hook(__FILE__, 'flush_rewrite_rules');
    add_action('admin_menu', 'bb_add_admin_menu');
    add_action('admin_init', 'bb_register_settings');
}

function bb_add_admin_menu() {
    add_menu_page(
        __('CustomerStats Settings', 'bronzebyte'), 
        __('CustomerStats', 'bronzebyte'), 
        'manage_options', 
        'customerstats_settings', 
        'bb_settings_page', 
        'dashicons-chart-bar', 
        60
    );
}

function bb_register_settings() {
    register_setting('bb_customer_stats_options', 'bb_enable_customer_stats', [
        'type' => 'boolean',
        'default' => true,
    ]);
}

function bb_settings_page() {
    ?>
    <div class="wrap">
        <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('bb_customer_stats_options');
            do_settings_sections('bb_customer_stats_options');
            ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row"><?php _e('Enable Customer My Detail Link', 'bronzebyte'); ?></th>
                    <td>
                        <input type="checkbox" name="bb_enable_customer_stats" value="1" <?php checked(1, get_option('bb_enable_customer_stats'), true); ?> />
                        <label for="bb_enable_customer_stats"><?php _e('Enable the Customer My Detail Link for users', 'bronzebyte'); ?></label>
                    </td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

function bb_add_custom_dashboard_endpoint() {
    if (get_option('bb_enable_customer_stats', true)) {
        add_rewrite_endpoint('customer-stats', EP_ROOT | EP_PAGES);
    }
}

function bb_add_custom_dashboard_link($items) {
    return $items;
}

add_action('woocommerce_account_dashboard', 'bb_custom_dashboard_content', 15); 

function get_user_wishlist_items($user_id) {
    if (class_exists('YITH_WCWL')) {
        $wishlist = YITH_WCWL()->get_products();
        return count($wishlist);
    }
    return 0;
}

function bb_export_recent_orders_to_csv() {
    if (!is_user_logged_in()) {
        return;
    }
    $current_user = wp_get_current_user();
    $customer_orders = wc_get_orders(array(
        'customer_id' => $current_user->ID,
        'limit' => 10, 
        'orderby' => 'date',
        'order' => 'DESC'
    ));
    if (empty($customer_orders)) {
        return;
    }
    $csv_data = array(
        array('Order ID', 'Date', 'Status', 'Total', 'Items')
    );
    foreach ($customer_orders as $order) {
        $order_items = '';
        foreach ($order->get_items() as $item) {
            $order_items .= $item->get_name() . ' (x' . $item->get_quantity() . '), ';
        }
        $csv_data[] = array(
            $order->get_id(),
            $order->get_date_created()->date('Y-m-d H:i:s'),
            $order->get_status(),
            $order->get_total(),
            rtrim($order_items, ', ')
        );
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="recent_orders.csv"');
    $output = fopen('php://output', 'w');
    foreach ($csv_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

function bb_add_export_button() {
    // Only show the button if the user is logged in
    if (is_user_logged_in()) {
        echo '<form method="post" action="">
                <button type="submit" name="bb_export_orders" class="button" style="color: red; border-radius: 45px;">' . __('Order Details', 'bronzebyte') . '</button>
              </form>';
    }
}

// Add export button before dashboard content
add_action('woocommerce_account_customer-stats_endpoint', 'bb_add_export_button', 5);

// Handle export request
function bb_handle_export_request() {
    if (isset($_POST['bb_export_orders'])) {
        bb_export_recent_orders_to_csv();
    }
}

function bb_custom_dashboard_content() {
    if (!get_option('bb_enable_customer_stats', true)) {
        return; 
    }
    $current_user = wp_get_current_user();
    $customer_orders = wc_get_orders(array(
        'customer_id' => $current_user->ID,
        'limit' => -1,  
    ));
    $total_orders = count($customer_orders);
    $total_sales = 0;
    $total_payment = 0; 
    $order_status_count = array('completed' => 0, 'pending' => 0, 'cancelled' => 0, 'processing' => 0); 
    $sales_per_month = array_fill(1, 12, 0); 
    $product_sales_count = array(); 
    foreach ($customer_orders as $order) {
        $total_sales += $order->get_total();
        $total_payment += $order->get_total() - $order->get_total_refunded(); 
        $order_status = $order->get_status();
        if (isset($order_status_count[$order_status])) {
            $order_status_count[$order_status]++;
        }
        $order_month = date('n', strtotime($order->get_date_created())); 
        $sales_per_month[$order_month] += $order->get_total();
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            if (!isset($product_sales_count[$product_id])) {
                $product_sales_count[$product_id] = 0;
            }
            $product_sales_count[$product_id] += $item->get_quantity();
        }
    }
    arsort($product_sales_count);
    $top_products = array_slice($product_sales_count, 0, 3, true); 
    $sales_history_data = array_values($sales_per_month);
    $top_product_labels = array_map(function ($product_id) {
        return get_the_title($product_id); 
    }, array_keys($top_products));
    $top_product_sales = array_values($top_products);
    $order_status_labels = ['Completed', 'Pending', 'Cancelled', 'Processing']; 
    $order_status_values = array_values($order_status_count);
    $wishlist_items = get_user_wishlist_items($current_user->ID);

    echo '<div class="container">';
    echo '<h2 class="title">' . __('Details', 'bronzebyte') . '</h2>';
    
    echo '<div class="stats-container">';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-icon">🛒</div>';
    echo '<p class="stat-value">' . esc_html($total_orders) . '</p>';
    echo '<h3 class="stat-title">' . __('Total Orders', 'bronzebyte') . '</h3>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-icon">💰</div>';
    echo '<h3 class="stat-title">' . __('Total Sales', 'bronzebyte') . '</h3>';
    echo '<p class="stat-value">' . wc_price($total_sales) . '</p>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-icon">❤️</div>';
    echo '<h3 class="stat-title">' . __('Wishlist Items', 'bronzebyte') . '</h3>';
    echo '<p class="stat-value">' . esc_html($wishlist_items) . '</p>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-icon">❌</div>';
    echo '<h3 class="stat-title">' . __('Total Cancelled Orders', 'bronzebyte') . '</h3>';
    echo '<p class="stat-value">' . esc_html($order_status_count['cancelled']) . '</p>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-icon">💳</div>';
    echo '<h3 class="stat-title">' . __('Total Payment', 'bronzebyte') . '</h3>';
    echo '<p class="stat-value">' . wc_price($total_payment) . '</p>';
    echo '</div>';
    
    echo '<div class="stat-box">';
    echo '<div class="stat-icon">🚚</div>';
    echo '<h3 class="stat-title">' . __('Open Orders', 'bronzebyte') . '</h3>';
    echo '<p class="stat-value">' . esc_html($order_status_count['processing']) . '</p>';
    echo '</div>';
    
    echo '</div>';
    
    echo '<div class="report-section">';
    echo '<h3 class="report-title">' . __('Sales Report History', 'bronzebyte') . '</h3>';
    echo '<canvas id="salesHistoryChart" class="chart"></canvas>';
    echo '</div>';
    
    echo '<div class="chart-container">';
    echo '<div class="chart-box">';
    echo '<h3 class="chart-title">' . __('Monthly Top Products', 'bronzebyte') . '</h3>';
    echo '<canvas id="topProductsChart" class="chart"></canvas>';
    echo '</div>';
    echo '<div class="chart-box">';
    echo '<h3 class="chart-title">' . __('Order Report', 'bronzebyte') . '</h3>';
    echo '<canvas id="orderReportChart" class="chart"></canvas>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>';

    wp_enqueue_script('chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], null, true);
    wp_add_inline_script('chart-js', "
        // Wait until the DOM is fully loaded
        document.addEventListener('DOMContentLoaded', function () {

            // Set up the Sales History chart
            var salesHistoryCtx = document.getElementById('salesHistoryChart').getContext('2d');
            var salesHistoryChart = new Chart(salesHistoryCtx, {
                type: 'line', // Line chart type
                data: {
                    labels: ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'], // Months
                    datasets: [{
                        label: 'Sales History', // Title of the dataset
                        data: " . json_encode($sales_history_data) . ", // Sales data from PHP
                        borderColor: 'rgb(75, 192, 192)', // Line color
                        tension: 0.1 // Smoothness of the line
                    }]
                }
            });

            // Set up the Top Products chart
            var topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
            var topProductsChart = new Chart(topProductsCtx, {
                type: 'pie', // Pie chart type
                data: {
                    labels: " . json_encode($top_product_labels) . ", // Product labels from PHP
                    datasets: [{
                        data: " . json_encode($top_product_sales) . ", // Sales numbers for each product
                        backgroundColor: ['#ff9999', '#66b3ff', '#99ff99'] // Colors for pie chart slices
                    }]
                }
            });

            // Set up the Order Report chart
            var orderReportCtx = document.getElementById('orderReportChart').getContext('2d');
            var orderReportChart = new Chart(orderReportCtx, {
                type: 'bar', // Bar chart type
                data: {
                    labels: " . json_encode($order_status_labels) . ", // Order status labels from PHP
                    datasets: [{
                        label: 'Order Status', // Title of the dataset
                        data: " . json_encode($order_status_values) . ", // Values for each order status
                        backgroundColor: '#ffcc00' // Bar color
                    }]
                }
            });
        });
    ");
}

add_action('woocommerce_account_customer-stats_endpoint', 'bb_custom_dashboard_content');

// Flush rewrite rules on activation
function bb_flush_rewrite_rules() {
    bb_add_custom_dashboard_endpoint();
    flush_rewrite_rules();
}

register_activation_hook(__FILE__, 'bb_flush_rewrite_rules');
register_deactivation_hook(__FILE__, 'flush_rewrite_rules');

// Enqueue plugin styles
function bb_enqueue_plugin_styles() {
    wp_enqueue_style('bb-customer-stats-style', plugin_dir_url(__FILE__) . 'style.css');
}

add_action('wp_enqueue_scripts', 'bb_enqueue_plugin_styles');
