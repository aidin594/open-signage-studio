<?php
if (!defined('ABSPATH')) { exit; }

final class OSGS {
    const TYPE = 'osgs_slide';
    const NS = 'open-signage/v1';
    private static bool $syncing = false;

    public static function bootstrap(): void {
        add_action('init', [__CLASS__, 'register_type'], 0);
        add_action('init', [__CLASS__, 'rewrite']);
        add_filter('query_vars', static function ($vars) { $vars[] = 'osgs_page'; return $vars; });
        add_action('template_redirect', [__CLASS__, 'render_public']);
        add_action('rest_api_init', [__CLASS__, 'routes']);
        add_action('admin_menu', [__CLASS__, 'menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'assets']);
        add_filter('cron_schedules', static function ($s) { $s['osgs_15m'] = ['interval'=>900,'display'=>'OpenSignage: 15 minutes']; return $s; });
        add_action('osgs_sync_cron', [__CLASS__, 'sync_all']);
        add_action('woocommerce_update_product', [__CLASS__, 'sync_product'], 99, 1);
        add_action('woocommerce_product_set_stock_status', [__CLASS__, 'sync_product'], 99, 1);
        add_action('woocommerce_product_set_stock', [__CLASS__, 'sync_stock_object'], 99, 1);
        add_action('woocommerce_variation_set_stock', [__CLASS__, 'sync_stock_object'], 99, 1);
        add_action('woocommerce_update_product_variation', [__CLASS__, 'sync_variation'], 99, 1);
        add_action('woocommerce_rest_insert_product_object', [__CLASS__, 'sync_stock_object'], 99, 1);
        add_action('before_delete_post', [__CLASS__, 'product_deleted']);
        add_action('deleted_post', [__CLASS__, 'product_deleted_after']);
        add_action('woocommerce_trash_product', [__CLASS__, 'sync_product'], 99, 1);
        if (!wp_next_scheduled('osgs_sync_cron')) wp_schedule_event(time()+300, 'osgs_15m', 'osgs_sync_cron');
    }
    public static function activate(): void {
        self::register_type();
        self::rewrite();
        flush_rewrite_rules();
        if (!get_option('osgs_settings')) add_option('osgs_settings', self::defaults(), '', false);
        if (!get_option('osgs_revision')) add_option('osgs_revision', 1, '', false);
        self::publish_signal(self::revision());
        add_filter('cron_schedules', static function ($s) { $s['osgs_15m'] = ['interval'=>900,'display'=>'OpenSignage: 15 minutes']; return $s; });
        if (!wp_next_scheduled('osgs_sync_cron')) wp_schedule_event(time()+300,'osgs_15m','osgs_sync_cron');
    }
    public static function deactivate(): void {
        wp_clear_scheduled_hook('osgs_sync_cron');
        flush_rewrite_rules();
    }
    public static function register_type(): void {
        register_post_type(self::TYPE, [
            'label'=>'Display slides', 'public'=>false, 'show_ui'=>false,
            'show_in_rest'=>false, 'supports'=>['title','editor','page-attributes'],
            'capability_type'=>'post', 'map_meta_cap'=>true,
        ]);
    }
    public static function rewrite(): void {
        add_rewrite_rule('^signage-screen/?$', 'index.php?osgs_page=screen', 'top');
        add_rewrite_rule('^signage-control/?$', 'index.php?osgs_page=control', 'top');
    }
    public static function defaults(): array {
        return ['duration'=>8,'poll'=>3,'price_bg'=>'#e5483a','accent'=>'#2563eb',
          'background'=>'#ffffff','currency'=>(function_exists('get_woocommerce_currency_symbol') ? wp_strip_all_tags(get_woocommerce_currency_symbol()) : '$'),'short_words'=>2,'screen_title'=>(string)get_bloginfo('name'),
          'telegram_lunch_text'=>'Closed for lunch. Back soon!',
          'telegram_soon_text'=>'Back soon! We will reopen shortly.',
          'telegram_lunch_minutes'=>60,'telegram_soon_minutes'=>10,
          'telegram_lunch_template'=>'notice','telegram_soon_template'=>'notice',
          'telegram_lunch_html'=>'','telegram_soon_html'=>'',
          'telegram_lunch_soft'=>false,'telegram_soon_soft'=>false];
    }
    public static function animation(string $value): string {
        return in_array($value,['none','soft','blink'],true)?$value:'none';
    }
    public static function settings(): array {
        $settings=wp_parse_args((array)get_option('osgs_settings',[]),self::defaults());
        foreach(['lunch','soon'] as $key) {
            $name='telegram_'.$key.'_animation';
            if(!isset($settings[$name])) {
                $settings[$name]=!empty($settings['telegram_'.$key.'_soft'])?'soft':'none';
            } else $settings[$name]=self::animation((string)$settings[$name]);
        }
        return $settings;
    }
    public static function can_manage(): bool { return current_user_can('manage_woocommerce') || current_user_can('manage_options'); }
    public static function menu(): void {
        $capability = current_user_can('manage_woocommerce') ? 'manage_woocommerce' : 'manage_options';
        add_menu_page('OpenSignage Studio','Display Studio',$capability,'open-signage-studio',[__CLASS__,'admin_page'],'dashicons-desktop',56);
    }
    public static function admin_page(): void {
        if (!self::can_manage()) wp_die('Unauthorized');
        echo '<div class="wrap osgs-admin" id="osgs-root"><div class="osgs-loading">Loading Display Studio…</div></div>';
    }
    public static function assets(string $hook): void {
        if ($hook !== 'toplevel_page_open-signage-studio') return;
        wp_enqueue_style('osgs-rich', OSGS_URL.'assets/rich-editor.css',[],OSGS_VERSION);
        wp_enqueue_style('osgs-admin', OSGS_URL.'assets/admin.css',['osgs-rich'],OSGS_VERSION);
        wp_enqueue_script('osgs-rich',OSGS_URL.'assets/rich-editor.js',[],OSGS_VERSION,true);
        wp_enqueue_script('osgs-admin',OSGS_URL.'assets/admin.js',['osgs-rich'],OSGS_VERSION,true);
        wp_add_inline_script('osgs-admin','window.OSGS_CONFIG='.wp_json_encode([
            'api'=>esc_url_raw(rest_url(self::NS.'/')),
            'nonce'=>wp_create_nonce('wp_rest'),
            'screenUrl'=>home_url('/signage-screen/'),
            'controlUrl'=>home_url('/signage-control/'),
        ],JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT).';','before');
    }
    public static function respond($data): WP_REST_Response { $r=rest_ensure_response($data); $r->header('Cache-Control','no-store, private'); return $r; }
    public static function err(string $message,int $code=400): WP_Error { return new WP_Error('osgs_error',$message,['status'=>$code]); }
    /**
     * Public revision marker in uploads, served by Nginx/Apache without PHP.
     * Never store slide content, credentials, or control tokens in this file.
     * If uploads cannot be written, players fall back to standard REST.
     */
    private static function signal_location(): array {
        $uploads=wp_upload_dir();
        if (!empty($uploads['error']) || empty($uploads['basedir']) || empty($uploads['baseurl'])) return [];
        $folder='osgs-signal';
        return ['dir'=>trailingslashit($uploads['basedir']).$folder,
                'url'=>trailingslashit($uploads['baseurl']).$folder.'/revision.json'];
    }
    private static function publish_signal(int $revision): bool {
        $location=self::signal_location();
        if (!$location) return false;
        $dir=$location['dir'];
        if (!is_dir($dir) && !wp_mkdir_p($dir)) return false;
        $target=$dir.'/revision.json';
        $lock=@fopen($dir.'/.revision.lock','c');
        if (!$lock) return false;
        try {
            if (!flock($lock, LOCK_EX)) return false;
            $existing=@json_decode((string)@file_get_contents($target),true);
            if (is_array($existing) && (int)($existing['revision']??0)>=$revision) return true;
            $tmp=@tempnam($dir,'.revision-');
            if ($tmp===false) return false;
            $data=wp_json_encode(['revision'=>$revision]);
            $written=@file_put_contents($tmp,$data);
            if ($written===false || $written!==strlen($data)) { @unlink($tmp); return false; }
            @chmod($tmp,0644);
            if (!@rename($tmp,$target)) { @unlink($tmp); return false; }
            return true;
        } finally { flock($lock, LOCK_UN); fclose($lock); }
    }
    private static function ensure_signal(): string {
        $location=self::signal_location();
        if (!$location) return '';
        $target=$location['dir'].'/revision.json';
        if (!is_file($target)) self::publish_signal(self::revision());
        return is_readable($target) ? esc_url_raw($location['url']) : '';
    }
    public static function bump(): void {
        $revision=max((int)get_option('osgs_revision',1)+1,(int)floor(microtime(true)*1000));
        update_option('osgs_revision',$revision,false);
        self::publish_signal($revision);
    }
    public static function revision(): int { return (int)get_option('osgs_revision',1); }
    private static function register(string $path,string $methods,callable $callback,$permission='manage'): void {
        $permission_callback = match($permission) {
            'manage' => [__CLASS__,'can_manage'],
            'screen' => [__CLASS__,'screen_allowed'],
            'quick' => [__CLASS__,'quick_allowed'],
            'telegram' => [OSGS_Telegram::class,'allowed'],
            default => '__return_false'
        };
        register_rest_route(self::NS, $path, ['methods'=>$methods,'callback'=>$callback,'permission_callback'=>$permission_callback]);
    }
    public static function routes(): void {
        self::register('/dashboard','GET',[__CLASS__,'api_dashboard']);
        self::register('/products','GET',[__CLASS__,'api_products']);
        self::register('/categories','GET',[__CLASS__,'api_categories']);
        self::register('/slides','POST',[__CLASS__,'api_add_slides']);
        self::register('/slide/(?P<id>\d+)','POST',[__CLASS__,'api_update_slide']);
        self::register('/slide/(?P<id>\d+)','DELETE',[__CLASS__,'api_delete_slide']);
        self::register('/order','POST',[__CLASS__,'api_order']);
        self::register('/settings','POST',[__CLASS__,'api_settings']);
        self::register('/sync','POST',[__CLASS__,'api_sync']);
        self::register('/message','POST',[__CLASS__,'api_message']);
        self::register('/playlist','POST',[__CLASS__,'api_playlist']);
        self::register('/access','POST',[__CLASS__,'api_access']);
        self::register('/import','POST',[__CLASS__,'api_import']);
        self::register('/telegram/config','POST',[OSGS_Telegram::class,'config']);
        self::register('/telegram/webhook/register','POST',[OSGS_Telegram::class,'register_webhook']);
        self::register('/telegram/webhook','POST',[OSGS_Telegram::class,'handle'],'telegram');
        self::register('/screen/state','GET',[__CLASS__,'api_screen_state'],'screen');
        self::register('/quick/state','GET',[__CLASS__,'api_quick_state'],'quick');
        self::register('/quick/message','POST',[__CLASS__,'api_message'],'quick');
        self::register('/quick/playlist','POST',[__CLASS__,'api_playlist'],'quick');
    }
    private static function token_hash(string $token): string { return hash('sha256',$token); }
    public static function check_token(string $kind,string $token): bool {
        $stored=(string)get_option('osgs_'.$kind.'_hash','');
        return strlen($token)>=40 && $stored!=='' && hash_equals($stored,self::token_hash($token));
    }
    /** The signage display is public and read-only. Only control endpoints require credentials. */
    public static function screen_allowed(WP_REST_Request $r): bool {
        return true;
    }
    public static function quick_allowed(WP_REST_Request $r): bool {
        if (self::can_manage() && wp_verify_nonce((string)$r->get_header('x-wp-nonce'),'wp_rest')) return true;
        return self::check_token('quick',(string)$r->get_header('x-osgs-control-key'));
    }
    public static function render_public(): void {
        $page=get_query_var('osgs_page');
        if (!in_array($page,['screen','control'],true)) return;
        $preview=self::can_manage() && isset($_GET['preview']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['preview'])),'osgs_preview');
        $key=isset($_GET['key'])?sanitize_text_field(wp_unslash($_GET['key'])):'';
        // This screen URL is intentionally permanent and publicly viewable.
        // Mobile controls remain secured by their own independent access token.
        if ($page === 'screen') {
            $authorized = true;
        } else {
            $authorized = $preview || self::check_token('quick',$key);
            if (!$authorized && !isset($_GET['key'])) {
                // Existing mobile-control links may have moved the key to sessionStorage.
                // Its REST requests still require valid credentials.
                $authorized = true;
            }
        }
        if (!$authorized) { status_header(403); nocache_headers(); wp_die('Access denied. Regenerate the secure link in Display Studio.','Display Studio',['response'=>403]); }
        status_header(200); nocache_headers();
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow, noarchive');
        header('X-Content-Type-Options: nosniff');
        header('Content-Security-Policy: frame-ancestors \'self\'');
        $asset=$page==='screen'?'player':'control';
        $config=['api'=>esc_url_raw(rest_url(self::NS.'/')), 'preview'=>$preview,'nonce'=>$preview?wp_create_nonce('wp_rest'):'', 'signalUrl'=>$page==='screen'?self::ensure_signal():''];
        ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><meta name="robots" content="noindex,nofollow"><title><?php echo esc_html($page==='screen'?'OpenSignage':'OpenSignage Quick Control'); ?></title><link rel="stylesheet" href="<?php echo esc_url(OSGS_URL.'assets/rich-editor.css?ver='.OSGS_VERSION); ?>"><link rel="stylesheet" href="<?php echo esc_url(OSGS_URL.'assets/'.$asset.'.css?ver='.OSGS_VERSION); ?>"></head><body>
        <div id="osgs-app"></div><script>window.OSGS_PUBLIC=<?php echo wp_json_encode($config,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT); ?>;</script><script defer src="<?php echo esc_url(OSGS_URL.'assets/rich-editor.js?ver='.OSGS_VERSION); ?>"></script><script defer src="<?php echo esc_url(OSGS_URL.'assets/'.$asset.'.js?ver='.OSGS_VERSION); ?>"></script></body></html><?php
        exit;
    }
    public static function list_slides(): array {
        $posts=get_posts(['post_type'=>self::TYPE,'post_status'=>'publish','numberposts'=>500,'orderby'=>'menu_order','order'=>'ASC']);
        $result=[];
        foreach($posts as $post) {
            $m=fn($key)=>get_post_meta($post->ID,'_osgs_'.$key,true);
            $result[]=['id'=>$post->ID,'kind'=>$m('kind')?:'text','product_id'=>(int)$m('product_id'),
              'title'=>$post->post_title,'text'=>$post->post_content,'html'=>$m('html')?:'',
              'template'=>$m('template')?:'clean','image'=>$m('image'),
              'price'=>$m('price'),'old_price'=>$m('old_price'), 'duration'=>max(2,(int)$m('duration')?:8),
              'enabled'=>$m('enabled')!=='0','auto_paused'=>$m('auto_paused')==='1',
              'position'=>(int)$post->menu_order,'stock'=>$m('stock')?:'','updated'=>$post->post_modified];
        }
        return $result;
    }
    private static function product_summary($p): array {
        $id=$p->get_id();
        $display=$p;
        if ($p->is_type('variable')) {
            $price=$p->get_variation_price('min',true);
        } else $price=$p->get_price();
        $regular=$p->get_regular_price();
        $decimals=function_exists('wc_get_price_decimals')?wc_get_price_decimals():0;
        $fmt=static function($amount)use($decimals):string {
            if ($amount === '' || $amount === null) return '';
            $n=number_format((float)$amount,min(2,max(0,$decimals)),'.','');
            return str_contains($n,'.')?rtrim(rtrim($n,'0'),'.'):$n;
        };
        $name=wp_strip_all_tags($display->get_name());
        $words=preg_split('/\s+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY);
        $short=implode(' ',array_slice($words,0,max(1,min(5,(int)self::settings()['short_words']))));
        return ['id'=>$id,'name'=>$name,'short'=>$short,'image'=>wp_get_attachment_image_url($p->get_image_id(),'large')?:'',
            'price'=>$fmt($price),'old_price'=>($regular!=='' && (float)$regular>(float)$price)?$fmt($regular):'',
            'stock'=>$p->get_stock_status(),'category_ids'=>wp_get_post_terms($id,'product_cat',['fields'=>'ids'])];
    }
    public static function api_categories(): WP_REST_Response {
        if (!taxonomy_exists('product_cat')) return self::respond(['categories'=>[]]);
        $terms=get_terms(['taxonomy'=>'product_cat','hide_empty'=>true]);
        $cats=[];
        if (!is_wp_error($terms)) foreach($terms as $term) $cats[]=['id'=>$term->term_id,'name'=>$term->name,'parent'=>$term->parent,'count'=>$term->count];
        return self::respond(['categories'=>$cats]);
    }
    public static function api_products(WP_REST_Request $r) {
        if (!function_exists('wc_get_products')) return self::err('WooCommerce must be active.',503);
        $page=max(1,min(10000,(int)$r->get_param('page')));
        $category=absint($r->get_param('category'));
        $search=osgs_clip(sanitize_text_field((string)$r->get_param('search')),100);
        $args=['status'=>'publish','limit'=>24,'page'=>$page,'paginate'=>true,'orderby'=>'title','order'=>'ASC','stock_status'=>'instock'];
        if ($category) {
            $term=get_term($category,'product_cat');
            if (!$term || is_wp_error($term)) return self::err('Unknown category.');
            $args['category']=[$term->slug];
        }
        if ($search!=='') $args['s']=$search;
        $query=wc_get_products($args);
        return self::respond(['products'=>array_map([__CLASS__,'product_summary'],$query->products),
          'page'=>$page,'pages'=>(int)$query->max_num_pages,'total'=>(int)$query->total]);
    }
    private static function meta(int $id,array $values): void { foreach($values as $k=>$v) update_post_meta($id,'_osgs_'.$k,$v); }
    public static function insert_product(int $product_id): int {
        if (!function_exists('wc_get_product')) return 0;
        $p=wc_get_product($product_id);
        if (!$p || $p->get_status()!=='publish' || $p->get_stock_status()!=='instock') return 0;
        $existing=self::find_product_slide($product_id);
        if ($existing) return 0;
        $item=self::product_summary($p);
        if($item['price']==='' || !$item['image']) return 0;
        $id=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'publish','post_title'=>$item['short'],'post_content'=>'',
           'menu_order'=>count(self::list_slides())+1],true);
        if (is_wp_error($id) || !$id) return 0;
        self::meta($id,['kind'=>'product','product_id'=>$product_id,'image'=>$item['image'],
            'price'=>$item['price'],'old_price'=>$item['old_price'],'duration'=>(int)self::settings()['duration'],
            'enabled'=>'1','auto_paused'=>'0','stock'=>'instock']);
        return (int)$id;
    }
    private static function find_product_slide(int $id): int {
        $ids=get_posts(['post_type'=>self::TYPE,'post_status'=>'publish','numberposts'=>1,'fields'=>'ids',
            'meta_key'=>'_osgs_product_id','meta_value'=>$id]);
        return $ids?(int)$ids[0]:0;
    }
    /** Only approved message themes; never accept arbitrary CSS or markup. */
    public static function template(string $value): string {
        $allowed=['clean','notice','alert','night','warm','promo'];
        return in_array($value,$allowed,true)?$value:'clean';
    }
    public static function safe_rich_html(string $html): string {
        $allowed=[
           'div'=>['class'=>true,'style'=>true], 'p'=>['class'=>true,'style'=>true],
           'span'=>['class'=>true,'style'=>true], 'br'=>[],
           'strong'=>[], 'b'=>[], 'em'=>[], 'i'=>[], 'u'=>[],
        ];
        $html=wp_kses(osgs_clip($html,8000),$allowed);
        // Only CSS classes known to the renderer may survive.
        return preg_replace_callback('/\sclass="([^"]*)"/i',static function($m){
            $allowed=['osgs-blink','osgs-blink-soft','osgs-mark','osgs-text-large','osgs-text-small'];
            $classes=array_intersect(preg_split('/\s+/',trim($m[1])),$allowed);
            return $classes?' class="'.esc_attr(implode(' ',$classes)).'"':'';
        },$html);
    }
    public static function api_add_slides(WP_REST_Request $r) {
        $type=sanitize_key((string)$r->get_param('kind'));
        if ($type==='products') {
            $ids=(array)$r->get_param('ids');
            if (count($ids)>120 || count($ids)===0) return self::err('Select 1–120 products.');
            $count=0;
            foreach(array_unique(array_map('absint',$ids)) as $id) if ($id && self::insert_product($id)) $count++;
            if ($count) self::bump();
            return self::respond(['added'=>$count,'skipped'=>count(array_unique($ids))-$count]);
        }
        if ($type!=='text') return self::err('Unsupported slide type.');
        $title=osgs_clip(sanitize_text_field((string)$r->get_param('title')),120);
        $body=osgs_clip(sanitize_textarea_field((string)$r->get_param('text')),1200);
        if ($title==='' && $body==='') return self::err('Enter some text.');
        $duration=max(3,min(120,(int)$r->get_param('duration')?:8));
        $id=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'publish','post_title'=>$title?:'Message',
          'post_content'=>$body,'menu_order'=>count(self::list_slides())+1],true);
        if (is_wp_error($id)) return $id;
        self::meta($id,['kind'=>'text','duration'=>$duration,'enabled'=>'1','auto_paused'=>'0',
            'html'=>self::safe_rich_html((string)$r->get_param('html')),
            'template'=>self::template(sanitize_key((string)$r->get_param('template')))]);
        self::bump(); return self::respond(['id'=>(int)$id]);
    }
    public static function api_update_slide(WP_REST_Request $r) {
        $id=absint($r['id']);$p=get_post($id);
        if (!$p || $p->post_type!==self::TYPE) return self::err('Slide not found.',404);
        $fields=[];
        if ($r->has_param('title')) $fields['post_title']=osgs_clip(sanitize_text_field((string)$r['title']),120);
        if ($r->has_param('text') && get_post_meta($id,'_osgs_kind',true)==='text') $fields['post_content']=osgs_clip(sanitize_textarea_field((string)$r['text']),1200);
        if ($fields) wp_update_post(array_merge(['ID'=>$id],$fields));
        $meta=[];
        if ($r->has_param('enabled')) $meta['enabled']=$r['enabled']?'1':'0';
        if ($r->has_param('duration')) $meta['duration']=max(3,min(120,(int)$r['duration']));
        if (get_post_meta($id,'_osgs_kind',true)==='text') {
            if ($r->has_param('html')) $meta['html']=self::safe_rich_html((string)$r['html']);
            if ($r->has_param('template')) $meta['template']=self::template(sanitize_key((string)$r['template']));
        }
        if ($meta) self::meta($id,$meta);
        self::bump();return self::respond(['ok'=>true]);
    }
    public static function api_delete_slide(WP_REST_Request $r) {
        $id=absint($r['id']);$p=get_post($id);
        if (!$p || $p->post_type!==self::TYPE) return self::err('Slide not found.',404);
        wp_delete_post($id,true);self::bump();return self::respond(['ok'=>true]);
    }
    public static function api_order(WP_REST_Request $r) {
        $ids=array_values(array_unique(array_filter(array_map('absint',(array)$r->get_param('ids')))));
        if (count($ids)>500) return self::err('Too many slides.');
        $valid=array_column(self::list_slides(),'id');
        foreach($ids as $id) if (!in_array($id,$valid,true)) return self::err('Invalid slide ID.');
        foreach($ids as $position=>$id) wp_update_post(['ID'=>$id,'menu_order'=>$position]);
        self::bump();return self::respond(['ok'=>true]);
    }
    public static function api_settings(WP_REST_Request $r): WP_REST_Response|WP_Error {
        $input=(array)$r->get_json_params();$settings=self::settings();
        foreach(['price_bg','accent','background'] as $k) if(isset($input[$k])) {
            $color=sanitize_hex_color($input[$k]); if($color) $settings[$k]=$color;
        }
        foreach(['duration','poll','short_words'] as $k) if(isset($input[$k])) {
            $n=absint($input[$k]);$settings[$k]=match($k){'duration'=>max(3,min(120,$n)),'poll'=>max(3,min(60,$n)),default=>max(1,min(5,$n))};
        }
        if(isset($input['currency'])) $settings['currency']=osgs_clip(sanitize_text_field($input['currency']),8);
        if(isset($input['screen_title'])) $settings['screen_title']=osgs_clip(sanitize_text_field($input['screen_title']),70);
        // Admin-only quick-action messages. They never arrive through the public screen API.
        foreach (['telegram_lunch_text','telegram_soon_text'] as $k) if (array_key_exists($k,$input)) {
            $v=osgs_clip(sanitize_textarea_field((string)$input[$k]),1200);
            if (trim($v)==='') return self::err('A quick message cannot be empty.');
            $settings[$k]=$v;
        }
        foreach (['telegram_lunch_html','telegram_soon_html'] as $k) if (array_key_exists($k,$input)) {
            if (!is_string($input[$k])) return self::err('Formatted message must be text.');
            $settings[$k]=self::safe_rich_html($input[$k]);
        }
        // New explicit three-state setting takes precedence; keep the old boolean
        // in sync for installations upgrading from <=1.1.7.
        foreach (['lunch','soon'] as $key) {
            $name='telegram_'.$key.'_animation';
            $legacy='telegram_'.$key.'_soft';
            if (array_key_exists($name,$input)) {
                if(!is_string($input[$name]) || !in_array($input[$name],['none','soft','blink'],true))
                    return self::err('Invalid animation mode.');
                $settings[$name]=$input[$name];
                $settings[$legacy]=$input[$name]==='soft';
            } elseif (array_key_exists($legacy,$input)) {
                if (!is_bool($input[$legacy])) return self::err('Soft-blink option must be true or false.');
                $settings[$legacy]=$input[$legacy];
                $settings[$name]=$input[$legacy]?'soft':'none';
            }
        }
        foreach (['telegram_lunch_minutes','telegram_soon_minutes'] as $k) if (array_key_exists($k,$input)) {
            if (!is_scalar($input[$k]) || !preg_match('/^\d{1,4}$/',(string)$input[$k]))
                return self::err('Quick message duration must be 0–1440 minutes.');
            $n=(int)$input[$k];
            if($n>1440) return self::err('Quick message duration cannot exceed 1440 minutes.');
            $settings[$k]=$n;
        }
        foreach (['telegram_lunch_template','telegram_soon_template'] as $k) if (isset($input[$k])) {
            $settings[$k]=self::template((string)$input[$k]);
        }
        update_option('osgs_settings',$settings,false);self::bump();return self::respond(['settings'=>$settings]);
    }
    public static function sync_stock_object($p): void { if (is_object($p) && method_exists($p,'get_id')) self::sync_product((int)$p->get_id()); }
    public static function sync_variation(int $variation): void {
        $p=function_exists('wc_get_product')?wc_get_product($variation):null;
        if($p) self::sync_product($p->get_parent_id());
    }
    public static function product_deleted(int $id): void {
        if (get_post_type($id)==='product') self::sync_product($id);
    }
    public static function product_deleted_after(int $id): void {
        $slide=self::find_product_slide($id);
        if($slide) { update_post_meta($slide,'_osgs_auto_paused','1');self::bump(); }
    }
    public static function sync_product(int $product_id): void {
        if (self::$syncing) return;
        $id=self::find_product_slide($product_id);
        if (!$id) return;
        self::$syncing=true;
        try {
            $p=function_exists('wc_get_product')?wc_get_product($product_id):null;
            if (!$p || $p->get_status()!=='publish') {
                if (get_post_meta($id,'_osgs_auto_paused',true)!=='1') { update_post_meta($id,'_osgs_auto_paused','1');self::bump(); }
                return;
            }
            $item=self::product_summary($p);
            $new=['image'=>$item['image'],'price'=>$item['price'],'old_price'=>$item['old_price'],
              'auto_paused'=>($item['stock']==='instock' && $item['price']!=='')?'0':'1','stock'=>$item['stock']];
            $changed=false;
            foreach($new as $k=>$v) { if((string)get_post_meta($id,'_osgs_'.$k,true)!==(string)$v) {
                update_post_meta($id,'_osgs_'.$k,$v);$changed=true;
            }}
            if ($changed) self::bump();
        } finally {self::$syncing=false;}
    }
    public static function sync_all(): int {
        $ids=get_posts(['post_type'=>self::TYPE,'post_status'=>'publish','numberposts'=>500,'fields'=>'ids',
            'meta_key'=>'_osgs_kind','meta_value'=>'product']);
        foreach($ids as $id) self::sync_product((int)get_post_meta($id,'_osgs_product_id',true));
        update_option('osgs_last_sync',time(),false);
        return count($ids);
    }
    public static function api_sync(): WP_REST_Response { return self::respond(['synced'=>self::sync_all()]); }
    public static function set_message(string $text,int $minutes=10,string $html='',string $template='clean',?int $untilAt=null,string $animation='none'): void {
        $text=osgs_clip(sanitize_textarea_field($text),1200);
        if ($text==='') delete_option('osgs_message');
        else update_option('osgs_message',[
          'text'=>$text,'html'=>self::safe_rich_html($html),'template'=>self::template($template),
          'animation'=>self::animation($animation),
          'until'=>$untilAt!==null?max(time()+1,min(time()+DAY_IN_SECONDS,$untilAt)):
              ($minutes>0?time()+min($minutes,1440)*60:0)],false);
        self::bump();
    }
    public static function message(): ?array {
        $m=get_option('osgs_message');
        if(!is_array($m) || empty($m['text'])) return null;
        if (!empty($m['until']) && time()>=(int)$m['until']) {
            delete_option('osgs_message');self::bump();return null;
        }
        return $m;
    }
    public static function api_message(WP_REST_Request $r) {
        $text=(string)$r->get_param('text');
        $minutes=$r->has_param('minutes')?max(0,min(1440,(int)$r['minutes'])):10;
        self::set_message($text,$minutes,(string)$r->get_param('html'),
            sanitize_key((string)$r->get_param('template')),null,
            self::animation((string)$r->get_param('animation')));
        return self::respond(['message'=>self::message()]);
    }
    public static function set_playlist(array $ids): void {
        $ids=array_values(array_unique(array_filter(array_map('absint',$ids))));
        update_option('osgs_playlist_filter',$ids,false);self::bump();
    }
    public static function set_paused(bool $pause): void { update_option('osgs_paused',$pause,false);self::bump(); }
    public static function api_playlist(WP_REST_Request $r) {
        if ($r->has_param('ids')) {
            $ids=(array)$r['ids']; if(count($ids)>500)return self::err('Too many IDs.');
            $valid=array_column(self::list_slides(),'id');
            foreach($ids as $id) if(!in_array((int)$id,$valid,true))return self::err('Unknown slide.');
            self::set_playlist($ids);
        }
        if ($r->has_param('paused')) self::set_paused((bool)$r['paused']);
        return self::respond(['playlist'=>get_option('osgs_playlist_filter',[]),'paused'=>(bool)get_option('osgs_paused',false)]);
    }
    public static function api_access(WP_REST_Request $r): WP_REST_Response {
        $kind=sanitize_key((string)$r['kind']);
        if(!in_array($kind,['screen','quick'],true))return self::respond(['error'=>'Invalid key type']);
        if ($kind==='screen') return self::respond(['url'=>home_url('/signage-screen/'),'public'=>true]);
        $token='osgs_'.bin2hex(random_bytes(32));
        update_option('osgs_'.$kind.'_hash',self::token_hash($token),false);
        $base=$kind==='screen'?home_url('/signage-screen/'):home_url('/signage-control/');
        return self::respond(['url'=>add_query_arg('key',$token,$base)]);
    }
    public static function api_import(WP_REST_Request $r) {
        $data=$r->get_param('slides');
        if(!is_array($data) || count($data)>200) return self::err('Expected up to 200 slides.');
        $count=0;$skipped=0;$linked=0;
        foreach($data as $slide) {
            if (!is_array($slide) || $count>=200) continue;
            // Preserve native WooCommerce links from the previous Node.js product slides.
            if (!empty($slide['wcManaged']) && !empty($slide['wcProductId'])) {
                $linked_id=absint($slide['wcProductId']);
                $existing=self::find_product_slide($linked_id);
                if ($existing) { $skipped++;continue; }
                $new=self::insert_product($linked_id);
                if ($new) {
                    self::meta($new,['duration'=>max(3,min(120,(int)($slide['duration']??8))),
                        'enabled'=>(isset($slide['active'])&&!$slide['active'])?'0':'1']);
                    $count++;$linked++;
                }else $skipped++;
                continue;
            }
            $title=osgs_clip(sanitize_text_field((string)($slide['title']??'Imported slide')),120);
            $html=(string)($slide['body']??$slide['content']??'');
            $linebroken=preg_replace('/<\s*\/(?:p|h[1-6]|div|li)>/i',"\n",$html);
            $linebroken=preg_replace('/<\s*br\s*\/?>/i',"\n",$linebroken);
            $text=osgs_clip(sanitize_textarea_field(wp_strip_all_tags($linebroken)),1200);
            $text=trim($text);
            $image=esc_url_raw((string)($slide['imageUrl']??''));
            if(!$text && !$image && !$title)continue;
            if(($slide['type']??'')==='override' && !empty($slide['active']) && !empty($slide['showUntil'])) {
                $until=strtotime((string)$slide['showUntil']);
                if($until>time() && $text!=='') { self::set_message($text,(int)ceil(($until-time())/60));$count++; }
                else $skipped++;
                continue;
            }
            $id=wp_insert_post(['post_type'=>self::TYPE,'post_status'=>'publish','post_title'=>$title,
                 'post_content'=>$text,'menu_order'=>count(self::list_slides())+1],true);
            if(is_wp_error($id))continue;
            self::meta($id,['kind'=>'text','image'=>$image,'duration'=>max(3,min(120,(int)($slide['duration']??8))),
                'enabled'=>array_key_exists('active',$slide) && !$slide['active']?'0':'1','auto_paused'=>'0']);$count++;
        }
        if ($count)self::bump();
        return self::respond(['imported'=>$count,'linked_products'=>$linked,'skipped'=>$skipped]);
    }
    public static function api_dashboard(): WP_REST_Response {
        return self::respond(['slides'=>self::list_slides(),'message'=>self::message(),'settings'=>self::settings(),
            'revision'=>self::revision(),'paused'=>(bool)get_option('osgs_paused',false),
            'playlist'=>get_option('osgs_playlist_filter',[]),'last_sync'=>(int)get_option('osgs_last_sync',0),
            'has_screen'=>true, 'screen_url'=>home_url('/signage-screen/'), 'public_screen'=>true,
            'has_quick'=>(bool)get_option('osgs_quick_hash'),
            'preview_url'=>add_query_arg('preview',wp_create_nonce('osgs_preview'),home_url('/signage-screen/')),
            'telegram'=>OSGS_Telegram::status(), 'woocommerce'=>function_exists('wc_get_products')]);
    }
    public static function api_quick_state(): WP_REST_Response {
        $slides=self::list_slides();return self::respond(['slides'=>array_map(static fn($s)=>['id'=>$s['id'],'title'=>$s['title'],'enabled'=>$s['enabled'],'auto_paused'=>$s['auto_paused']],$slides),
          'message'=>self::message(),'playlist'=>get_option('osgs_playlist_filter',[]),'paused'=>(bool)get_option('osgs_paused',false)]);
    }
    public static function api_screen_state(WP_REST_Request $r): WP_REST_Response {
        // A key-free screen has no device identity. This is only evidence of recent
        // public player requests, NOT proof that a specific shop monitor is online.
        // Admin preview sets an advisory flag; this is not an authentication check.
        if ((string)$r->get_header('x-osgs-preview')!=='1') {
            $last=(int)get_option('osgs_screen_seen',0);
            if (time()-$last>=30) update_option('osgs_screen_seen',time(),false);
        }
        // Always evaluate message expiry before comparing revisions.
        $message=self::message();$rev=self::revision();
        if((int)$r->get_param('since')===$rev) return self::respond(['changed'=>false,'revision'=>$rev]);
        $slides=self::list_slides();$filter=array_map('intval',(array)get_option('osgs_playlist_filter',[]));
        $playable=array_values(array_filter($slides,static function($s)use($filter){
            return $s['enabled'] && !$s['auto_paused'] && (!$filter || in_array($s['id'],$filter,true));
        }));
        // Do not publish private management labels or WooCommerce internal IDs.
        // This endpoint returns only data needed to render an already-public display.
        $display_slides=array_map(static function(array $slide): array {
            return [
                'id'=>$slide['id'], 'kind'=>$slide['kind'],
                'title'=>$slide['kind']==='product'?$slide['title']:'',
                'text'=>$slide['text'], 'html'=>$slide['html'],
                'template'=>$slide['template'], 'image'=>$slide['image'],
                'price'=>$slide['price'], 'duration'=>$slide['duration'],
            ];
        },$playable);
        return self::respond(['changed'=>true,'revision'=>$rev,'slides'=>$display_slides,'message'=>$message,
            'paused'=>(bool)get_option('osgs_paused',false),'settings'=>array_intersect_key(self::settings(), array_flip(['duration','poll','price_bg','accent','background','currency','short_words','screen_title'])),'server_time'=>time()]);
    }
}
