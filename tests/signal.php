<?php
/** Standalone static revision marker test, without a full WordPress install. */
error_reporting(E_ALL);
define('ABSPATH', __DIR__.'/');
$GLOBALS['osgs_test_options'] = ['osgs_revision'=>1];
$GLOBALS['osgs_test_dir'] = sys_get_temp_dir().'/osgs-signal-test-'.getmypid();
function get_option($key,$default=false){return $GLOBALS['osgs_test_options'][$key]??$default;}
function update_option($key,$value,$autoload=null){$GLOBALS['osgs_test_options'][$key]=$value;return true;}
function wp_upload_dir(){return ['basedir'=>$GLOBALS['osgs_test_dir'],'baseurl'=>'https://example.test/wp-content/uploads','error'=>false];}
function wp_mkdir_p($path){return is_dir($path)||mkdir($path,0755,true);}
function trailingslashit($path){return rtrim($path,'/').'/';}
function esc_url_raw($url){return $url;}
function wp_json_encode($value){return json_encode($value);}
require_once dirname(__DIR__).'/includes/class-osgs.php';
OSGS::bump();
$filename=$GLOBALS['osgs_test_dir'].'/osgs-signal/revision.json';
if(!is_file($filename)) throw new RuntimeException('Missing public static revision marker');
$first=json_decode(file_get_contents($filename),true);
if(array_keys($first)!==['revision'])throw new RuntimeException('Marker includes additional public data');
if(!is_int($first['revision'])||$first['revision']<=1)throw new RuntimeException('Invalid first revision');
OSGS::bump();
$next=json_decode(file_get_contents($filename),true);
if($next['revision']<=$first['revision'])throw new RuntimeException('Signal did not advance');
$method=new ReflectionMethod(OSGS::class,'ensure_signal');
if($method->invoke(null)!=='https://example.test/wp-content/uploads/osgs-signal/revision.json')throw new RuntimeException('Unexpected public URL');
@unlink($filename);@unlink($GLOBALS['osgs_test_dir'].'/osgs-signal/.revision.lock');@rmdir($GLOBALS['osgs_test_dir'].'/osgs-signal');@rmdir($GLOBALS['osgs_test_dir']);
echo "PASS: atomic public revision markers contain only a revision and advance on updates\n";
