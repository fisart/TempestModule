<?php
declare(strict_types=1);

/** Local sources only; request parameters never select object IDs or URLs. */
trait TempestWeatherSources
{
    private function WeatherView(array $query, array $p): string
    {
        $view=$query['view']??$p['DefaultWeatherView'];
        if(!is_string($view)||!in_array($view,['berlin','hermitage','forecast'],true))throw new InvalidArgumentException('Choose Berlin, Hermitage or Berlin forecast.');
        return $view;
    }

    private function WeatherBindings(string $view, array $p): array
    {
        $bindings=[];
        if($view==='berlin'){
            foreach($this->GetMasterMetadata() as $ident=>$label){
                $id=(int)@$this->GetIDForIdent($ident);
                if($id>0&&IPS_VariableExists($id)&&(IPS_GetObject($id)['ParentID']??0)===$this->InstanceID)$bindings[$ident]=$id;
            }
            return $bindings;
        }
        if($view!=='hermitage')return [];
        $root=$p['HermitageRootID'];
        if(!$root||!IPS_ObjectExists($root)||(IPS_GetObject($root)['ObjectType']??-1)!==0)return [];
        $names=[];$children=IPS_GetChildrenIDs($root);
        if(count($children)>300)return [];
        foreach($children as $id){
            if(!IPS_VariableExists($id))continue;$object=IPS_GetObject($id);
            if(($object['ParentID']??0)!==$root||!in_array(IPS_GetVariable($id)['VariableType'],[1,2],true))continue;
            $names[$object['ObjectName']??''][]=(int)$id;
        }
        foreach($this->GetMasterMetadata() as $ident=>$label){
            // The existing RemoteSync copy calls rapid wind "Wind Speed".
            $matches=$names[$label]??($ident==='Wind_Speed'?($names['Wind Speed']??[]):[]);
            if(count($matches)===1)$bindings[$ident]=$matches[0];
        }
        return $bindings;
    }

    private function ValidateWeatherSourceSettings(array $p): void
    {
        $root=$p['HermitageRootID'];$image=$p['ForecastVariableID'];
        if($root&&(!IPS_ObjectExists($root)||(IPS_GetObject($root)['ObjectType']??-1)!==0))throw new InvalidArgumentException('Select the local Hermitage mirror category.');
        if($image&&(!IPS_VariableExists($image)||IPS_GetVariable($image)['VariableType']!==3))throw new InvalidArgumentException('Select the string variable containing the downloaded forecast image.');
    }

    private function ForecastImage(array $p): array
    {
        $id=$p['ForecastVariableID'];
        if(!$id||!IPS_VariableExists($id)||IPS_GetVariable($id)['VariableType']!==3)throw new InvalidArgumentException('Select the downloaded Berlin forecast image in Administration.');
        $value=GetValue($id);
        if(!is_string($value)||strlen($value)>4194304)throw new InvalidArgumentException('The forecast image is unavailable or too large.');
        // Extract an embedded image, never render the source HTML or fetch an external URL.
        if(!preg_match('~(?:^|[\s\x22\x27])data:image/(svg\+xml|png|jpeg|gif);base64,([A-Za-z0-9+/=\r\n]+)~D',$value,$match))throw new InvalidArgumentException('The forecast variable must contain an embedded SVG, PNG, JPEG or GIF image.');
        $image=base64_decode($match[2],true);
        if($image===false||$image===''||strlen($image)>2097152)throw new InvalidArgumentException('Invalid forecast image data.');
        $mime='image/'.$match[1];
        if($match[1]==='svg+xml'){
            // Matplotlib's standard SVG DOCTYPE is harmless; entities, active content and external resources are not allowed.
            if(!preg_match('~<svg\b~i',$image)||preg_match('~<!ENTITY|<\s*(?:script|foreignObject|iframe|object|embed|animate\w*|set|audio|video)\b|\bon[a-z]+\s*=|(?:href|src)\s*=\s*[\x22\x27](?!#|data:image/(?:png|jpeg|gif);base64,)|url\(\s*[\x22\x27]?(?!#)|@import~i',$image))throw new InvalidArgumentException('The forecast SVG contains unsupported active or external content.');
        }else{
            $valid=match($match[1]){'png'=>str_starts_with($image,"\x89PNG\r\n\x1a\n"),'jpeg'=>str_starts_with($image,"\xff\xd8\xff"),'gif'=>str_starts_with($image,'GIF87a')||str_starts_with($image,'GIF89a')};
            if(!$valid)throw new InvalidArgumentException('The forecast image type does not match its contents.');
        }
        return ['mime'=>$mime,'body'=>$image,'revision'=>hash('sha256',$image),'updated'=>(int)(IPS_GetVariable($id)['VariableUpdated']??0)];
    }

    private function ForecastState(array $p): array
    {
        try{
            $image=$this->ForecastImage($p);
            return ['available'=>true,'revision'=>$image['revision'],'updated'=>$image['updated'],'stale'=>time()-$image['updated']>86400,'title'=>'Berlin-Dahlem forecast','credit'=>'DWD / profiwetter.ch'];
        }catch(InvalidArgumentException $e){return ['available'=>false,'error'=>$e->getMessage(),'updated'=>0,'stale'=>false];}
    }
}
