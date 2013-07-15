<?php
class DB
{
  private static $getInitial;

  public static function getInitial() {
    if (self::$getInitial == null)
      self::$getInitial = new DB();
    return self::$getInitial;
  }
  private static $db_path = 'db/work.db';

  public static function workjobExist($name){
    exec("sqlite3 " + DB_PATH + " \"select name from job where condition='WORKING' and name='".$name."' limit 1;\"", $output);
    return count($output) != 0;
  }

  public static function getAllJobs(){
    $path = self::$db_path;
    $q = "sqlite3 $path \"select * from job\"";
    exec($q, $output);
    print $q;
    return $output;
  }
}
?>
