<?php
/** Drill lama berjalan pada jendela migrasinya, lalu mengembalikan 018/019.
 * Hanya database uji, hanya rollback yang telah terbukti tidak menghapus data.
 */
function v3MigrationWindow(int $max):string
{
    if(app_config('database.database')!=='webalhasan_v3_phase1_test')throw new RuntimeException('Test database only');
    $r=new App\V3\KonselingRepository(app_db());$full=new App\Database\Migrator(app_db(),APP_ROOT.'/database/migrations',APP_ROOT.'/database/rollbacks');
    $later=$r->all('SELECT migration FROM schema_migrations ORDER BY id DESC');
    foreach($later as $row){if((int)substr($row['migration'],0,3)<=$max)break;if(!in_array($row['migration'],['018_v3_fase4_publikasi.sql','019_v3_fase5_laporan_retensi.sql'],true))throw new RuntimeException('Unknown later migration');}
    $dir=sys_get_temp_dir().'/v3-drill-'.bin2hex(random_bytes(8));mkdir($dir,0700);
    foreach(glob(APP_ROOT.'/database/migrations/*.sql') as $file)if((int)substr(basename($file),0,3)<=$max)symlink($file,$dir.'/'.basename($file));
    register_shutdown_function(static function()use($full,$dir){$full->up();foreach(glob($dir.'/*.sql') as $file)unlink($file);rmdir($dir);});
    foreach($later as $row){if((int)substr($row['migration'],0,3)<=$max)break;$full->rollbackLast();}
    return $dir;
}
