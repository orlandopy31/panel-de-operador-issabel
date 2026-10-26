#!/usr/bin/php
<?php
/*
 * issabel-panel-lite-daemon.php
 * Un único cliente AMI comparte estado para todos los navegadores.
 * No usa SSE ni una conexión AMI por usuario.
 */
set_time_limit(0);
error_reporting(E_ALL);

const DATA_DIR = '/var/lib/issabel-panel-lite';
const STATE_FILE = DATA_DIR.'/state.json';
const GROUP_FILE = DATA_DIR.'/groups.json';
const DESIGN_DB = '/var/www/db/control_panel_design.db';

function logmsg($s) { fwrite(STDERR, '['.date('Y-m-d H:i:s').'] '.$s."\n"); }
function parseConfig($file) {
    $out=[];
    if (!is_readable($file)) return $out;
    foreach (file($file, FILE_IGNORE_NEW_LINES) as $line) {
        $line=trim($line); if ($line==='' || $line[0]==='#' || $line[0]===';') continue;
        if (preg_match('/^\$amp_conf\[[\'\"]([^\'\"]+)[\'\"]\]\s*=\s*[\'\"]?(.*?)[\'\"]?\s*;?$/',$line,$m)) $out[$m[1]]=trim($m[2]," \t\n\r\0\x0B\"';");
        elseif (preg_match('/^([A-Z0-9_]+)\s*=\s*[\'\"]?(.*?)[\'\"]?\s*$/',$line,$m)) $out[$m[1]]=trim($m[2]," \t\n\r\0\x0B\"';");
    }
    return $out;
}
function atomicJson($file,$data){$tmp=$file.'.tmp.'.getmypid();$json=json_encode($data,JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if(file_put_contents($tmp,$json,LOCK_EX)===false)return false;chmod($tmp,0640);return rename($tmp,$file);}
function initializeGroups(){
    if (is_readable(GROUP_FILE)) return;
    $groups=[];for($i=1;$i<=17;$i++)$groups[$i]=['id'=>$i,'name'=>'Grupo '.$i];$assign=[];
    if (class_exists('SQLite3') && is_readable(DESIGN_DB)) {
        try{$db=new SQLite3(DESIGN_DB,SQLITE3_OPEN_READONLY);$q=$db->query("SELECT a.name,a.description,i.id_device FROM area a LEFT JOIN item_box i ON i.id_area=a.id WHERE a.name GLOB 'Area[0-9]*'");while($r=$q->fetchArray(SQLITE3_ASSOC)){if(preg_match('/^Area(\d+)$/',$r['name'],$m)){ $id=(int)$m[1]; if($id>=1&&$id<=17){if(trim((string)$r['description'])!=='')$groups[$id]['name']=(string)$r['description'];if($r['id_device']!==null&&$r['id_device']!=='')$assign[(string)$r['id_device']]=$id;}}}$db->close();}catch(Exception $e){logmsg('No se pudo migrar areas: '.$e->getMessage());}
    }
    atomicJson(GROUP_FILE,['ok'=>true,'version'=>1,'updated_at'=>time(),'groups'=>array_values($groups),'assignments'=>$assign]);
}
class AmiClient {
    private $fp=null,$host,$port,$user,$pass,$seq=0;
    function __construct($h,$p,$u,$pw){$this->host=$h?:'127.0.0.1';$this->port=(int)($p?:5038);$this->user=$u;$this->pass=$pw;}
    function close(){if(is_resource($this->fp)){@fclose($this->fp);} $this->fp=null;}
    function connect(){
        $this->close();$errno=0;$err='';$this->fp=@stream_socket_client("tcp://{$this->host}:{$this->port}",$errno,$err,3);
        if(!$this->fp){logmsg("AMI connect: $err ($errno)");return false;}stream_set_timeout($this->fp,3);@fgets($this->fp);
        $r=$this->request('Login',['Username'=>$this->user,'Secret'=>$this->pass,'Events'=>'off'],null,3);
        if(!$r['ok']){logmsg('AMI login falló');$this->close();return false;}return true;
    }
    private function packet(){
        if(!is_resource($this->fp))return null;$p=[];$started=false;
        while(true){$line=@fgets($this->fp);if($line===false){$meta=stream_get_meta_data($this->fp);if(!empty($meta['timed_out']))return ['__timeout'=>1];return null;}$line=rtrim($line,"\r\n");if($line===''){if($started)return $p;continue;}$started=true;$pos=strpos($line,':');if($pos!==false){$k=substr($line,0,$pos);$v=ltrim(substr($line,$pos+1));if(isset($p[$k])){$p[$k].="\n".$v;}else$p[$k]=$v;}}
    }
    function request($action,$fields=[],$completeEvent=null,$timeout=4){
        if(!is_resource($this->fp)&&!$this->connect())return ['ok'=>false,'events'=>[],'response'=>[]];
        $aid='PL'.getmypid().'-'.(++$this->seq).'-'.sprintf('%.0f',microtime(true)*1000);$buf="Action: $action\r\nActionID: $aid\r\n";foreach($fields as $k=>$v)$buf.="$k: $v\r\n";$buf.="\r\n";
        if(@fwrite($this->fp,$buf)===false){$this->close();return ['ok'=>false,'events'=>[],'response'=>[]];}
        $events=[];$resp=[];$deadline=microtime(true)+$timeout;
        while(microtime(true)<$deadline){$p=$this->packet();if($p===null){$this->close();break;}if(isset($p['__timeout']))continue;if(isset($p['ActionID'])&&$p['ActionID']!==$aid)continue;if(isset($p['Response'])){$resp=$p;if(strcasecmp($p['Response'],'Error')===0)return ['ok'=>false,'events'=>$events,'response'=>$resp];if($completeEvent===null)return ['ok'=>strcasecmp($p['Response'],'Success')===0,'events'=>$events,'response'=>$resp];}
            if(isset($p['Event'])){if($completeEvent!==null&&strcasecmp($p['Event'],$completeEvent)===0)return ['ok'=>true,'events'=>$events,'response'=>$resp];$events[]=$p;}
        }
        return ['ok'=>false,'events'=>$events,'response'=>$resp];
    }
}
function dbConnect($c){
    mysqli_report(MYSQLI_REPORT_OFF);$h=$c['AMPDBHOST']??'localhost';$u=$c['AMPDBUSER']??'asteriskuser';$p=$c['AMPDBPASS']??'';$d=$c['AMPDBNAME']??'asterisk';$m=@new mysqli($h,$u,$p,$d);if($m->connect_errno)return null;$m->set_charset('utf8mb4');return $m;
}
function loadExtensions($db){
    $out=[];if(!$db)return $out;$r=$db->query("SELECT dial,tech,id AS extension,description FROM devices ORDER BY CAST(id AS UNSIGNED),id");if(!$r)return $out;while($x=$r->fetch_assoc()){$ext=(string)$x['extension'];$dial=trim((string)$x['dial']);if($dial==='')$dial=strtoupper((string)$x['tech']).'/'.$ext;$out[$ext]=['extension'=>$ext,'description'=>(string)$x['description'],'tech'=>strtoupper((string)$x['tech']),'dial'=>$dial];}$r->free();return $out;
}
function registrationSnapshot($ami,$extensions){
    $reg=[];$ips=[];
    $r=$ami->request('PJSIPShowEndpoints',[],'EndpointListComplete',5);if($r['ok'])foreach($r['events'] as $e){if(($e['Event']??'')!=='EndpointList')continue;$id=(string)($e['ObjectName']??'');$ds=strtolower((string)($e['DeviceState']??''));$contacts=(int)($e['Contacts']??0);$online=$contacts>0||($ds!==''&&!in_array($ds,['unavailable','unknown','invalid'],true));if($id!=='')$reg['PJSIP/'.$id]=$online;}
    $r=$ami->request('SIPpeers',[],'PeerlistComplete',5);if($r['ok'])foreach($r['events'] as $e){if(($e['Event']??'')!=='PeerEntry')continue;$id=(string)($e['ObjectName']??($e['Channeltype']==='SIP'?($e['ObjectName']??'') : ''));$st=strtoupper((string)($e['Status']??''));$ip=(string)($e['IPaddress']??'');$online=($ip!==''&&$ip!=='-none-'&&$ip!=='0.0.0.0'&&!str_contains($st,'UNREACHABLE')&&!str_contains($st,'UNKNOWN'));if($id!==''){$reg['SIP/'.$id]=$online;if($online)$ips['SIP/'.$id]=$ip;}}
    foreach($extensions as $ext=>$x){$key=strtoupper($x['dial']);if(!isset($reg[$key])&&$x['tech']==='PJSIP'){$reg[$key]=false;}if(!isset($reg[$key])&&$x['tech']==='SIP'){$reg[$key]=false;}}
    return [$reg,$ips];
}
function channelSnapshot($ami){$r=$ami->request('CoreShowChannels',[],'CoreShowChannelsComplete',4);if(!$r['ok'])return null;$out=[];foreach($r['events'] as $e){if(($e['Event']??'')==='CoreShowChannel')$out[]=$e;}return $out;}
function durationSec($s){if(!is_string($s)||!preg_match('/^(\d+):(\d+):(\d+)$/',$s,$m))return 0;return (int)$m[1]*3600+(int)$m[2]*60+(int)$m[3];}
function goodNumber($v,$self){$v=trim((string)$v);if($v===''||$v==='<unknown>'||$v==='unknown'||$v===$self)return null;return $v;}
function buildState($extensions,$channels,$reg,$ips,&$callMemory,$startedAt){
    $now=time();$byDial=[];$internal=array_fill_keys(array_keys($extensions),true);foreach($extensions as $ext=>$x)$byDial[strtoupper($x['dial'])]=$ext;
    $byExt=[];$byLinked=[];foreach($channels as $ch){$chan=(string)($ch['Channel']??'');$linked=(string)($ch['Linkedid']??'');if($linked!=='')$byLinked[$linked][]=$ch;$base=preg_replace('/-[^-]+$/','',$chan);$key=strtoupper($base);if(isset($byDial[$key]))$byExt[$byDial[$key]][]=$ch;}
    $rows=[];$sum=['total'=>count($extensions),'registered'=>0,'unregistered'=>0,'ringing'=>0,'incall'=>0];$newMem=[];
    foreach($extensions as $ext=>$x){$dialKey=strtoupper($x['dial']);$registered=$reg[$dialKey]??(!in_array($x['tech'],['PJSIP','SIP'],true));$arr=$byExt[$ext]??[];$main=null;$score=-1;foreach($arr as $ch){$st=strtolower((string)($ch['ChannelStateDesc']??''));$s=$st==='up'?30:(str_contains($st,'ring')?20:10);if($s>$score){$main=$ch;$score=$s;}}
        $status=$registered?'idle':'unregistered';$remote='';$rname='';$since=null;$uid='';
        if($main){$st=strtolower((string)($main['ChannelStateDesc']??''));$status=$st==='up'?'incall':(str_contains($st,'ring')?'ringing':'dialing');$uid=(string)($main['Uniqueid']??$main['Channel']??'');$cands=[goodNumber($main['ConnectedLineNum']??'',$ext),goodNumber($main['Exten']??'',$ext),goodNumber($main['CallerIDNum']??'',$ext)];foreach($cands as $c)if($c!==null){$remote=$c;break;}
            $linked=(string)($main['Linkedid']??'');if($linked!==''&&isset($byLinked[$linked]))foreach($byLinked[$linked] as $other){if(($other['Channel']??'')===($main['Channel']??''))continue;foreach(['ConnectedLineNum','Exten','CallerIDNum'] as $f){$c=goodNumber($other[$f]??'',$ext);if($c!==null&&(!isset($internal[$c])||$remote===''||$c!==$ext)){$remote=$c;break 2;}}}
            $rname=(string)($main['ConnectedLineName']??($main['CallerIDName']??''));$dur=durationSec((string)($main['Duration']??''));if(isset($callMemory[$ext])&&$callMemory[$ext]['uid']===$uid)$since=$callMemory[$ext]['since'];else $since=max($now-$dur,$now-86400);$newMem[$ext]=['uid'=>$uid,'since'=>$since];if($status==='ringing')$sum['ringing']++;if($status==='incall')$sum['incall']++;}
        if($registered)$sum['registered']++;else$sum['unregistered']++;
        $rows[]=['extension'=>$ext,'description'=>$x['description'],'tech'=>$x['tech'],'dial'=>$x['dial'],'registered'=>(bool)$registered,'ip'=>$ips[$dialKey]??null,'status'=>$status,'channel'=>$main['Channel']??null,'uniqueid'=>$uid?:null,'remote_number'=>$remote?:null,'remote_name'=>$rname?:null,'call_since'=>$since,'channel_state'=>$main['ChannelStateDesc']??null];
    }
    $callMemory=$newMem;return ['ok'=>true,'generated_at'=>$now,'daemon_started_at'=>$startedAt,'summary'=>$sum,'extensions'=>$rows];
}

@mkdir(DATA_DIR,0750,true);initializeGroups();$conf=parseConfig('/etc/amportal.conf');$amiHost=$conf['ASTMANAGERHOST']??'127.0.0.1';$amiPort=$conf['ASTMANAGERPORT']??5038;$amiUser=$conf['AMPMGRUSER']??'admin';$amiPass=$conf['AMPMGRPASS']??'';
$ami=new AmiClient($amiHost,$amiPort,$amiUser,$amiPass);$db=null;$extensions=[];$reg=[];$ips=[];$callMemory=[];$lastExt=0;$lastReg=0;$lastHash='';$lastWrite=0;$startedAt=time();$nextTick=microtime(true);
logmsg('Panel Lite iniciado');
while(true){
    $now=time();if(!$db||$db->ping()===false){$db=dbConnect($conf);if(!$db){logmsg('No se pudo conectar a MariaDB');sleep(2);continue;}}
    if($now-$lastExt>=60||!$extensions){$extensions=loadExtensions($db);$lastExt=$now;}
    if($now-$lastReg>=10||!$reg){list($reg,$ips)=registrationSnapshot($ami,$extensions);$lastReg=$now;}
    $channels=channelSnapshot($ami);if($channels===null){$ami->close();usleep(500000);continue;}
    $state=buildState($extensions,$channels,$reg,$ips,$callMemory,$startedAt);$hash=sha1(json_encode($state['extensions']).json_encode($state['summary']));if($hash!==$lastHash||$now-$lastWrite>=10){atomicJson(STATE_FILE,$state);$lastHash=$hash;$lastWrite=$now;}
    $nextTick+=1.0;$sleep=$nextTick-microtime(true);if($sleep>0)usleep((int)($sleep*1000000));else$nextTick=microtime(true);
}
?>
