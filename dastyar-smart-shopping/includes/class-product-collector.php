<?php
if (!defined('ABSPATH')) exit;

class DSS_Product_Collector {

    public static function get_upload_dir(){
        $ul = wp_upload_dir();
        $dir = trailingslashit($ul['basedir']) . 'dastyar-smart-shopping';
        if (!file_exists($dir)) wp_mkdir_p($dir);
        return $dir;
    }

    public static function get_json_path(){
        return trailingslashit(self::get_upload_dir()) . 'products.json';
    }

    public static function build_products_json(){
        if ( ! class_exists('WC_Product') ) {
            return ['ok'=>false, 'error'=>'ووکامرس فعال نیست.'];
        }

        $args = ['status' => 'publish', 'limit' => -1, 'return' => 'objects'];
        if ( function_exists('wc_get_products') ) {
            $products = wc_get_products($args);
        } else {
            $loop = new WP_Query(['post_type'=>'product','posts_per_page'=>-1,'post_status'=>'publish']);
            $products = [];
            while($loop->have_posts()){ $loop->the_post(); $products[] = wc_get_product(get_the_ID()); }
            wp_reset_postdata();
        }

        $data = [];
        foreach ($products as $p){
            if (! $p) continue;
            $id   = $p->get_id();
            $name = $p->get_name();
            $short = $p->get_short_description();
            $full  = $p->get_description();
            $price = $p->get_price();
            $url   = get_permalink($id);
            $img   = wp_get_attachment_image_url($p->get_image_id(), 'medium');

            $cats = wp_get_post_terms($id, 'product_cat', ['fields'=>'names']);
            $tags = wp_get_post_terms($id, 'product_tag', ['fields'=>'names']);

            $attrs = [];
            foreach ($p->get_attributes() as $key => $attr){
                if ( is_a($attr, 'WC_Product_Attribute') ) {
                    $label = wc_attribute_label( $attr->get_name() );
                    if ( $attr->is_taxonomy() ) {
                        $terms = wc_get_product_terms( $id, $attr->get_name(), ['fields'=>'names'] );
                        $attrs[$label] = $terms;
                    } else {
                        $attrs[$label] = $attr->get_options();
                    }
                }
            }

            $meta = [];
            $raw_meta = get_post_meta($id);
            if (is_array($raw_meta)){
                foreach ($raw_meta as $k=>$vals){
                    if (strpos($k, '_')===0) continue;
                    $meta[$k] = is_array($vals) && count($vals)===1 ? $vals[0] : $vals;
                }
            }

            $data[] = [
                'product_id' => $id,
                'title' => $name,
                'short_description' => wp_strip_all_tags($short),
                'full_description'  => wp_strip_all_tags($full),
                'price' => $price,
                'url' => $url,
                'image' => $img,
                'categories' => $cats,
                'tags' => $tags,
                'attributes' => $attrs,
                'meta_data' => $meta,
            ];
        }

        $json_path = self::get_json_path();
        $ok = file_put_contents($json_path, wp_json_encode($data, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
        if ($ok === false){
            return ['ok'=>false, 'error'=>'نوشتن فایل JSON ناموفق بود. مسیر دسترسی پوشه آپلودها را بررسی کنید.'];
        }
        return ['ok'=>true, 'count'=>count($data), 'path'=>$json_path];
    }
}
