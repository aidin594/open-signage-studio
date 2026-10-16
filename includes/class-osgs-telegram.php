<?php
if (!defined('ABSPATH')) { exit; }

/**
 * Telegram command center for OpenSignage Studio.
 * All writes run through WordPress/WooCommerce APIs; never use public screen keys.
 * Callback actions are always authenticated against chat + sender allowlists.
 */
final class OSGS_Telegram {
    private const SESSION_TTL = 2700;
    private const PREFIX = 'os:';
    private const PAGE_SIZE = 6;

    private static function values(): array { return (array)get_option('osgs_telegram', []); }
    public static function status(): array {
        $v=self::values();
        return ['configured'=>!empty($v['bot_token']),'chats'=>$v['chats']??'',
            'users'=>$v['users']??'','webhook'=>(bool)($v['webhook']??false),
            'webhook_url'=>rest_url(OSGS::NS.'/telegram/webhook')];
    }
    public static function config(WP_REST_Request $r) {
        $v=self::values(); $token=trim((string)$r->get_param('bot_token'));
        if ($token!=='') {
            if (!preg_match('/^[0-9]{5,20}:[A-Za-z0-9_-]{25,}$/',$token)) return OSGS::err('Invalid Telegram bot token format.');
            $v['bot_token']=$token; $v['webhook']=false;
        }
        foreach(['chats','users'] as $field) if ($r->has_param($field)) {
            $raw=str_replace(' ','',(string)$r[$field]);
            if ($raw!=='' && !preg_match('/^-?[0-9]+(?:,-?[0-9]+)*$/',$raw)) return OSGS::err('IDs must be comma-separated integers.');
            $v[$field]=$raw;
        }
        if (empty($v['secret'])) $v['secret']=bin2hex(random_bytes(24));
        update_option('osgs_telegram',$v,false);
        return OSGS::respond(['telegram'=>self::status()]);
    }
    private static function api(string $method,array $body=[],int $timeout=8): array {
        $v=self::values();
        if (empty($v['bot_token'])) return ['ok'=>false];
        $url='https://api.telegram.org/bot'.$v['bot_token'].'/'.$method;
        if (isset($body['reply_markup']) && is_array($body['reply_markup']))
            $body['reply_markup']=wp_json_encode($body['reply_markup'],JSON_UNESCAPED_UNICODE);
        $response=wp_remote_post($url,['timeout'=>$timeout,'body'=>$body]);
        if (is_wp_error($response)) {
            error_log('OSGS Telegram transport: '.$response->get_error_message());
            return ['ok'=>false];
        }
        $result=json_decode(wp_remote_retrieve_body($response),true);
        if (!is_array($result) || empty($result['ok'])) {
            $description=is_array($result)?(string)($result['description']??''):'';
            // Telegram returns HTTP 400 when trying to edit an already-identical message.
            // This is a success for an idempotent menu update, not a reason to send another menu.
            if (in_array($method,['editMessageText','editMessageReplyMarkup'],true)
                && stripos($description,'message is not modified')!==false) return ['ok'=>true,'unchanged'=>true];
            error_log('OSGS Telegram API '.$method.' failed (HTTP '.wp_remote_retrieve_response_code($response).')'.
                ($description!==''?' — '.osgs_clip($description,180):''));
            return ['ok'=>false,'description'=>$description];
        }
        return $result;
    }
    public static function register_webhook() {
        $v=self::values();
        if (empty($v['bot_token'])) return OSGS::err('Save a Telegram bot token first.');
        $url=rest_url(OSGS::NS.'/telegram/webhook');
        if (!str_starts_with($url,'https://')) return OSGS::err('Telegram requires HTTPS on the WordPress site.');
        if (empty($v['secret'])) $v['secret']=bin2hex(random_bytes(24));
        $result=self::api('setWebhook',['url'=>$url,'secret_token'=>$v['secret'],
            'allowed_updates'=>wp_json_encode(['message','callback_query'])],15);
        if (empty($result['ok'])) return OSGS::err('Telegram webhook registration failed. Check Telegram and PHP logs.',502);
        // Best-effort menu registration; a failure must not break the webhook.
        self::api('setMyCommands',['commands'=>wp_json_encode([
            ['command'=>'start','description'=>'Open interactive control center'],
            ['command'=>'status','description'=>'Screen, slides and message status'],
            ['command'=>'message','description'=>'Show an instant message'],
            ['command'=>'blink','description'=>'Create blinking text message'],
            ['command'=>'soft','description'=>'Create softly blinking text'],
            ['command'=>'slides','description'=>'Browse and manage slides'],
            ['command'=>'select','description'=>'Select slide IDs'],
            ['command'=>'clear','description'=>'Clear active message'],
            ['command'=>'pause','description'=>'Pause slideshow'],
            ['command'=>'resume','description'=>'Resume slideshow'],
            ['command'=>'help','description'=>'Commands and examples']
        ],JSON_UNESCAPED_UNICODE)]);
        $v['webhook']=true;update_option('osgs_telegram',$v,false);
        return OSGS::respond(['ok'=>true,'telegram'=>self::status()]);
    }
    public static function allowed(WP_REST_Request $r): bool {
        $v=self::values();$secret=(string)($v['secret']??'');
        $received=(string)$r->get_header('x-telegram-bot-api-secret-token');
        return strlen($secret)>=32 && strlen($received)>=32 && hash_equals($secret,$received);
    }
    private static function ids(string $csv): array {
        return array_values(array_filter(explode(',',str_replace(' ','',$csv)),'strlen'));
    }
    private static function authorized(string $chat,string $user,string $type): bool {
        $v=self::values();
        $chats=self::ids((string)($v['chats']??''));
        $users=self::ids((string)($v['users']??''));
        return in_array($chat,$chats,true)
            && (!$users || in_array($user,$users,true))
            && ($type==='private' || ($users && in_array($user,$users,true)));
    }
    private static function esc(string $value): string { return htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'); }
    private static function btn(string $title,string $action): array {
        return ['text'=>$title,'callback_data'=>self::PREFIX.$action];
    }
    private static function keyboard(array $rows): array {return ['inline_keyboard'=>$rows];}
    private static function send(string $chat,string $text,array $rows=[],array $extra=[]): void {
        self::api('sendMessage',array_merge(['chat_id'=>$chat,'text'=>osgs_clip($text,3900),
            'parse_mode'=>'HTML','disable_web_page_preview'=>'true'],
            $rows?['reply_markup'=>self::keyboard($rows)]:[],$extra));
    }
    /** A single reusable dashboard per chat, rather than a new inline keyboard on every tap. */
    private static function panel_key(string $chat): string {
        return 'osgs_tg_panel_'.md5($chat);
    }
    private static function panel_id(string $chat): int {
        return max(0,(int)get_transient(self::panel_key($chat)));
    }
    private static function save_panel_id(string $chat,int $id): void {
        if($id>0)set_transient(self::panel_key($chat),$id,30*DAY_IN_SECONDS);
    }
    /** After manually typing a command/reply, move controls below the user's new message. */
    private static function new_panel_after_input(string $chat): void {
        $old=self::panel_id($chat);
        if($old>0)self::retire_panel($chat,$old);
        delete_transient(self::panel_key($chat));
    }
    private static function retire_panel(string $chat,int $id): void {
        if($id<=0)return;
        // Remove the buttons from the previous panel to avoid conflicting controls.
        self::api('editMessageReplyMarkup',['chat_id'=>$chat,'message_id'=>$id,
            'reply_markup'=>self::keyboard([])]);
    }
    private static function show(string $chat,string $text,array $rows=[],int $msg=0): void {
        $stored=self::panel_id($chat);
        $target=$msg>0?$msg:$stored;
        $payload=['chat_id'=>$chat,'text'=>osgs_clip($text,3900),'parse_mode'=>'HTML',
            'disable_web_page_preview'=>'true','reply_markup'=>self::keyboard($rows)];
        if($target>0){
            $ok=self::api('editMessageText',$payload+['message_id'=>$target]);
            if(!empty($ok['ok'])){
                if($stored>0 && $stored!==$target)self::retire_panel($chat,$stored);
                self::save_panel_id($chat,$target);
                return;
            }
            // A deleted/expired original message cannot be edited; replace it once.
            // Editing errors must not cause a duplicate for 'message is not modified'.
        }
        $sent=self::api('sendMessage',$payload);
        $newId=(int)($sent['result']['message_id']??0);
        if($newId>0){
            if($stored>0 && $stored!==$newId)self::retire_panel($chat,$stored);
            self::save_panel_id($chat,$newId);
        }
    }
    private static function session_key(string $chat,string $user): string {
        return 'osgs_tg_s_'.md5($chat.':'.$user);
    }
    private static function session(string $chat,string $user): array {
        $v=get_transient(self::session_key($chat,$user));
        return is_array($v)?$v:[];
    }
    private static function save_session(string $chat,string $user,array $s): void {
        set_transient(self::session_key($chat,$user),$s,self::SESSION_TTL);
    }
    private static function forget(string $chat,string $user): void {
        delete_transient(self::session_key($chat,$user));
    }
    private static function page(int $p,int $n,int $per=self::PAGE_SIZE): int {
        return max(0,min(max(0,(int)ceil($n/$per)-1),$p));
    }
    /** Start a secure, per-user message draft. Blink modes affect only the next drafted message. */
    private static function ask_for_message(string $chat,string $user,string $mode): void {
        $allowed=['await_message','await_blink','await_soft'];
        if(!in_array($mode,$allowed,true))return;
        $s=self::session($chat,$user);
        $s['mode']=$mode;
        unset($s['draft']);
        $title=match($mode){
            'await_blink'=>'✨ <b>Send text to blink</b>',
            'await_soft'=>'💫 <b>Send text for soft blinking</b>',
            default=>'✍️ <b>Write your message now</b>'
        };
        $tip=$mode==='await_message'
            ? 'For selected words use [blink]word[/blink] or [soft]word[/soft].'
            : 'The complete text will blink. To animate just one word, use [blink]word[/blink] or [soft]word[/soft] in a regular message.';
        $result=self::api('sendMessage',['chat_id'=>$chat,'text'=>$title."\n\n".
            'Send your message, then choose a theme and duration. '. $tip."\n".
            'Send /cancel to stop.','parse_mode'=>'HTML',
            'reply_markup'=>['force_reply'=>true,'input_field_placeholder'=>'Your screen message…']]);
        $s['prompt_id']=(int)($result['result']['message_id']??0);
        self::save_session($chat,$user,$s);
    }
    private static function stage_draft(string $chat,string $user,string $text,array $entities=[],string $mode='await_message',int $minutes=10): void {
        $s=self::session($chat,$user);
        $message=osgs_clip(trim($text),1200);
        if($message==='')return;
        $html=self::formatted($message,$entities);
        $animation=$mode==='await_blink'?'blink':'soft';
        $s['draft']=['text'=>$message,'html'=>$html,'template'=>'clean',
            'minutes'=>$minutes,'until_at'=>null,'animation'=>$animation];
        $s['mode']='draft';
        unset($s['prompt_id']);
        self::save_session($chat,$user,$s);
        self::template_menu($chat,$user);
    }
    /** Saved in Settings > Telegram quick buttons; fallback supports older installations. */
    private static function preset(string $key): string {
        $cfg=OSGS::settings();
        return match($key) {
            'lunch'=>(string)($cfg['telegram_lunch_text']??'Closed for lunch. Back soon!'),
            'soon'=>(string)($cfg['telegram_soon_text']??'Back soon! We will reopen shortly.'),
            'closed'=>'Closed for today. See you tomorrow!',
            default=>''
        };
    }
    /** Executes immediately: no preview, selection, or second confirmation. */
    private static function one_tap(string $key): array {
        if ($key==='clear') {
            OSGS::set_message('');
            return ['label'=>'🧹 Temporary message cleared.','text'=>''];
        }
        if (!in_array($key,['lunch','soon'],true)) return ['label'=>'Unknown action.','text'=>''];
        $cfg=OSGS::settings();
        $message=trim(self::preset($key));
        if ($message==='') return ['label'=>'Quick message is empty. Update it in WordPress.','text'=>''];
        $minutes=max(0,min(1440,(int)($cfg['telegram_'.$key.'_minutes']??10)));
        $template=OSGS::template((string)($cfg['telegram_'.$key.'_template']??'notice'));
        // One-tap message animation is controlled only by inline rich-text effects.
        // Ignore the old whole-message setting, including previously saved soft blink.
        $savedHtml=trim((string)($cfg['telegram_'.$key.'_html']??''));
        $html=$savedHtml!==''?OSGS::safe_rich_html($savedHtml):self::tagged($message);
        OSGS::set_message($message,$minutes,$html,$template,null,'none');
        return [
            'label'=>$key==='lunch'?'🍽 Lunch message is live.':'⏳ Back-soon message is live.',
            'text'=>$message,
            'time'=>$minutes===0?'Until cleared':$minutes.' min'
        ];
    }
    private static function state_text(): string {
        $slides=OSGS::list_slides();$play=(array)get_option('osgs_playlist_filter',[]);
        $enabled=array_filter($slides,static fn($s)=>!empty($s['enabled']) && empty($s['auto_paused']));
        $visible=array_filter($enabled,static fn($s)=>!$play || in_array($s['id'],$play));
        $m=OSGS::message(); $paused=(bool)get_option('osgs_paused',false);
        $s="<b>📺 Your Store · Live</b>\n\n";
        $seen=(int)get_option('osgs_screen_seen',0);
        $online=$seen>0 && time()-$seen<=180;
        $s.='Playback: '.($paused?'⏸ Paused':'▶️ Enabled')."\n";
        $s.='Screen requests: '.($online?'🟢 Recently active':'⚪ None recently')." (public URL, device not verified)\n";
        $s.='Slides: <b>'.count($visible).'</b> showing / '.count($slides)." total\n";
        $s.='Playlist: '.($play?'Custom ('.count($play).' selected)':'All enabled')."\n";
        if ($m) {
            $s.='Message: <b>'.self::esc(osgs_clip((string)$m['text'],160))."</b>\n";
            $s.='Expires: '.(!empty($m['until'])?wp_date('H:i',(int)$m['until']):'Until cleared')."\n";
        } else $s.="Message: None\n";
        $last=(int)get_option('osgs_last_sync',0);
        $s.='WooCommerce sync: '.($last?wp_date('d M H:i',$last):'Not yet synced');
        return $s;
    }
    private static function main(string $chat,int $mid=0): void {
        $slides=OSGS::list_slides();
        $active=count(array_filter($slides,static fn($slide)=>!empty($slide['enabled']) && empty($slide['auto_paused'])));
        $message=OSGS::message();
        $paused=(bool)get_option('osgs_paused',false);
        $label=$message?'💬 '.self::esc(osgs_clip((string)$message['text'],70)):'💬 No temporary message';
        self::show($chat,"<b>📺 Your Store</b>\n".
            ($paused?'⏸ Paused':'▶️ Active').' · '.$active.' slides' ."\n".$label,[
            [self::btn('🧹 Clear temporary message','fast:clear')],
            [self::btn('🍽 Lunch break','fast:lunch'),self::btn('⏳ Back soon','fast:soon')],
            [self::btn('✍️ Write a message','message'),self::btn('🎞 Slides & playlist','slides:0')],
            [self::btn('⚙️ More options','tools'),self::btn('🔄 Refresh','home')]
        ],$mid);
    }
    private static function tools_menu(string $chat,int $mid=0): void {
        $paused=(bool)get_option('osgs_paused',false);
        self::show($chat,'<b>⚙️ More options</b>\nChoose what to manage.',[
            [self::btn('🚲 Add products','cats:0'),self::btn('📊 Full status','status')],
            [self::btn($paused?'▶️ Resume slideshow':'⏸ Pause slideshow',$paused?'resume':'pause'),self::btn('🔄 Refresh prices','sync')],
            [self::btn('❔ Help','help')],
            [self::btn('⬅️ Back','home')]
        ],$mid);
    }
    private static function message_menu(string $chat,int $mid=0): void {
        self::show($chat,"<b>✍️ New message</b>\nEnter your own message or choose a preset.",[
            [self::btn('✍️ Write text','write')],
            [self::btn('✨ Blink','write:blink'),self::btn('💫 Soft blink','write:soft')],
            [self::btn('🌙 Closed today','preset:closed')],
            [self::btn('⬅️ Back','home')]
        ],$mid);
    }
    private static function template_menu(string $chat,string $user,int $mid=0): void {
        $s=self::session($chat,$user);
        if (empty($s['draft']['text'])) {self::message_menu($chat,$mid);return;}
        self::show($chat,'<b>🎨 Choose a message theme</b>'."\n\n".
            self::esc(osgs_clip($s['draft']['text'],350))."\n\n".'Choose a visual template:',[
            [self::btn('🤍 Clean','tpl:clean'),self::btn('ℹ️ Information','tpl:notice')],
            [self::btn('🚨 Alert','tpl:alert'),self::btn('🌙 Dark','tpl:night')],
            [self::btn('☀️ Warm','tpl:warm'),self::btn('🎉 Campaign','tpl:promo')],
            [self::btn('❌ Cancel','cancel')]
        ],$mid);
    }
    private static function duration_menu(string $chat,string $user,int $mid=0): void {
        $s=self::session($chat,$user);
        if (empty($s['draft']['text'])) {self::message_menu($chat,$mid);return;}
        self::show($chat,'<b>⏱ How long should the message stay?</b>'."\n\n".
            'Theme: <b>'.self::esc(ucfirst($s['draft']['template']??'clean')).'</b>',[
            [self::btn('5 minutes','dur:5'),self::btn('10 minutes','dur:10')],
            [self::btn('15 minutes','dur:15'),self::btn('30 minutes','dur:30')],
            [self::btn('1 hour','dur:60'),self::btn('Until cleared','dur:0')],
            [self::btn('⌨️ Custom duration / end time','dur:custom')],
            [self::btn('⬅️ Themes','theme'),self::btn('❌ Cancel','cancel')]
        ],$mid);
    }
    /** Parse manual durations: 25, 25m, 1h, 1h30m, or clock time 14:30 in WP timezone. */
    private static function custom_duration(string $input): ?array {
        $raw=trim($input);
        if(preg_match('/^(?:until\s+)?([01]?\d|2[0-3]):([0-5]\d)$/i',$raw,$m)){
            $now=new DateTimeImmutable('now',wp_timezone());
            $end=$now->setTime((int)$m[1],(int)$m[2],0);
            if($end<=$now)$end=$end->modify('+1 day');
            $seconds=$end->getTimestamp()-$now->getTimestamp();
            if($seconds<1 || $seconds>86400)return null;
            return ['minutes'=>(int)ceil($seconds/60),'until_at'=>$end->getTimestamp()];
        }
        $raw=preg_replace('/\s+/u','',strtolower($raw));
        $minutes=null;
        if(preg_match('/^\d{1,4}$/',$raw))$minutes=(int)$raw;
        elseif(preg_match('/^(?:(\d{1,2})h)?(?:(\d{1,4})m)?$/',$raw,$m) && $raw!=='' && $raw!=='h' && $raw!=='m') {
            $hours=(int)($m[1]??0);$mins=(int)($m[2]??0);
            $minutes=$hours*60+$mins;
        }
        if($minutes===null || $minutes<1 || $minutes>1440)return null;
        return ['minutes'=>$minutes,'until_at'=>null];
    }
    private static function ask_custom_duration(string $chat,string $user): void {
        $s=self::session($chat,$user);
        if(empty($s['draft']['text'])) {self::message_menu($chat);return;}
        $s['mode']='await_duration';
        $response=self::api('sendMessage',['chat_id'=>$chat,'parse_mode'=>'HTML',
            'text'=>"<b>⌨️ Enter a custom time</b>\n\n".
                "<b>Duration:</b> <code>25</code> · <code>25m</code> · <code>1h30m</code> · <code>2h</code>\n".
                "<b>Until clock time:</b> <code>14:30</code> (WordPress local time)\n\n".
                "Maximum 24 hours. Send /cancel to discard.",
            'reply_markup'=>['force_reply'=>true,'input_field_placeholder'=>'25m or 14:30']]);
        $s['prompt_id']=(int)($response['result']['message_id']??0);
        self::save_session($chat,$user,$s);
    }
    private static function animation_menu(string $chat,string $user,int $mid=0): void {
        $s=self::session($chat,$user);
        if(empty($s['draft']['text'])) {self::message_menu($chat,$mid);return;}
        $selected=(string)($s['draft']['animation']??'soft');
        $check=static fn(string $x): string=>$selected===$x?'✅ ':'▫️ ';
        self::show($chat,"<b>✨ Message animation</b>\n\nSoft blink is the default for temporary messages. Choose another mode or continue:",[
            [self::btn($check('soft').'💫 Soft blink','anim:soft')],
            [self::btn($check('blink').'✨ Normal blink','anim:blink'),self::btn($check('none').'◻️ No animation','anim:none')],
            [self::btn('👀 Preview and confirm','review')],
            [self::btn('⬅️ Change duration','duration'),self::btn('❌ Cancel','cancel')]
        ],$mid);
    }
    private static function apply_animation(string $html,string $animation): string {
        $html=OSGS::safe_rich_html($html);
        $class=match($animation){'blink'=>'osgs-blink','soft'=>'osgs-blink-soft',default=>''};
        return $class?OSGS::safe_rich_html('<span class="'.$class.'">'.$html.'</span>'):$html;
    }
    private static function duration_label(array $draft): string {
        $until=(int)($draft['until_at']??0);
        if($until>0)return 'Until '.wp_date('H:i',$until).' (store time)';
        $minutes=(int)($draft['minutes']??10);
        return $minutes===0?'Until cleared':$minutes.' minutes';
    }
    private static function review(string $chat,string $user,int $mid=0): void {
        $s=self::session($chat,$user);$d=(array)($s['draft']??[]);
        if (empty($d['text'])) {self::message_menu($chat,$mid);return;}
        $animation=match($d['animation']??'soft'){'blink'=>'Normal blink','none'=>'Off',default=>'Soft blink'};
        self::show($chat,"<b>👀 Preview before publishing</b>\n\n".
            self::esc($d['text'])."\n\n".
            'Style: '.self::esc(ucfirst($d['template']??'clean'))."\n".
            'Duration: '.self::esc(self::duration_label($d))."\n".
            'Animation: <b>'.self::esc($animation)."</b>\n\n".
            'Nothing is shown until you confirm.',[
            [self::btn('🚀 SHOW ON SCREEN','publish')],
            [self::btn('🎨 Theme','theme'),self::btn('⏱ Duration','duration')],
            [self::btn('✨ Animation','animation'),self::btn('❌ Cancel','cancel')]
        ],$mid);
    }
    private static function slides_menu(string $chat,int $p=0,int $mid=0): void {
        $slides=OSGS::list_slides(); $n=count($slides);$p=self::page($p,$n);
        $items=array_slice($slides,$p*self::PAGE_SIZE,self::PAGE_SIZE);
        $text="<b>🎞 Slide manager</b>\n\nTap a slide to turn it on/off.\n".
            "✅ Enabled · ⬜ Disabled · 📦 Out of stock\n".
            'Total: '.$n.' · Page '.($p+1).'/'.max(1,(int)ceil($n/self::PAGE_SIZE));
        $rows=[];
        foreach ($items as $sl) {
            $flag=!empty($sl['auto_paused'])?'📦':(!empty($sl['enabled'])?'✅':'⬜');
            $rows[]=[self::btn($flag.' '.osgs_clip((string)$sl['title'],34),'toggle:'.$sl['id'].':'.$p)];
        }
        $nav=[]; if($p>0)$nav[]=self::btn('◀️ Prev','slides:'.($p-1));
        if(($p+1)*self::PAGE_SIZE<$n)$nav[]=self::btn('Next ▶️','slides:'.($p+1));
        if($nav)$rows[]=$nav;
        $rows[]=[self::btn('✅ Select playlist','pick:0'),self::btn('🔁 All enabled','all:ask')];
        $rows[]=[self::btn('⬅️ Home','home')];
        self::show($chat,$text,$rows,$mid);
    }
    private static function selection_menu(string $chat,string $user,int $p=0,int $mid=0): void {
        $slides=OSGS::list_slides();$n=count($slides);$p=self::page($p,$n);
        $s=self::session($chat,$user);
        if (!isset($s['selected'])) {
            $stored=array_map('intval',(array)get_option('osgs_playlist_filter',[]));
            $s['selected']=$stored?:array_column(array_filter($slides,static fn($sl)=>$sl['enabled']&&!$sl['auto_paused']),'id');
            self::save_session($chat,$user,$s);
        }
        $selected=array_map('intval',$s['selected']);$rows=[];
        foreach(array_slice($slides,$p*self::PAGE_SIZE,self::PAGE_SIZE) as $sl) {
            $flag=in_array($sl['id'],$selected,true)?'✅':'⬜';
            $rows[]=[self::btn($flag.' '.osgs_clip((string)$sl['title'],35),'sel:'.$sl['id'].':'.$p)];
        }
        $nav=[];if($p>0)$nav[]=self::btn('◀️','pick:'.($p-1));
        if(($p+1)*self::PAGE_SIZE<$n)$nav[]=self::btn('▶️','pick:'.($p+1));
        if($nav)$rows[]=$nav;
        $rows[]=[self::btn('🚀 APPLY '.count($selected).' SELECTED','apply')];
        $rows[]=[self::btn('☑️ Select all','selall'),self::btn('🧽 Clear choices','selnone')];
        $rows[]=[self::btn('⬅️ Slides','slides:0')];
        self::show($chat,"<b>🎛 Build your playlist</b>\n\nChoose exactly which slides should rotate. " .
            "Selections are staged until you press APPLY.\n".
            'Selected: <b>'.count($selected).'</b> · Page '.($p+1).'/'.max(1,(int)ceil($n/self::PAGE_SIZE)),$rows,$mid);
    }
    private static function categories_menu(string $chat,int $page=0,int $mid=0): void {
        if(!function_exists('wc_get_products')) {
            self::show($chat,'WooCommerce is not available.',[[self::btn('⬅️ Home','home')]],$mid);return;
        }
        $terms=get_terms(['taxonomy'=>'product_cat','hide_empty'=>true,'orderby'=>'name','order'=>'ASC']);
        if(is_wp_error($terms) || !is_array($terms))$terms=[];
        $page=self::page($page,count($terms),8);$rows=[];
        foreach(array_slice($terms,$page*8,8) as $term)$rows[]=[self::btn('🚲 '.osgs_clip($term->name,34),'cat:'.$term->term_id.':0')];
        $nav=[];if($page>0)$nav[]=self::btn('◀️','cats:'.($page-1));
        if(($page+1)*8<count($terms))$nav[]=self::btn('▶️','cats:'.($page+1));
        if($nav)$rows[]=$nav;
        $rows[]=[self::btn('⬅️ Home','home')];
        self::show($chat,"<b>🚲 WooCommerce categories</b>\n\nSelect a category to browse only products that are in stock.",$rows,$mid);
    }
    private static function product_page(string $chat,string $user,int $cat,int $page=0,int $mid=0): void {
        if(!function_exists('wc_get_products')){self::categories_menu($chat,0,$mid);return;}
        $term=get_term($cat,'product_cat');
        if(!$term || is_wp_error($term)){self::categories_menu($chat,0,$mid);return;}
        $page=max(0,min(999,$page));
        $q=wc_get_products(['status'=>'publish','limit'=>self::PAGE_SIZE,'page'=>$page+1,
            'paginate'=>true,'orderby'=>'title','order'=>'ASC','stock_status'=>'instock',
            'category'=>[$term->slug]]);
        $items=is_object($q)&&isset($q->products)?$q->products:[];
        $total=is_object($q)?(int)$q->max_num_pages:1;
        if($page>=max(1,$total)){$page=max(0,$total-1);self::product_page($chat,$user,$cat,$page,$mid);return;}
        $s=self::session($chat,$user);
        $selected=array_map('intval',$s['products']??[]);$rows=[];
        foreach($items as $product){
            $id=(int)$product->get_id();$on=in_array($id,$selected,true);
            $price=wp_strip_all_tags(wc_price($product->get_price()));
            $rows[]=[self::btn(($on?'✅ ':'⬜ ').osgs_clip($product->get_name(),23).' · '.osgs_clip($price,14),
                'prod:'.$cat.':'.$id.':'.$page)];
        }
        $nav=[];if($page>0)$nav[]=self::btn('◀️ Prev','cat:'.$cat.':'.($page-1));
        if($page+1<$total)$nav[]=self::btn('Next ▶️','cat:'.$cat.':'.($page+1));
        if($nav)$rows[]=$nav;
        $rows[]=[self::btn('➕ ADD SELECTED ('.count($selected).')','products:add')];
        $rows[]=[self::btn('🧹 Clear selected','products:reset'),self::btn('⬅️ Categories','cats:0')];
        self::show($chat,'<b>🚲 '.self::esc($term->name)."</b>\n\n".
            'Only in-stock products · Page '.($page+1).'/'.max(1,$total)."\n".
            'Tap products to select. Nothing is added until you press ADD SELECTED.', $rows,$mid);
    }
    private static function help(string $chat,int $mid=0): void {
        self::show($chat,"<b>⚙️ Telegram Control Center</b>\n\n".
            "<b>Interactive:</b> /start — open menus, select slides/products, design and time messages.\n\n".
            "<b>One-tap buttons:</b> Cancel temporary message, Lunch break, Back soon (no confirmation). Text, duration and theme are editable in WordPress → Display Studio → Telegram.\n\n".
            "<b>Quick commands</b>\n".
            "/status — live overview\n".
            "/message 10m Text — send a soft-blinking message for 10 minutes\n".
            "/message 10m --none Text — send a non-blinking message\n".
            "/message 0m Text — keep until cleared\n".
            "/blink — type a whole blinking message\n".
            "/soft — type a whole softly blinking message\n".
            "/blink Text · /soft Text — create a draft directly\n".
            "/slides — slide list and controls\n".
            "/select 123,456 — select exact IDs\n".
            "/on 123 · /off 123 — enable/disable a slide\n".
            "/all — rotate all enabled slides\n".
            "/pause · /resume — control display\n".
            "/clear — remove temporary message\n".
            "/cancel — cancel an unfinished draft\n".
            "/id — show chat and user IDs\n\n".
            "<b>Animate just one word:</b> /message 10m We will be [blink]back[/blink] soon!\n".
            "For soft animation use [soft]word[/soft]. /blink and /soft animate the entire message.\n".
            "<b>Manual time:</b> In Message studio choose Custom duration. Send <code>25m</code>, <code>1h30m</code>, or <code>14:30</code> (store time).\n".
            "All temporary messages default to soft blink. Choose Animation to switch it off or use normal blink.\n".
            "<i>Telegram menus require a registered webhook and permitted Chat/User IDs.</i>",[
            [self::btn('🏠 Main menu','home')]
        ],$mid);
    }
    private static function tagged(string $text): string {
        // Escape first, then accept only safe formatting markers, no arbitrary HTML.
        $out=self::esc($text);
        foreach(['blink'=>'osgs-blink','soft'=>'osgs-blink-soft'] as $tag=>$class){
            $out=preg_replace('/\['.$tag.'\]([\s\S]{1,300}?)\[\/'.$tag.'\]/iu',
                '<span class="'.$class.'">$1</span>',$out);
        }
        return str_replace("\n",'<br>',$out);
    }
    private static function formatted(string $text,array $entities): string {
        // Telegram offsets are UTF-16 code units. Only whitelisted inline styles.
        $symbols=preg_split('//u',$text,-1,PREG_SPLIT_NO_EMPTY);
        if(!is_array($symbols))return self::tagged($text);
        $offsets=[0];$count=0;
        foreach($symbols as $char){
            $utf16=function_exists('mb_convert_encoding')?mb_convert_encoding($char,'UTF-16LE','UTF-8'):
                (function_exists('iconv')?@iconv('UTF-8','UTF-16LE',$char):false);
            if($utf16===false)return self::tagged($text);
            $count+=(int)(strlen($utf16)/2);$offsets[]=$count;
        }
        $start=[];$end=[];
        foreach(array_slice($entities,0,80) as $entity){
            $kind=$entity['type']??'';
            $tags=['bold'=>'strong','italic'=>'em','underline'=>'u'];
            if(!isset($tags[$kind]))continue;
            $a=(int)($entity['offset']??-1);$b=$a+(int)($entity['length']??0);
            $from=array_search($a,$offsets,true);$to=array_search($b,$offsets,true);
            if($from===false||$to===false||$from>=$to)continue;
            $tag=$tags[$kind];$start[$from][]=$tag;$end[$to][]=$tag;
        }
        $html='';$open=[];
        foreach($symbols as $i=>$char){
            if(isset($end[$i]))foreach(array_reverse($end[$i]) as $tag){
                // Avoid malformed overlapping markup in case of corrupted entities.
                if(end($open)!==$tag)continue;
                $html.='</'.$tag.'>';array_pop($open);
            }
            if(isset($start[$i]))foreach($start[$i] as $tag){$html.='<'.$tag.'>';$open[]=$tag;}
            $html.=$char==="\n"?'<br>':self::esc($char);
        }
        while($open)$html.='</'.array_pop($open).'>';
        foreach(['blink'=>'osgs-blink','soft'=>'osgs-blink-soft'] as $tag=>$class)
            $html=preg_replace('/\['.$tag.'\]([\s\S]{1,300}?)\[\/'.$tag.'\]/iu',
                '<span class="'.$class.'">$1</span>',$html);
        return OSGS::safe_rich_html($html);
    }
    public static function handle(WP_REST_Request $r): WP_REST_Response {
        $data=$r->get_json_params();
        if(!is_array($data))return OSGS::respond(['ok'=>true]);
        // Deduplicate webhook retries without treating partial executions as success.
        $update=(string)($data['update_id']??'');
        $key=$update!==''?'osgs_tg_u_'.md5($update):'';
        if($key && get_transient($key))return OSGS::respond(['ok'=>true]);
        if($key)set_transient($key,1,DAY_IN_SECONDS);
        if(isset($data['callback_query']))self::callback((array)$data['callback_query']);
        else self::incoming((array)($data['message']??[]));
        return OSGS::respond(['ok'=>true]);
    }
    private static function incoming(array $msg): void {
        $chat=(string)($msg['chat']['id']??'');$user=(string)($msg['from']['id']??'');
        $type=(string)($msg['chat']['type']??'private');$text=trim((string)($msg['text']??''));
        if(!$chat || !$user || $text==='')return;
        $parts=preg_split('/\s+/u',$text,2);$cmd=strtolower(preg_replace('/@[^\s]+$/','',$parts[0]));$arg=trim($parts[1]??'');
        if($cmd==='/id' || ($cmd==='/start' && !self::authorized($chat,$user,$type))){
            self::send($chat,'Chat ID: <code>'.self::esc($chat).'</code>'."\n".'User ID: <code>'.self::esc($user).
                "</code>\nAsk an administrator to allow these IDs in Display Studio.");return;
        }
        if(!self::authorized($chat,$user,$type)){
            if(str_starts_with($text,'/'))self::send($chat,'🔒 Unauthorized. Ask the administrator to allow this chat and user.');
            return;
        }
        if($cmd==='/start'||$cmd==='/menu'){self::forget($chat,$user);self::new_panel_after_input($chat);self::main($chat);return;}
        if($cmd==='/cancel'){self::forget($chat,$user);self::new_panel_after_input($chat);self::main($chat);return;}
        $session=self::session($chat,$user);
        // Only treat free text as a draft in a private chat, or as reply to the bot prompt.
        $replyTo=(int)($msg['reply_to_message']['message_id']??0);
        $mode=(string)($session['mode']??'');
        $isPromptReply=$replyTo && $replyTo===(int)($session['prompt_id']??0);
        if(!str_starts_with($text,'/') && in_array($mode,['await_message','await_blink','await_soft'],true)
           && ($type==='private' || $isPromptReply)) {
            self::new_panel_after_input($chat);
            self::stage_draft($chat,$user,$text,(array)($msg['entities']??[]),$mode);
            return;
        }
        if(!str_starts_with($text,'/') && $mode==='await_duration'
           && ($type==='private' || $isPromptReply)) {
            $custom=self::custom_duration($text);
            if(!$custom){
                self::send($chat,'Invalid time. Try <code>25m</code>, <code>1h30m</code>, or <code>14:30</code> (maximum 24 hours).');
                return;
            }
            $session['draft']['minutes']=$custom['minutes'];
            $session['draft']['until_at']=$custom['until_at'];
            $session['mode']='draft';unset($session['prompt_id']);
            self::save_session($chat,$user,$session);
            self::new_panel_after_input($chat);
            self::animation_menu($chat,$user);
            return;
        }
        $slides=OSGS::list_slides();$ids=array_map('intval',array_column($slides,'id'));
        $reply='Unknown command. Use /start for the control center.';
        switch($cmd){
          case '/help':self::help($chat);return;
          case '/status':self::new_panel_after_input($chat);self::show($chat,self::state_text(),[[self::btn('🏠 Home','home')]]);return;
          case '/slides':self::new_panel_after_input($chat);self::slides_menu($chat);return;
          case '/message':
            $minutes=10;
            if(preg_match('/^(\d{1,4})m\s+([\s\S]+)$/ui',$arg,$m)){$minutes=min(1440,(int)$m[1]);$arg=$m[2];}
            if($arg===''){self::message_menu($chat);return;}
            $arg=osgs_clip($arg,1200);
            $animation='soft';
            // Direct-command override: /message 10m --none Text (or --soft / --blink).
            if(preg_match('/^--(none|soft|blink)\s+([\s\S]+)$/ui',$arg,$override)) {
                $animation=$override[1];$arg=$override[2];
            }
            OSGS::set_message($arg,$minutes,self::tagged($arg),'clean',null,$animation);
            self::new_panel_after_input($chat);
            self::main($chat);return;
          case '/blink':case '/soft':
            $blinkMode=$cmd==='/blink'?'await_blink':'await_soft';
            if($arg===''){self::ask_for_message($chat,$user,$blinkMode);return;}
            $minutes=10;
            if(preg_match('/^(\d{1,4})m\s+([\s\S]+)$/ui',$arg,$m)){$minutes=min(1440,(int)$m[1]);$arg=$m[2];}
            self::new_panel_after_input($chat);
            self::stage_draft($chat,$user,$arg,[],$blinkMode,$minutes);
            return;
          case '/clear':OSGS::set_message('');$reply='🧹 Temporary message cleared.';break;
          case '/on':case '/off':
            if(!ctype_digit($arg) || !in_array((int)$arg,$ids,true)){$reply='Slide ID not found.';break;}
            update_post_meta((int)$arg,'_osgs_enabled',$cmd==='/on'?'1':'0');OSGS::bump();
            $reply='✅ Slide updated.';break;
          case '/select':
            if(!preg_match('/^\d+(?:[,\s]+\d+)*$/',$arg)){$reply='Use: /select 123,456';break;}
            $chosen=array_values(array_unique(array_map('intval',preg_split('/[,\s]+/',$arg))));
            if(count($chosen)>100 || array_diff($chosen,$ids)){$reply='One or more slide IDs are invalid.';break;}
            OSGS::set_playlist($chosen);$reply='✅ Playlist updated.';break;
          case '/all':OSGS::set_playlist([]);$reply='✅ All enabled slides selected.';break;
          case '/pause':OSGS::set_paused(true);$reply='⏸ Display paused.';break;
          case '/resume':OSGS::set_paused(false);$reply='▶️ Display resumed.';break;
          default:
            if(in_array(($session['mode']??''),['await_message','await_blink','await_soft'],true)){
                self::send($chat,'Send your message as text, or /cancel.');return;
            }
            if(($session['mode']??'')==='await_duration'){
                self::send($chat,'Send a custom time such as <code>25m</code>, <code>1h30m</code>, or <code>14:30</code>.');return;
            }
        }
        self::new_panel_after_input($chat);
        self::show($chat,'<b>'.self::esc($reply).'</b>',[[self::btn('🏠 Home','home')]]);
    }
    private static function callback(array $cb): void {
        $id=(string)($cb['id']??'');$chat=(string)($cb['message']['chat']['id']??'');
        $user=(string)($cb['from']['id']??'');$type=(string)($cb['message']['chat']['type']??'private');
        $mid=(int)($cb['message']['message_id']??0);$raw=(string)($cb['data']??'');
        if($id==='')return;
        if(!$chat||!$user||!self::authorized($chat,$user,$type)){
            self::api('answerCallbackQuery',['callback_query_id'=>$id,'text'=>'Not authorized','show_alert'=>'true']);return;
        }
        if(!str_starts_with($raw,self::PREFIX)){
            self::api('answerCallbackQuery',['callback_query_id'=>$id,'text'=>'Unsupported control']);return;
        }
        $act=substr($raw,strlen(self::PREFIX));
        if(strlen($act)>60){self::api('answerCallbackQuery',['callback_query_id'=>$id,'text'=>'Invalid action']);return;}
        // Stale inline keyboards are never allowed to act on the screen.
        // Retire old keyboards quietly, preserving just the latest control panel.
        $current=self::panel_id($chat);
        if($current>0 && $mid>0 && $mid!==$current){
            self::retire_panel($chat,$mid);
            self::api('answerCallbackQuery',['callback_query_id'=>$id,
                'text'=>'This menu is outdated. Please use the latest menu.']);
            return;
        }
        if(in_array($act,['fast:clear','fast:lunch','fast:soon'],true)){
            // One-tap actions show a short Telegram toast and refresh the SAME menu.
            $key=substr($act,5);
            $hadMessage=(bool)OSGS::message();
            self::forget($chat,$user);
            if($key==='clear' && !$hadMessage){
                self::api('answerCallbackQuery',['callback_query_id'=>$id,'text'=>'No temporary message to clear.']);
            }else{
                $result=self::one_tap($key);
                $toast=$key==='clear'?'🧹 Message cleared.':
                    ($key==='lunch'?'🍽 Lunch message is live.':'⏳ Back-soon message is live.');
                if($result['label']==='Quick message is empty. Update it in WordPress.')
                    $toast='Edit the quick message in WordPress first.';
                self::api('answerCallbackQuery',['callback_query_id'=>$id,'text'=>$toast]);
            }
            self::main($chat,$mid);
            return;
        }
        self::api('answerCallbackQuery',['callback_query_id'=>$id]);
        $s=self::session($chat,$user);
        if($act==='home'){self::main($chat,$mid);return;}
        if($act==='tools'){self::tools_menu($chat,$mid);return;}
        if($act==='status'){self::show($chat,self::state_text(),[[self::btn('🔃 Refresh','status'),self::btn('🏠 Home','home')]],$mid);return;}
        if($act==='help'){self::help($chat,$mid);return;}
        if($act==='message'){self::message_menu($chat,$mid);return;}
        if(in_array($act,['write','write:blink','write:soft'],true)){
            $mode=match($act){'write:blink'=>'await_blink','write:soft'=>'await_soft',default=>'await_message'};
            self::ask_for_message($chat,$user,$mode);return;
        }
        if(str_starts_with($act,'preset:')){
            $key=substr($act,7);$text=self::preset($key);if(!$text){self::message_menu($chat,$mid);return;}
            $s['draft']=['text'=>$text,'html'=>self::tagged($text),'template'=>'notice','minutes'=>10,'until_at'=>null,'animation'=>'soft'];
            $s['mode']='draft';self::save_session($chat,$user,$s);
            self::template_menu($chat,$user,$mid);return;
        }
        if($act==='theme'){self::template_menu($chat,$user,$mid);return;}
        if($act==='duration'){self::duration_menu($chat,$user,$mid);return;}
        if($act==='dur:custom'){self::ask_custom_duration($chat,$user);return;}
        if($act==='animation'){self::animation_menu($chat,$user,$mid);return;}
        if($act==='review'){self::review($chat,$user,$mid);return;}
        if(preg_match('/^anim:(soft|blink|none)$/',$act,$m)){
            if(empty($s['draft']['text'])){self::message_menu($chat,$mid);return;}
            $s['draft']['animation']=$m[1];self::save_session($chat,$user,$s);
            self::review($chat,$user,$mid);return;
        }
        if(preg_match('/^tpl:(clean|notice|alert|night|warm|promo)$/',$act,$m)){
            if(empty($s['draft']['text'])){self::message_menu($chat,$mid);return;}
            $s['draft']['template']=$m[1];self::save_session($chat,$user,$s);
            self::duration_menu($chat,$user,$mid);return;
        }
        if(preg_match('/^dur:(0|5|10|15|30|60)$/',$act,$m)){
            if(empty($s['draft']['text'])){self::message_menu($chat,$mid);return;}
            $s['draft']['minutes']=(int)$m[1];$s['draft']['until_at']=null;
            self::save_session($chat,$user,$s);
            self::animation_menu($chat,$user,$mid);return;
        }
        if($act==='publish'){
            $draft=(array)($s['draft']??[]);
            if(empty($draft['text'])){self::message_menu($chat,$mid);return;}
            if(!empty($draft['until_at']) && (int)$draft['until_at']<=time()){
                self::show($chat,'⌛ The selected end time has passed. Choose a new duration.',[
                    [self::btn('⏱ Change duration','duration'),self::btn('❌ Cancel','cancel')]
                ],$mid);return;
            }
            // Consume draft first, so repeated callback presses cannot publish twice.
            self::forget($chat,$user);
            OSGS::set_message($draft['text'],(int)($draft['minutes']??10),
                (string)($draft['html']??''),
                (string)($draft['template']??'clean'),
                !empty($draft['until_at'])?(int)$draft['until_at']:null,
                (string)($draft['animation']??'none'));
            self::show($chat,'✅ <b>Message is live!</b>'."\n\n".self::esc($draft['text']),[
                [self::btn('🧹 Clear message','clear:ask'),self::btn('🏠 Home','home')]
            ],$mid);return;
        }
        if($act==='cancel'){self::forget($chat,$user);self::main($chat,$mid);return;}
        if($act==='clear:ask'){
            self::show($chat,'⚠️ <b>Clear the temporary message?</b>'."\n".'The normal slideshow will resume.',[
                [self::btn('✅ Yes, clear','clear:yes'),self::btn('❌ Keep it','message')]
            ],$mid);return;
        }
        if($act==='clear:yes'){
            OSGS::set_message('');self::show($chat,'✅ Message cleared.',[[self::btn('🏠 Home','home')]],$mid);return;
        }
        if(preg_match('/^slides:(\d+)$/',$act,$m)){self::slides_menu($chat,(int)$m[1],$mid);return;}
        if(preg_match('/^toggle:(\d+):(\d+)$/',$act,$m)){
            $sid=(int)$m[1];$valid=array_column(OSGS::list_slides(),'id');
            if(!in_array($sid,$valid,true)){self::slides_menu($chat,(int)$m[2],$mid);return;}
            $cur=get_post_meta($sid,'_osgs_enabled',true);
            update_post_meta($sid,'_osgs_enabled',$cur==='0'?'1':'0');OSGS::bump();
            self::slides_menu($chat,(int)$m[2],$mid);return;
        }
        if(preg_match('/^pick:(\d+)$/',$act,$m)){self::selection_menu($chat,$user,(int)$m[1],$mid);return;}
        if(preg_match('/^sel:(\d+):(\d+)$/',$act,$m)){
            $sid=(int)$m[1];$valid=array_column(OSGS::list_slides(),'id');
            if(!in_array($sid,$valid,true)){self::selection_menu($chat,$user,(int)$m[2],$mid);return;}
            $selected=array_map('intval',$s['selected']??[]);
            if(in_array($sid,$selected,true))$selected=array_values(array_diff($selected,[$sid]));
            else $selected[]=$sid;
            $s['selected']=$selected;self::save_session($chat,$user,$s);
            self::selection_menu($chat,$user,(int)$m[2],$mid);return;
        }
        if($act==='selall' || $act==='selnone'){
            $s['selected']=$act==='selall'?array_column(OSGS::list_slides(),'id'):[];
            self::save_session($chat,$user,$s);self::selection_menu($chat,$user,0,$mid);return;
        }
        if($act==='apply'){
            $picked=array_values(array_unique(array_map('intval',$s['selected']??[])));
            $valid=array_column(OSGS::list_slides(),'id');$picked=array_values(array_intersect($picked,$valid));
            if(!$picked){self::show($chat,'No slides selected. Choose at least one slide.',[[self::btn('⬅️ Choose slides','pick:0')]],$mid);return;}
            OSGS::set_playlist($picked);self::forget($chat,$user);
            self::show($chat,'✅ Playlist updated: '.count($picked).' selected.',[[self::btn('🏠 Home','home')]],$mid);return;
        }
        if($act==='all:ask'){
            self::show($chat,'Switch back to all enabled slides?',[[self::btn('✅ All enabled','all:yes'),self::btn('↩️ Cancel','slides:0')]],$mid);return;
        }
        if($act==='all:yes'){
            OSGS::set_playlist([]);self::forget($chat,$user);self::slides_menu($chat,0,$mid);return;
        }
        if($act==='pause' || $act==='resume'){
            OSGS::set_paused($act==='pause');self::tools_menu($chat,$mid);return;
        }
        if($act==='sync'){
            $n=OSGS::sync_all();
            self::tools_menu($chat,$mid);return;
        }
        if(preg_match('/^cats:(\d+)$/',$act,$m)){self::categories_menu($chat,(int)$m[1],$mid);return;}
        if(preg_match('/^cat:(\d+):(\d+)$/',$act,$m)){self::product_page($chat,$user,(int)$m[1],(int)$m[2],$mid);return;}
        if(preg_match('/^prod:(\d+):(\d+):(\d+)$/',$act,$m)){
            $cat=(int)$m[1];$pid=(int)$m[2];$page=(int)$m[3];
            if(!function_exists('wc_get_product'))return;
            $product=wc_get_product($pid);$term=get_term($cat,'product_cat');
            if(!$product || !$term || is_wp_error($term) || $product->get_status()!=='publish'
                || $product->get_stock_status()!=='instock' || !has_term($cat,'product_cat',$pid)){
                self::product_page($chat,$user,$cat,$page,$mid);return;
            }
            $list=array_map('intval',$s['products']??[]);
            if(in_array($pid,$list,true))$list=array_values(array_diff($list,[$pid]));
            elseif(count($list)<30)$list[]=$pid;
            $s['products']=$list;self::save_session($chat,$user,$s);
            self::product_page($chat,$user,$cat,$page,$mid);return;
        }
        if($act==='products:reset'){
            $s['products']=[];self::save_session($chat,$user,$s);self::categories_menu($chat,0,$mid);return;
        }
        if($act==='products:add'){
            $chosen=array_values(array_unique(array_map('intval',$s['products']??[])));
            if(!$chosen){self::show($chat,'Choose one or more products first.',[[self::btn('🚲 Categories','cats:0')]],$mid);return;}
            $added=0;
            foreach(array_slice($chosen,0,30) as $pid)if(OSGS::insert_product($pid))$added++;
            if($added)OSGS::bump();
            $s['products']=[];self::save_session($chat,$user,$s);
            self::show($chat,'✅ Added '.(int)$added.' product slides. Skipped '.(count($chosen)-$added).
                ' (already added, missing photo/price, or unavailable).',[[self::btn('🚲 More bikes','cats:0'),self::btn('🏠 Home','home')]],$mid);return;
        }
        self::main($chat,$mid);
    }
}
