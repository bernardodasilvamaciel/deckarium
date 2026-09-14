<?php
declare(strict_types=1);
function downloadState(string $storage,array $progress): string {
    $state=(string)($progress['state']??'');
    $lock=@fopen($storage.'/download-normal.lock','c');
    $active=false;
    if($lock){$free=@flock($lock,LOCK_EX|LOCK_NB);$active=!$free;if($free)flock($lock,LOCK_UN);fclose($lock);}
    if($active)return is_file($storage.'/STOP_DOWNLOAD')?'stopping':'running';
    if(in_array($state,['starting','running','stopping'],true)) {
        if($state==='starting'&&time()-(int)($progress['updated_at']??0)<15)return 'starting';
        return is_file($storage.'/STOP_DOWNLOAD')?'stopped_by_user':'interrupted';
    }
    return $state;
}
