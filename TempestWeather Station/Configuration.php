<?php
declare(strict_types=1);
trait TempestConfiguration
{
    private const PRIVATE_MODULE='{FC25E9B8-9281-8A17-15BF-A1CD0C7CCB70}';
    private static function Canonical($value): string
    {
        $sort=function($v)use(&$sort){if(!is_array($v))return $v;if(!array_is_list($v))ksort($v,SORT_STRING);foreach($v as &$item)$item=$sort($item);return $v;};
        return json_encode($sort($value),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }
    private function ValidProperties(array $input): array
    {
        $defaults=json_decode(file_get_contents(__DIR__.'/defaults.json'),true,32,JSON_THROW_ON_ERROR);
        if(array_diff(array_keys($input),array_keys($defaults)))throw new InvalidArgumentException('Unknown configuration field.');
        $p=array_replace($defaults,$input);
        foreach($defaults as $key=>$default){
            if(is_float($default)&&(is_int($p[$key])||is_float($p[$key])))$p[$key]=(float)$p[$key];
            if(gettype($p[$key])!==gettype($default))throw new InvalidArgumentException('Invalid type: '.$key);
            if(is_string($p[$key])&&strlen($p[$key])>131072)throw new InvalidArgumentException('Configuration text too large.');
        }
        foreach(['SecretsInstanceID','PortalInstanceID','DocumentationInstanceID','ArchiveID'] as $key)if($p[$key]<0)throw new InvalidArgumentException('Invalid instance ID.');
        foreach(['HTMLBackgroundColor','HTMLFontColor','ChartColor'] as $key)if($p[$key]<0||$p[$key]>16777215)throw new InvalidArgumentException('Invalid colour.');
        foreach(['StationName'=>160,'ProfilePrefix'=>80] as $key=>$max)if(trim($p[$key])===''||strlen($p[$key])>$max||preg_match('/[\x00-\x1f\x7f]/',$p[$key]))throw new InvalidArgumentException('Invalid '.$key.'.');
        if(!preg_match('~^/hook/[A-Za-z0-9_-]{1,100}$~D',$p['WebhookPath']))throw new InvalidArgumentException('Use a plain local /hook/name path.');
        if(!in_array($p['HTMLTimezone'],timezone_identifiers_list(),true)&&$p['HTMLTimezone']!=='UTC')throw new InvalidArgumentException('Unknown timezone.');
        foreach(['RegressionDataPoints'=>[5,500],'HTMLUpdateInterval'=>[0,3600],'HTMLChartHeight'=>[20,500],'ChartTimeframe'=>[1,168],'HTMLFontSizeValue'=>[1,20],'AuthMode'=>[0,3]] as $key=>[$min,$max])if($p[$key]<$min||$p[$key]>$max)throw new InvalidArgumentException('Out of range: '.$key);
        if(!is_numeric($p['TriggerValueSlope'])||!is_finite((float)$p['TriggerValueSlope'])||abs((float)$p['TriggerValueSlope'])>1)throw new InvalidArgumentException('Invalid regression slope.');
        $rows=json_decode($p['HTMLVariableList'],true,16,JSON_THROW_ON_ERROR);$known=$this->GetMasterMetadata();$seen=[];
        if(!is_array($rows)||!array_is_list($rows)||count($rows)>150)throw new InvalidArgumentException('Use a dashboard list with at most 150 rows.');
        foreach($rows as &$row){
            if(!is_array($row)||array_diff(array_keys($row),['Ident','Label','Show','ShowChart','Row','Col'])||!isset($known[$row['Ident']??''])||isset($seen[$row['Ident']]))throw new InvalidArgumentException('Invalid or duplicate dashboard ident.');
            $seen[$row['Ident']]=true;$row['Label']=$known[$row['Ident']];$row['ShowChart']=$row['ShowChart']??false;
            if(!is_bool($row['Show']??null)||!is_bool($row['ShowChart'])||!is_int($row['Row']??null)||!is_int($row['Col']??null)||$row['Row']<1||$row['Row']>150||$row['Col']<1||$row['Col']>4)throw new InvalidArgumentException('Invalid dashboard row settings.');
        }unset($row);
        $p['HTMLVariableList']=json_encode($rows,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        // Old auth settings remain schema-compatible, but are never used or exported as credentials.
        $p['AuthMode']=3;$p['WebhookUser']='';$p['WebhookPassword']='';return $p;
    }
    private function AppliedProperties(): array {return $this->ValidProperties(json_decode(IPS_GetConfiguration($this->InstanceID),true,64,JSON_THROW_ON_ERROR));}
    private function PortableConfiguration(): array {return ['schema'=>'Tempest.afprivat.v1','moduleID'=>self::PRIVATE_MODULE,'properties'=>$this->AppliedProperties()];}
    public function ExportConfig(): string {return json_encode($this->PortableConfiguration(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);}
    public function ImportConfig(string $JSON): string
    {
        if(strlen($JSON)>262144)throw new InvalidArgumentException('Configuration too large.');$v=json_decode($JSON,true,64,JSON_THROW_ON_ERROR);
        if(($v['schema']??'')!=='Tempest.afprivat.v1'||($v['moduleID']??'')!==self::PRIVATE_MODULE||!is_array($v['properties']??null)||array_diff(array_keys($v),['schema','moduleID','properties']))throw new InvalidArgumentException('Unsupported Tempest configuration.');
        if(IPS_HasChanges($this->InstanceID))throw new InvalidArgumentException('Apply or discard pending Tempest edits before import.');
        $p=$this->ValidProperties($v['properties']);$p['WebEnabled']=false;$p['AllowWebAdministration']=false;
        $this->WriteAttributeString('HTMLVariableListBuffer',$p['HTMLVariableList']);$this->SetBuffer('PrivateState','');
        IPS_SetConfiguration($this->InstanceID,json_encode($p,JSON_THROW_ON_ERROR));IPS_ApplyChanges($this->InstanceID);
        return 'Imported. Review the native Secrets backend and object IDs in the console, then enable web access.';
    }
    public function ExportToForm(): void {$this->UpdateFormField('BackupJSON','value',$this->ExportConfig());}
    public function ExportConfigurationBackup(): string
    {
        $properties=json_decode(IPS_GetConfiguration($this->InstanceID),true,64,JSON_THROW_ON_ERROR);
        return json_encode(['schema'=>'Symcon.ConfigurationBackup.v1','moduleID'=>self::PRIVATE_MODULE,'format'=>'Tempest.afprivat.v1','payload'=>$this->PortableConfiguration(),'sourceHash'=>hash('sha256',self::Canonical(['properties'=>$properties,'attributes'=>[]])),'attributeNames'=>[],'restoreMode'=>'manual-native-import','exclusions'=>['Legacy web credentials','Native sessions','CSRF key','Pending form edits','Weather readings and archives','Debug history'],'notes'=>['Use TMT_ImportConfig on afprivat. Import disables web access and web administration.']],JSON_THROW_ON_ERROR);
    }
    public function ValidateConfigurationBackup(string $JSON): string
    {
        if(strlen($JSON)>262144)throw new InvalidArgumentException('Configuration too large.');$v=json_decode($JSON,true,64,JSON_THROW_ON_ERROR);$issues=[];
        try{if(($v['schema']??'')!=='Tempest.afprivat.v1'||($v['moduleID']??'')!==self::PRIVATE_MODULE||array_diff(array_keys($v),['schema','moduleID','properties']))throw new InvalidArgumentException('Unsupported configuration schema.');if(!is_array($v['properties']??null))throw new InvalidArgumentException('Missing properties.');$this->ValidProperties($v['properties']);}catch(Throwable $e){$issues[]=$e->getMessage();}
        return json_encode(['schema'=>'Symcon.ConfigurationBackupValidation.v1','valid'=>!$issues,'scope'=>'format','payloadHash'=>hash('sha256',self::Canonical($v)),'issues'=>$issues,'checks'=>['Native Tempest importer schema and property checks'],'notes'=>['No restore was executed.']],JSON_THROW_ON_ERROR);
    }
    private function SaveWebSettings(array $settings,string $revision): array
    {
        if(IPS_HasChanges($this->InstanceID))throw new InvalidArgumentException('Apply or discard pending console edits first.');
        $current=$this->AppliedProperties();if(!hash_equals(hash('sha256',self::Canonical($current)),$revision))throw new InvalidArgumentException('Settings changed. Reload the administration page before saving.');
        $blocked=['SecretsInstanceID','PortalInstanceID','DocumentationInstanceID','WebhookPath','WebEnabled','AllowWebAdministration','AuthMode','WebhookUser','WebhookPassword','ProfilePrefix'];
        if(array_intersect(array_keys($settings),$blocked)||array_diff(array_keys($settings),array_keys($current)))throw new InvalidArgumentException('These settings are console-only.');
        $next=$this->ValidProperties(array_replace($current,$settings));$archive=$next['ArchiveID'];
        if($archive>0&&(!IPS_InstanceExists($archive)||strtoupper(IPS_GetInstance($archive)['ModuleInfo']['ModuleID']??'')!=='{43192F0B-135B-4CE7-A0A7-1475603F3060}'))throw new InvalidArgumentException('Select an installed Archive Control.');
        $this->WriteAttributeString('HTMLVariableListBuffer',$next['HTMLVariableList']);IPS_SetConfiguration($this->InstanceID,json_encode($next,JSON_THROW_ON_ERROR));IPS_ApplyChanges($this->InstanceID);$this->DebugEvent('settings','Weather settings updated from protected administration.',0);return ['message'=>'Settings saved.'];
    }
}
