<?php
declare(strict_types=1);
trait TempestPrivateWeb
{
    private const HOOKS='{015A6EB8-D6E5-4B93-B496-0D3F77AE9FE1}';
    private const BACKEND='{52165EDD-B2C0-40EC-8A59-42A97319B6E2}';
    private const FRONTEND='{8D6AE8F7-EA3A-4513-AA14-C3F9613B0E83}';
    private const PORTAL='{922E5A10-A048-463D-A973-97B4BBD9096B}';
    private function WeatherPath(): string {return $this->ReadPropertyString('WebhookPath');}
    private function AdminPath(): string {return '/hook/tempest_admin_'.$this->InstanceID;}
    private function DebugEvent(string $message,$data,int $format=0): void
    {
        if(!$this->ReadPropertyBoolean('DebugEnabled'))return;
        $text=is_string($data)?$data:json_encode($data,JSON_UNESCAPED_UNICODE);
        $this->SetValue('Debug',substr($this->GetValue('Debug').date('c').' '.$message.' '.substr((string)$text,0,4096)."\n",-32000));
        $this->SendDebug($message,$data,$format);
    }
    private function NativeBackend(): int
    {
        if(!function_exists('SECB_IsNativePortalAuthenticated')||!function_exists('SECB_GetNativePortalLoginURL'))return 0;
        $selected=$this->ReadPropertyInteger('SecretsInstanceID');
        if($selected>0&&function_exists('SEC_GetReplacementInfo'))try{
            if(IPS_InstanceExists($selected)&&strtoupper(IPS_GetInstance($selected)['ModuleInfo']['ModuleID']??'')==='{7C5A3841-3F7B-4D2A-9E1C-5B6D8F9A0E12}'){
                $info=json_decode(SEC_GetReplacementInfo($selected),true,32,JSON_THROW_ON_ERROR);
                if(($info['engine']??'')!=='native-only'||($info['ready']??false)!==true)return 0;$selected=(int)($info['backend']??0);if(!$selected)return 0;
            }
        }catch(Throwable $e){return 0;}
        $ids=$selected>0?[$selected]:IPS_GetInstanceListByModuleID(self::BACKEND);$eligible=[];
        foreach($ids as $id)try{
            $id=(int)$id;if(!IPS_InstanceExists($id)||IPS_HasChanges($id)||strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')!==self::BACKEND||IPS_GetProperty($id,'Enabled')!==true)continue;
            $f=IPS_GetProperty($id,'FrontendInstanceID');
            if(!is_int($f)||!IPS_InstanceExists($f)||IPS_HasChanges($f)||strtoupper(IPS_GetInstance($f)['ModuleInfo']['ModuleID']??'')!==self::FRONTEND||IPS_GetProperty($f,'Enabled')!==true||IPS_GetProperty($f,'BackendInstanceID')!==$id||IPS_GetProperty($f,'AdminOrigin')!==IPS_GetProperty($id,'AdminOrigin'))continue;
            $eligible[]=$id;
        }catch(Throwable $e){}
        return count($eligible)===1?$eligible[0]:0;
    }
    private function HookList(int $id): array
    {
        $rows=json_decode((string)IPS_GetProperty($id,'Hooks'),true,32,JSON_THROW_ON_ERROR);
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>2048)throw new RuntimeException('Invalid WebhookControl registry.');return $rows;
    }
    private function SyncPrivateHooks(): void
    {
        $this->AppliedProperties();
        if(!IPS_SemaphoreEnter('ALPORT.WebhookControl',1000))throw new RuntimeException('Webhook registry is busy; retry scheduled.');
        try{
            $ids=IPS_GetInstanceListByModuleID(self::HOOKS);if(count($ids)!==1)throw new RuntimeException('Exactly one WebhookControl is required.');$id=(int)$ids[0];
            if(IPS_HasChanges($id))throw new RuntimeException('Apply or discard pending WebhookControl edits before registration.');
            $paths=[$this->WeatherPath(),$this->AdminPath()];if($paths[0]===$paths[1])throw new RuntimeException('Weather and administration paths must differ.');
            $old=$this->HookList($id);$next=[];
            foreach($old as $row){
                if(in_array($row['Hook']??'', $paths,true)&&($row['TargetID']??0)!==$this->InstanceID)throw new RuntimeException('A requested webhook belongs to another instance. Choose a different weather path.');
                if(($row['TargetID']??0)===$this->InstanceID)continue;$next[]=$row;
            }
            if($this->ReadPropertyBoolean('WebEnabled'))foreach($paths as $path)$next[]=['Hook'=>$path,'TargetID'=>$this->InstanceID];
            if($next!==$old){IPS_SetProperty($id,'Hooks',json_encode($next,JSON_THROW_ON_ERROR));IPS_ApplyChanges($id);}
        }finally{IPS_SemaphoreLeave('ALPORT.WebhookControl');}
    }
    private function IntegrationInstance(string $property,string $module): int
    {
        $selected=$this->ReadPropertyInteger($property);$ids=$selected>0?[$selected]:IPS_GetInstanceListByModuleID($module);
        if(count($ids)!==1)throw new RuntimeException('Select exactly one '.$property.' in the Tempest instance.');$id=(int)$ids[0];
        if(!IPS_InstanceExists($id)||strtoupper(IPS_GetInstance($id)['ModuleInfo']['ModuleID']??'')!==$module)throw new RuntimeException('Selected integration instance has the wrong module type.');return $id;
    }
    public function AddToPortal(): string
    {
        $portal=$this->IntegrationInstance('PortalInstanceID',self::PORTAL);
        if(!IPS_SemaphoreEnter('ALPORT.AdditionalPages.'.$portal,1000))throw new RuntimeException('Portal registration is busy.');
        try{
            if(IPS_HasChanges($portal))throw new RuntimeException('Apply or discard pending Portal edits first.');
            $old=json_decode(IPS_GetProperty($portal,'AdditionalPages'),true,32,JSON_THROW_ON_ERROR);if(!is_array($old)||!array_is_list($old))throw new RuntimeException('Invalid Portal additional-pages list.');
            $next=array_values(array_filter($old,fn($r)=>($r['TargetID']??0)!==$this->InstanceID));
            if($this->ReadPropertyBoolean('WebEnabled')){
                foreach([[$this->WeatherPath(),'Weather — ','Current weather and recorded sensor charts.','smart'],[$this->AdminPath(),'Tempest administration — ','Weather settings, diagnostics, configuration import and export.','admin']] as [$path,$name,$description,$cluster]){
                    foreach($next as $row)if(($row['Hook']??'')===$path)throw new RuntimeException('Conflicting Portal page ownership.');
                    $title=$name.$this->ReadPropertyString('StationName');while(strlen($title)>160)$title=preg_replace('/.$/us','',$title);
                    $next[]=['Name'=>$title,'Description'=>$description,'Cluster'=>$cluster,'Hook'=>$path,'TargetID'=>$this->InstanceID];
                }
            }
            if(count($next)>100)throw new RuntimeException('Portal page list is full.');
            if($next!==$old){IPS_SetProperty($portal,'AdditionalPages',json_encode($next,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));IPS_ApplyChanges($portal);}
            return 'Weather and administration links registered in Portal.';
        }finally{IPS_SemaphoreLeave('ALPORT.AdditionalPages.'.$portal);}
    }
    public function RunPostApply(): void
    {
        if(IPS_GetKernelRunlevel()!==KR_READY)return;
        try{
            $this->SyncPrivateHooks();$status=$this->ReadPropertyBoolean('WebEnabled')?($this->NativeBackend()?'Protected weather and administration pages ready.':'Select an enabled native Secrets backend/frontend pair. Native authentication APIs are required.'):'Web access disabled.';
            try{$status.=' '.$this->AddToPortal();}catch(Throwable $e){$status.=' Portal: '.$e->getMessage();}
            $this->SetValue('WebLinks',json_encode(['weather'=>$this->WeatherPath(),'admin'=>$this->AdminPath()],JSON_UNESCAPED_SLASHES));
            $this->SetValue('WebStatus',$status);$this->SetTimerInterval('PostApply',0);try{$this->UpdateDashboard();}catch(Throwable $e){$this->DebugEvent('htmlbox',get_class($e),0);}$this->DebugEvent('web-setup',$status,0);
        }catch(Throwable $e){$this->SetValue('WebStatus',$e->getMessage());$this->SetTimerInterval('PostApply',5000);$this->DebugEvent('web-setup',$e->getMessage(),0);}
    }
    private function Navigation(): array
    {
        $links=['weather'=>$this->WeatherPath(),'admin'=>$this->AdminPath()];
        foreach(['portal'=>['PortalInstanceID',self::PORTAL,'alarm_portal_'],'documentation'=>['DocumentationInstanceID','{FBEB2EF6-8D23-49D7-A256-6D0C8D973B24}','alarm_documentation_']] as $key=>[$property,$module,$prefix])try{$id=$this->IntegrationInstance($property,$module);if(IPS_GetProperty($id,'Enabled')===true)$links[$key]='/hook/'.$prefix.$id.($key==='documentation'?'#tempest-afprivat':'');}catch(Throwable $e){}
        return $links;
    }
    private function CurrentWeather(string $revision): array
    {
        $p=$this->AppliedProperties();$list=json_decode($p['HTMLVariableList'],true);$values=[];
        foreach($list as $row){if(!$row['Show'])continue;$entry=['ident'=>$row['Ident'],'label'=>$row['Label'],'row'=>$row['Row'],'col'=>$row['Col'],'chart'=>$row['ShowChart'],'value'=>null,'formatted'=>'Unavailable','updated'=>0,'status'=>'missing'];
            try{$id=(int)@$this->GetIDForIdent($row['Ident']);if($id>0&&IPS_VariableExists($id)&&(IPS_GetObject($id)['ParentID']??0)===$this->InstanceID){$v=IPS_GetVariable($id);$value=GetValue($id);if(!is_float($value)||is_finite($value)){$entry['value']=$value;$entry['formatted']=GetValueFormatted($id);$entry['updated']=(int)($v['VariableUpdated']??0);$entry['status']=time()-$entry['updated']>600?'stale':'ok';}}}catch(Throwable $e){}$values[]=$entry;
        }
        usort($values,static fn($a,$b)=>[$a['row'],$a['col']]<=>[$b['row'],$b['col']]);$hash=hash('sha256',self::Canonical([$values,$p]));
        return ['revision'=>$hash,'changed'=>$hash!==$revision,'station'=>$p['StationName'],'enabled'=>$p['EnableHTML'],'values'=>$hash!==$revision?$values:null,'poll'=>max(2,$p['HTMLUpdateInterval']?:10),'navigation'=>$this->Navigation(),'presentation'=>array_intersect_key($p,array_flip(['HTMLBackgroundColor','HTMLFontColor','ChartColor','HTMLChartHeight','HTMLFontSizeValue','HTMLTimezone','ChartTimeframe'])),'now'=>time()];
    }
    private function AdminState(array $server,int $backend): array
    {
        $p=$this->AppliedProperties();$public=$p;foreach(['WebhookUser','WebhookPassword','AuthMode'] as $key)unset($public[$key]);
        $rows=json_decode($p['HTMLVariableList'],true);$seen=array_column($rows,'Ident');foreach($this->GetMasterMetadata() as $ident=>$label)if(!in_array($ident,$seen,true))$rows[]=['Ident'=>$ident,'Label'=>$label,'Show'=>false,'ShowChart'=>false,'Row'=>1,'Col'=>1];
        return ['properties'=>$public,'rows'=>$rows,'revision'=>hash('sha256',self::Canonical($p)),'csrf'=>$this->CSRF($server,$backend,(int)floor(time()/900)),'writable'=>$p['AllowWebAdministration'],'debug'=>$p['DebugEnabled']?$this->GetValue('Debug'):'Debug logging is off.','status'=>$this->GetValue('WebStatus'),'navigation'=>$this->Navigation(),'backup'=>'Module-owned configuration export and importer-format validation are available to Configuration Backup.','backend'=>$backend];
    }
    private function CSRF(array $server,int $backend,int $slot): string
    {
        preg_match_all('/(?:^|;\s*)__Host-SECB_'.preg_quote((string)$backend,'/').'=([A-Za-z0-9_-]{43})(?=;|$)/',(string)($server['HTTP_COOKIE']??''),$matches);
        if(count($matches[1])!==1)throw new RuntimeException('Native session cookie unavailable.');
        return hash_hmac('sha256',$this->InstanceID.'|'.$backend.'|'.$matches[1][0].'|'.$slot,$this->ReadAttributeString('CSRFKey'));
    }
    private static function Origin(string $url): string
    {
        $p=parse_url($url);if(!is_array($p)||($p['scheme']??'')!=='https'||empty($p['host'])||isset($p['user'])||isset($p['pass'])||isset($p['query'])||isset($p['fragment'])||!in_array($p['path']??'',['','/'],true))return '';
        return 'https://'.strtolower($p['host']).((int)($p['port']??443)===443?'':':'.$p['port']);
    }
    private function ChartHistory(array $query): array
    {
        $ident=$query['ident']??null;if(!is_string($ident))throw new InvalidArgumentException('Choose a configured chart.');$p=$this->AppliedProperties();$rows=json_decode($p['HTMLVariableList'],true);$match=array_values(array_filter($rows,static fn($r)=>$r['Ident']===$ident&&$r['Show']&&$r['ShowChart']));if(count($match)!==1)throw new InvalidArgumentException('Chart is not enabled for this variable.');
        $archive=$p['ArchiveID'];$id=(int)@$this->GetIDForIdent($ident);
        if(!$archive||!IPS_InstanceExists($archive)||strtoupper(IPS_GetInstance($archive)['ModuleInfo']['ModuleID']??'')!=='{43192F0B-135B-4CE7-A0A7-1475603F3060}'||!$id||!IPS_VariableExists($id)||(IPS_GetObject($id)['ParentID']??0)!==$this->InstanceID||!in_array(IPS_GetVariable($id)['VariableType'],[1,2],true)||!AC_GetLoggingStatus($archive,$id)||AC_GetAggregationType($archive,$id)!==0)throw new InvalidArgumentException('Enable standard logging for this selected weather variable in Archive Control.');
        $key=hash('sha256',self::Canonical([$archive,$id,$p['ChartTimeframe']]));$cache=json_decode($this->GetBuffer('Chart.'.$id),true)??[];if(($cache['key']??'')===$key&&time()-($cache['time']??0)<300)return $cache['data'];
        $end=time();$from=$end-$p['ChartTimeframe']*3600;$rows=AC_GetAggregatedValues($archive,$id,0,$from,$end,200);if(!is_array($rows))throw new RuntimeException('Archive read failed.');$points=[];$seen=[];
        foreach(array_slice($rows,0,200) as $row){$t=$row['TimeStamp']??null;$v=$row['Avg']??null;if(!is_int($t)||$t<$from||$t>$end||isset($seen[$t])||!is_numeric($v)||!is_finite((float)$v))continue;$seen[$t]=true;$points[]=[$t,(float)$v];}
        usort($points,static fn($a,$b)=>$a[0]<=>$b[0]);$data=['ident'=>$ident,'from'=>$from,'to'=>$end,'points'=>$points,'resolution'=>'hourly'];$this->SetBuffer('Chart.'.$id,json_encode(['key'=>$key,'time'=>time(),'data'=>$data],JSON_THROW_ON_ERROR));return $data;
    }
    private function PrivateResponse(array $server,array $query,string $body): array
    {
        $headers=['Content-Type'=>'application/json; charset=utf-8','Cache-Control'=>'private, no-store, max-age=0','X-Content-Type-Options'=>'nosniff','Referrer-Policy'=>'no-referrer'];
        $reply=static fn(int $status,$value)=>['status'=>$status,'headers'=>$headers,'body'=>json_encode($value,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
        if(!$this->ReadPropertyBoolean('WebEnabled'))return $reply(404,['error'=>'Tempest web access is disabled.']);
        if(IPS_GetKernelRunlevel()!==KR_READY)return $reply(503,['error'=>'Symcon is starting; retry shortly.']);
        $method=$server['REQUEST_METHOD']??'GET';if(!in_array($method,['GET','POST'],true))return $reply(405,['error'=>'Use GET or JSON POST.']);
        $path=parse_url((string)($server['REQUEST_URI']??$this->WeatherPath()),PHP_URL_PATH);$admin=$path===$this->AdminPath();if(!$admin&&$path!==$this->WeatherPath())return $reply(404,['error'=>'Unknown Tempest page.']);
        try{
            $backend=$this->NativeBackend();if(!$backend)throw new RuntimeException('Native Secrets backend or its application APIs are unavailable.');
            $context=array_intersect_key($server,array_flip(['HTTP_HOST','HTTP_COOKIE','REMOTE_ADDR','HTTPS','REQUEST_SCHEME','HTTP_X_FORWARDED_HOST','HTTP_X_FORWARDED_PROTO','HTTP_FORWARDED']));
            $login=SECB_GetNativePortalLoginURL($backend,$admin?$this->AdminPath():$this->WeatherPath(),json_encode($context,JSON_THROW_ON_ERROR));
            if(SECB_IsNativePortalAuthenticated($backend)!==true){$r=$reply(401,['error'=>'Sign in with your native Secrets passkey.','login'=>$login]);if($method==='GET'&&!isset($query['action'])&&!isset($query['ajax'])){$r['status']=303;$r['headers']['Location']=$login;}return $r;}
            $action=$query['action']??(isset($query['ajax'])?'data':(isset($query['manifest'])?'manifest':'page'));
            if(!is_string($action))return $reply(400,['error'=>'Invalid action.']);
            if($method==='POST'){
                if(!$admin||!$this->ReadPropertyBoolean('AllowWebAdministration'))return $reply(403,['error'=>'Web settings changes are disabled. Enable them explicitly in the Symcon instance console.']);
                $url=parse_url($login);$origin=self::Origin('https://'.($url['host']??'').(isset($url['port'])?':'.$url['port']:''));
                if(!$origin||self::Origin((string)($server['HTTP_ORIGIN']??''))!==$origin||($server['HTTP_SEC_FETCH_SITE']??'same-origin')!=='same-origin')return $reply(403,['error'=>'Same-origin administration required.']);
                if(IPS_HasChanges($this->InstanceID))return $reply(409,['error'=>'Apply or discard pending Tempest console edits before changing settings.']);
                $type=$server['CONTENT_TYPE']??$server['HTTP_CONTENT_TYPE']??'';if(!preg_match('~^application/json(?:\s*;|$)~i',(string)$type)||strlen($body)>262144)return $reply(400,['error'=>'Use a bounded JSON request.']);
                $v=json_decode($body,true,64,JSON_THROW_ON_ERROR);if(!is_array($v)||array_is_list($v)||!is_string($v['csrf']??null))return $reply(403,['error'=>'Reload the administration page to verify your session.']);
                $slot=(int)floor(time()/900);if(!hash_equals($this->CSRF($server,$backend,$slot),$v['csrf'])&&!hash_equals($this->CSRF($server,$backend,$slot-1),$v['csrf']))return $reply(403,['error'=>'Session verification expired. Reload before trying again.']);
                if(!IPS_SemaphoreEnter('TMT.Configuration.'.$this->InstanceID,1000))return $reply(409,['error'=>'Another administration change is in progress. Refresh before retrying.']);
                try{
                if(IPS_HasChanges($this->InstanceID))return $reply(409,['error'=>'Apply or discard pending Tempest console edits first.']);
                if($action==='settings'&&is_array($v['settings']??null)&&is_string($v['revision']??null))return $reply(200,$this->SaveWebSettings($v['settings'],$v['revision']));
                if($action==='portal')return $reply(200,['message'=>$this->AddToPortal()]);
                if($action==='logging'){ob_start();try{$this->EnableLogging();$message=trim(ob_get_contents());}finally{ob_end_clean();}return $reply(200,['message'=>$message]);}
                if($action==='import'&&is_array($v['configuration']??null)){
                    $c=$v['configuration'];$current=$this->AppliedProperties();if(!isset($c['properties'])||!is_array($c['properties']))throw new InvalidArgumentException('Invalid configuration.');
                    foreach(['SecretsInstanceID','PortalInstanceID','DocumentationInstanceID','WebhookPath'] as $key)$c['properties'][$key]=$current[$key];
                    return $reply(200,['message'=>$this->ImportConfig(json_encode($c,JSON_THROW_ON_ERROR))]);
                }return $reply(400,['error'=>'Unknown administration action.']);
                }finally{IPS_SemaphoreLeave('TMT.Configuration.'.$this->InstanceID);}
            }
            if($action==='data')return $reply(200,$this->CurrentWeather(is_string($query['revision']??null)?$query['revision']:''));
            if($action==='history')return $reply(200,$this->ChartHistory($query));
            if($admin&&$action==='admin-state')return $reply(200,$this->AdminState($server,$backend));
            if($admin&&$action==='export'){$r=$reply(200,$this->PortableConfiguration());$r['headers']['Content-Disposition']='attachment; filename="Tempest-'.$this->InstanceID.'.json"';return $r;}
            if($action==='manifest')return $reply(200,['name'=>$this->ReadPropertyString('StationName'),'short_name'=>'Tempest','start_url'=>$this->WeatherPath(),'scope'=>$this->WeatherPath(),'display'=>'standalone']);
            if($action!=='page')return $reply(404,['error'=>'Unknown page action.']);
            $nonce=base64_encode(random_bytes(18));$headers['Content-Type']='text/html; charset=utf-8';$headers['Content-Security-Policy']="default-src 'none'; script-src 'nonce-".$nonce."'; style-src 'nonce-".$nonce."'; connect-src 'self'; img-src 'self' data:; manifest-src 'self'; base-uri 'none'; frame-ancestors 'none'; form-action 'self'";
            $html=file_get_contents(__DIR__.($admin?'/admin.html':'/weather.html'));$html=str_replace('{{NONCE}}',$nonce,$html);return ['status'=>200,'headers'=>$headers,'body'=>$html];
        }catch(InvalidArgumentException|JsonException $e){return $reply(400,['error'=>$e->getMessage()]);}
        catch(Throwable $e){$this->DebugEvent('web-error',get_class($e),0);return $reply(503,['error'=>'Tempest request failed. Check the instance WebStatus and debug log.']);}
    }
}
