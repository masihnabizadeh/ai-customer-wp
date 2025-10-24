<?php
/*
Plugin Name: دستیار هوشمند خرید کاربر
Plugin URI: https://example.com
Description: چت‌بات فارسی راهنمای خرید؛ مکالمه مرحله‌ای + کارت محصول + دکمه‌های سریع (Quick Replies).
Version: 1.0.0
Author: Honix Digital Solution
Author URI: mailto:masih.nabizadeh@gmail.com
Text Domain: dastyar-smart-shopping
*/

if (!defined('ABSPATH')) exit;

class DSS_Plugin {
    const OPT_GROUP = 'dss_options_group';
    const OPT_NAME  = 'dss_options';
    const VERSION   = '1.0.0';

    public function __construct(){
        add_action('admin_menu', [$this, 'admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chatbox']);
        add_action('admin_post_dss_build_products', [$this, 'handle_build_products']);
        add_action('wp_ajax_dss_chat_reply', [$this, 'ajax_chat_reply']);
        add_action('wp_ajax_nopriv_dss_chat_reply', [$this, 'ajax_chat_reply']);
        register_activation_hook(__FILE__, [$this, 'activate']);

        if (is_admin()) require_once plugin_dir_path(__FILE__) . 'includes/class-product-collector.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-ai-engine.php';
        require_once plugin_dir_path(__FILE__) . 'includes/class-conversation.php';
    }

    public function activate(){
        $defaults = [
            'greeting'    => 'سلام! من «دستیار هوشمند خرید» هستم. می‌خوای بر اساس قیمت، دسته‌بندی یا نوع استفاده راهنمایی‌ت کنم؟',
            'enabled'     => 'yes',
            'position'    => 'left',
            'api_key'     => '',
            'max_results' => 3
        ];
        $opts = get_option(self::OPT_NAME, []);
        update_option(self::OPT_NAME, array_merge($defaults, (array)$opts));
    }

    public function admin_menu(){
        add_menu_page('دستیار خرید','دستیار خرید','manage_options','dss-settings',[$this,'settings_page'],'dashicons-format-chat',58);
    }

    public function register_settings(){
        register_setting(self::OPT_GROUP, self::OPT_NAME);

        add_settings_section('dss_main','تنظیمات عمومی',function(){ echo '<p>پیام شروع گفتگو و موقعیت نمایش باکس را تنظیم کنید.</p>'; },'dss-settings');

        add_settings_field('dss_enabled','فعال‌سازی',function(){
            $o = get_option(self::OPT_NAME, []); $val = isset($o['enabled']) ? $o['enabled'] : 'yes';
            echo '<label><input type="checkbox" name="dss_options[enabled]" value="yes" '.( $val==='yes'?'checked':'' ).'> فعال باشد</label>';
        },'dss-settings','dss_main');

        add_settings_field('dss_greeting','پیام شروع گفتگو',function(){
            $o = get_option(self::OPT_NAME, []); $val = isset($o['greeting']) ? esc_textarea($o['greeting']) : '';
            echo '<textarea name="dss_options[greeting]" rows="3" style="width:100%">'.$val.'</textarea>';
        },'dss-settings','dss_main');

        add_settings_field('dss_position','موقعیت باکس',function(){
            $o = get_option(self::OPT_NAME, []); $val = isset($o['position']) ? $o['position'] : 'left';
            echo '<select name="dss_options[position]">
                    <option value="left" '.('left'===$val?'selected':'').'>پایینِ چپ</option>
                    <option value="right" '.('right'===$val?'selected':'').'>پایینِ راست</option>
                  </select>';
        },'dss-settings','dss_main');

        add_settings_section('dss_ai','اتصال هوش مصنوعی',function(){
            echo '<p>کلید API سرویس OpenAI را وارد کنید تا دستیار قادر به پیشنهاد محصولات باشد.</p>';
        },'dss-settings');

        add_settings_field('dss_api_key','کلید API OpenAI',function(){
            $o = get_option(self::OPT_NAME, []); $val = isset($o['api_key']) ? esc_attr($o['api_key']) : '';
            echo '<input type="password" name="dss_options[api_key]" value="'.$val.'" style="width:480px" placeholder="sk-..." />';
        },'dss-settings','dss_ai');

        add_settings_field('dss_max_results','تعداد پیشنهادها',function(){
            $o = get_option(self::OPT_NAME, []); $val = isset($o['max_results']) ? intval($o['max_results']) : 3;
            echo '<input type="number" min="1" max="5" name="dss_options[max_results]" value="'.$val.'" />';
        },'dss-settings','dss_ai');

        add_settings_section('dss_ai_data','دیتای محصولات برای AI',function(){
            if ( ! class_exists('DSS_Product_Collector') ) { echo '<p>ووکامرس/کلاس جمع‌آوری محصولات در دسترس نیست.</p>'; return; }
            echo '<p>با کلیک روی دکمه زیر، اطلاعات محصولات ووکامرس برای استفاده در هوش مصنوعی، در قالب JSON ساخته/به‌روزرسانی می‌شود.</p>';
            $nonce = wp_create_nonce('dss_build_products');
            $url = admin_url('admin-post.php?action=dss_build_products&_wpnonce='.$nonce);
            echo '<p><a href="'.$url.'" class="button button-primary">بروزرسانی دیتای محصولات</a></p>';
            $path = DSS_Product_Collector::get_json_path();
            if (file_exists($path)) { echo '<p><code>فایل فعلی:</code> '.$path.'</p>'; }
        },'dss-settings');
    }

    public function settings_page(){
        echo '<div class="wrap"><h1>دستیار هوشمند خرید کاربر</h1>';
        echo '<form method="post" action="options.php">';
        settings_fields(self::OPT_GROUP);
        do_settings_sections('dss-settings');
        submit_button();
        echo '</form></div>';
    }

    public function enqueue_assets(){
        $o = get_option(self::OPT_NAME, []);
        if (!isset($o['enabled']) || $o['enabled'] !== 'yes') return;
        wp_enqueue_style('dss-chatbox', plugins_url('assets/css/chatbox.css', __FILE__), [], self::VERSION);
        wp_enqueue_script('dss-chatbox', plugins_url('assets/js/chatbox.js', __FILE__), ['jquery'], self::VERSION, true);
        $nonce = wp_create_nonce('dss_chat_nonce');
        wp_localize_script('dss-chatbox', 'DSS_CONFIG', [
            'greeting' => isset($o['greeting']) ? $o['greeting'] : '',
            'position' => isset($o['position']) ? $o['position'] : 'left',
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => $nonce
        ]);
    }

    public function render_chatbox(){
        $o = get_option(self::OPT_NAME, []);
        if (!isset($o['enabled']) || $o['enabled'] !== 'yes') return;
        ?>
        <div id="dss-launcher" class="dss-launcher dss-pos-<?php echo esc_attr($o['position'] ?? 'left'); ?>" aria-label="chat launcher">?</div>
        <div id="dss-chat" class="dss-chat dss-pos-<?php echo esc_attr($o['position'] ?? 'left'); ?>" dir="rtl" aria-live="polite">
            <div class="dss-header">
                <div class="dss-title">دستیار هوشمند خرید</div>
                <button class="dss-close" aria-label="بستن">×</button>
            </div>
            <div class="dss-body">
                <div class="dss-msg dss-bot"></div>
            </div>
            <div class="dss-input">
                <input type="text" placeholder="مثلاً: گوشی برای بازی زیر ۱۰ میلیون"/>
                <button class="dss-send">ارسال</button>
            </div>
        </div>
        <?php
    }

    public function handle_build_products(){
        if ( ! current_user_can('manage_options') ) wp_die('Unauthorized');
        check_admin_referer('dss_build_products');
        $result = DSS_Product_Collector::build_products_json();
        $redirect = admin_url('admin.php?page=dss-settings');
        if ($result['ok']) {
            wp_safe_redirect(add_query_arg(['dss_built' => '1', 'count' => $result['count']], $redirect));
        } else {
            wp_safe_redirect(add_query_arg(['dss_built' => '0', 'error' => rawurlencode($result['error'])], $redirect));
        }
        exit;
    }

    public function ajax_chat_reply(){
        check_ajax_referer('dss_chat_nonce','nonce');
        $message = isset($_POST['message']) ? sanitize_text_field($_POST['message']) : '';
        $sid = isset($_POST['sid']) ? sanitize_text_field($_POST['sid']) : '';
        if (!$message){ wp_send_json_error(['error'=>'پیامی دریافت نشد']); }
        if (!$sid){ $sid = 'dss_' . wp_generate_uuid4(); }
        $reply = DSS_Conversation::handle($sid, $message);
        if ($reply['ok']){
            wp_send_json_success([
                'text'=>$reply['text'],
                'sid'=>$sid,
                'cards'=> isset($reply['cards']) ? $reply['cards'] : [],
                'quick'=> isset($reply['quick']) ? $reply['quick'] : []
            ]);
        } else {
            wp_send_json_error(['error'=>$reply['error']]);
        }
    }
}
new DSS_Plugin();
