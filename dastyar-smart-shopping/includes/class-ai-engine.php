<?php
if (!defined('ABSPATH')) exit;

class DSS_AI_Engine {

    protected static function get_products_path(){
        if ( class_exists('DSS_Product_Collector') ){
            return DSS_Product_Collector::get_json_path();
        }
        $ul = wp_upload_dir();
        return trailingslashit($ul['basedir']).'dastyar-smart-shopping/products.json';
    }

    protected static function get_products(){
        $path = self::get_products_path();
        if (!file_exists($path)) return [];
        $raw = file_get_contents($path);
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    protected static function extract_keywords($message){
        $m = mb_strtolower($message);
        $m = preg_replace('/[\s\,\.;:!؟?]+/u',' ', $m);
        $parts = array_filter(array_unique(explode(' ', $m)));
        $stop = ['و','یا','برای','میخوام','می‌خوام','زیر','بالا','بین','از','تا','این','که','چه','چی','هست','رو','به','در','با','the','a','an','and','or','of','to'];
        $keywords = array_values(array_diff($parts, $stop));
        return array_slice($keywords, 0, 8);
    }

    protected static function score_product($prod, $keywords){
        $text_parts = [];
        foreach (['title','short_description','full_description'] as $k){
            if (!empty($prod[$k])) $text_parts[] = mb_strtolower($prod[$k]);
        }
        if (!empty($prod['categories'])) $text_parts[] = mb_strtolower(implode(' ', (array)$prod['categories']));
        if (!empty($prod['tags'])) $text_parts[] = mb_strtolower(implode(' ', (array)$prod['tags']));
        if (!empty($prod['attributes']) && is_array($prod['attributes'])){
            foreach ($prod['attributes'] as $k=>$v){
                $text_parts[] = mb_strtolower($k.' '.(is_array($v)? implode(' ', $v): $v));
            }
        }
        if (!empty($prod['meta_data']) && is_array($prod['meta_data'])){
            foreach ($prod['meta_data'] as $k=>$v){
                $text_parts[] = mb_strtolower($k.' '.(is_array($v)? implode(' ', $v): $v));
            }
        }
        $hay = implode(' ', $text_parts);
        $score = 0;
        foreach ($keywords as $kw){
            if (!$kw) continue;
            $kw = trim(mb_strtolower($kw));
            if ($kw && mb_strpos($hay, $kw) !== false){ $score += 1; }
        }
        return $score;
    }

    public static function suggest_structured($user_message){
        $opts = get_option('dss_options', []);
        $api_key = isset($opts['api_key']) ? trim($opts['api_key']) : '';
        $max_results = isset($opts['max_results']) ? intval($opts['max_results']) : 3;

        $products = self::get_products();
        if (empty($products)){
            return ['ok'=>true,'text'=>'برای شروع، دکمه «بروزرسانی دیتای محصولات» را بزن تا محصولات فروشگاه بارگذاری شود.','cards'=>[]];
        }

        $keywords = self::extract_keywords($user_message);
        $scored = [];
        foreach ($products as $p){
            $sc = self::score_product($p, $keywords);
            if ($sc>0) $scored[] = ['score'=>$sc,'p'=>$p];
        }
        if (empty($scored)){
            foreach ($products as $p){
                $scored[] = ['score'=>0,'p'=>$p];
                if (count($scored) >= $max_results) break;
            }
        } else {
            usort($scored, function($a,$b){ return $b['score'] <=> $a['score']; });
        }
        $top = array_slice($scored, 0, max(1,$max_results));

        $cards = [];
        foreach ($top as $row){
            $p = $row['p'];
            $cards[] = [
                'title' => isset($p['title'])?$p['title']:'',
                'price' => isset($p['price'])?$p['price']:'',
                'url'   => isset($p['url'])?$p['url']:'#',
                'image' => isset($p['image'])?$p['image']:''
            ];
        }

        $text = 'بر اساس نیازت، این چند گزینه مناسب‌ترند:';
        if ($api_key){
            $body = [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    ['role'=>'system','content'=>'تو یک دستیار خرید فارسی هستی. یک جمله کوتاه و محترمانه برای معرفی پیشنهادات بده.'],
                    ['role'=>'user','content'=>$user_message]
                ],
                'temperature' => 0.2,
                'max_tokens' => 60
            ];
            $args = [
                'headers' => [
                    'Content-Type'  => 'application/json',
                    'Authorization' => 'Bearer ' . $api_key,
                ],
                'body'    => wp_json_encode($body),
                'timeout' => 20
            ];
            $response = wp_remote_post('https://api.openai.com/v1/chat/completions', $args);
            if (!is_wp_error($response)){
                $code = wp_remote_retrieve_response_code($response);
                $raw  = wp_remote_retrieve_body($response);
                $data = json_decode($raw, true);
                if ($code===200 && isset($data['choices'][0]['message']['content'])){
                    $txt = trim($data['choices'][0]['message']['content']);
                    if ($txt) $text = $txt;
                }
            }
        }

        return ['ok'=>true,'text'=>$text,'cards'=>$cards];
    }
}
