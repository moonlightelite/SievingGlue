<!--
Copyright (c) 2013, Tim Lau
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the documentation and/or other materials provided with the distribution.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
//-->
<?php
   define("WORK_DIRECTORY", "work/");
   define("TMP_DIRECTORY", "tmp/");
   //150% with 154digits
   //10^(digits/41+4)
   define("HARDCODED_MERGE_SIZE", FALSE);
   define("BYTES_PER_RELATION", "143.86");
   define("MERGE_SIZE", "8200000000");
   define("TOTAL_CLIENT", "500");

   function rand_string( $length ) {
	$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";	
        $str = "";

	$size = strlen( $chars );
	for( $i = 0; $i < $length; $i++ ) {
		$str .= $chars[ rand( 0, $size - 1 ) ];
	}

	return $str;
   }

   function delTree($dir) {
     $files = glob( $dir . '*', GLOB_MARK );
     foreach( $files as $file ){
       if( substr( $file, -1 ) == '/' )
         delTree( $file );
       else
         unlink( $file );
     }
     if (is_dir($dir)) rmdir( $dir );
   } 

   function getDirSize($path)
   {
     $io = popen('/usr/bin/du -sb '.$path, 'r');
     $size = intval(fgets($io,80));
     pclose($io);
     return $size;
   }

   function bchexdec($hex)
   {
     $len = strlen($hex);
     for ($i = 1; $i <= $len; $i++){
       $dec = bcadd($dec, bcmul(strval(hexdec($hex[$i - 1])), bcpow('16', strval($len - $i))));
     }
     return $dec;
   }

   function downloadFile($file){
     header('Content-Description: File Transfer');
     header('Content-Type: application/octet');
     header('Content-Disposition: attachment; filename='.basename($file));
     header('Content-Transfer-Encoding: binary');
     header('Expires: 0');
     header('Cache-Control: must-revalidate');
     header('Pragma: public');
     header('Content-Length: ' . filesize($file));
     ob_clean();
     flush();
     readfile($file);
     //exit;
   }

   function uploadPoly(){
      echo "<br/><form enctype='multipart/form-data' action='index.php?action=uploadingFile' method='POST'>";
      echo "<input type='hidden' name='MAX_FILE_SIZE' value='100000' />";
      echo "Choose a file to upload: <input name='uploadedfile' type='file' /><br />";
      echo "<input type='submit' value='Upload File' />";
      echo "</form>";
   }
   
   function uploadWork(){
      echo "<br/><form enctype='multipart/form-data' action='index.php?action=uploadingWork' method='POST'>";
      echo "<input type='hidden' name='MAX_FILE_SIZE' value='1000000000' />";
      echo "Completed work file (gzip) to upload: <input name='workfile' type='file' /><br />";
      echo "<input type='submit' value='Upload File' />";
      echo "</form>";
   }

   function uploadFile(){
     $target_path = WORK_DIRECTORY;
     $file_name = str_replace(".poly", "", $_FILES['uploadedfile']['name']);
      
     if (ctype_alnum(str_replace(".", "", $file_name))){
       exec("sqlite3 work.db \"select exists(select name from job where name='".$file_name."' limit 1);\"", $output);
       if ($output[0] == "1"){
         echo "Job name exists already. Quitting.<br>";
         return FALSE;
       }
     } else {
       echo "Something about the job's name is messing me up. Quitting....<br>";
       return FALSE;
     }

     mkdir($target_path.basename($file_name), 0777);
     mkdir($target_path.basename($file_name)."/done", 0777);

     $target_path_full = $target_path.basename($file_name)."/".basename( $_FILES['uploadedfile']['name']); 

     if(move_uploaded_file($_FILES['uploadedfile']['tmp_name'], $target_path_full)) {
       echo "The file ".  basename( $_FILES['uploadedfile']['name'])." has been uploaded";
     } else{
       echo "There was an error uploading the file, please try again!";
       rmdir($target_path.basename($file_name));
       return;
     }

     if (HARDCODED_MERGE_SIZE == FALSE){
       $polyfp = fopen($target_path_full, 'r+');
       $num = str_replace("\n", "", str_replace("n: ", "",fgets($polyfp)));
       fclose($polyfp);
       $size = pow(10, (strlen($num)/41+4));
       $length = strval(floor($size * floatval(BYTES_PER_RELATION)));
     } else{
       $length = MERGE_SIZE;
     }
     //exec("sqlite3 work.db \"update job set currentSize=".bchexdec($n)." where name =
     exec("sqlite3 work.db \"insert into job(name, targetSize) values('".$file_name."', ".$length.");\"", $output);
   }

   function requestWork($file_name){
      if (!ctype_alnum($file_name)){
        echo "Something about the job's name is tripping me up. Quitting...<br>";
        return FALSE;
      }

      exec("sqlite3 work.db \"select name from job where condition='WORKING' and name='".$file_name."' limit 1;\"", $output);
      if (count($output) == 0){
        echo "Work not found in database. Quitting.<br>";
        return FALSE;
      }

      $poly_directory = WORK_DIRECTORY.$file_name;
      $poly_file = $poly_directory."/".$file_name.".poly";
      if (!file_exists($poly_file)) {
        echo ".poly file not exist. Quitting.<br>";
        return FALSE;
      }

      $size = getDirSize($poly_directory."/done");
      exec("sqlite3 work.db \"update job set currentSize=".$size." where name ='".$file_name."'\"", $output);

      if (ifWorkReady($file_name)){
        echo "Total relation reached target size. Quitting...<br>";
        exec("sqlite3 work.db \"update job set condition='READY' where name='".$file_name."';\"", $output);
        return FALSE;
      }

      exec("sqlite3 work.db \"select count from job where name = '".$file_name."';\"", $output);
      $count = intval($output[1]) + 2;

      $clientfp = fopen($poly_directory."/client.id", 'w+');
      fwrite($clientfp, strval($count)."\n");
      fwrite($clientfp, TOTAL_CLIENT);
      fclose($clientfp);
      exec("cd ".$poly_directory." && tar -zcvf workrequest.tar.gz ".$file_name.".poly client.id", $output);

      echo var_dump($output);

      downloadFile($poly_directory."/workrequest.tar.gz");

      unlink($poly_directory."/workrequest.tar.gz");
      unlink($poly_directory."/client.id");
      
      exec("sqlite3 work.db \"update job set count=count+1 where name='".$file_name."';\"", $output);
      echo "ClientID=".strval($count)."<br>";
   }

   function uploadingWork(){
      $file_name = $_FILES['workfile']['name'];
      $temp_folder = rand_string(10);
      
      $temp_folder_full = TMP_DIRECTORY.$temp_folder;
      mkdir($temp_folder_full, 0700);
      
      $target_path = $temp_folder_full."/".basename($file_name);

      if(move_uploaded_file($_FILES['workfile']['tmp_name'], $target_path)) {
          echo "The file ".basename($file_name)." has been uploaded<br>";
      } else{
          echo "There was an error uploading the file, please try again!<br>";
          rmdir($temp_folder_full);
          return;
      }
      
      exec("cd ".$temp_folder_full." && tar -zxvf ".$file_name, $output);
      
      $jlist = glob($temp_folder_full."/"."*.job.*.T0");
      if (count($jlist) > 0){
          echo basename($jlist[0])." exists<br>";
      } else {
          echo ".job.*.T0 file does not exists, quitting.<br>";
          delTree($temp_folder_full);
          return;
      }

      echo $jlist[0];

      $keywords = preg_split("/\.((job\.)|(T0))/", basename($jlist[0]));
      $job_file_name = $keywords[0];
      $id_file_name = $keywords[1];

      if (ctype_alnum(str_replace(".", "", $job_file_name))){
        exec("sqlite3 work.db \"select name from job where condition='WORKING' and name='".$job_file_name."' limit 1;\"", $output);
        if (count($output) == 0){
          echo "Work not found in database. Quitting.<br>";
          delTree($temp_dolder_full);
          return FALSE;
        }
      }
     
      $list = glob($temp_folder_full."/"."spairs.add.".$id_file_name);
      if (count($list) > 0){
        echo basename($list[0])." exists<br>";
      } else {
        echo "spairs.add file does not exists, quitting.<br>";
        delTree($temp_folder_full);
        return;
      }
     $done_directory = WORK_DIRECTORY.$job_file_name."/done/";
     if (!is_dir($done_directory)) mkdir($done_directory);

     $full_path = $done_directory."spairs.add.".$id_file_name;
     $dup_count = 0;
     if (file_exists($full_path)){
       $dup_count++;
     }
     if ($dup_count > 0)
       $full_path = $full_path.".".strval($dup_count);
     if (!rename($temp_folder_full."/spairs.add.".$id_file_name, $full_path)){
       echo "Fail to move spairs.add file, quitting.<br>";
       delTree($temp_folder_full);
       return;
     }

     //TODO: Add check to make sure the job file N and the actual N are the same
     $target_name = basename($jlist[0]);
     $dup_count = 0;
     if (file_exists($done_directory.$target_name)){
       $dup_count++;
     }
     if ($dup_count > 0)
       $target_name = $target_name.".".strval($dup_count);
     if (!rename($temp_folder_full."/".basename($jlist[0]), $done_directory.$target_name)){
          echo "Fail to move .job.T0 file, quitting.<br>";
          delTree($temp_folder_full);
          return;
     }

     echo "Move files complete. <br>";
     delTree($temp_folder_full);

     //workComplete($done_directory, $job_file_name);
   }

/*
   function workComplete($done_directory, $job_name){
     $folder_size = getDirSize($done_directory);
     $size = getMergeSize($job_name);

     exec("sqlite3 work.db \"update job set targetSize =".$folder_size." where name = '".$job_name."'\"", $output);
     if ($folder_size < intval($size)){
       echo "<br>Work files size is at ".strval($folder_size)." bytes, target is ".$size." bytes.<br>";
       return FALSE;
     } else {
       echo "<br>Work files size has reach the limit ".$size." bytes (".strval($folder_size)." bytes).<br>";
       if (ctype_alnum($job_name))
         exec("sqlite3 work.db \"update job set condition='READY' where name='".$job_name."' and condition='WORKING';\"", $output);
       else {
         echo "Something about the job's name is messing me up. Quitting....<br>";
         return FALSE;
       }
       return TRUE;
     }
   }
*/

   function mergeWorkFiles($done_directory, $job_name){
     //Doing the merge in php might not be such a good idea...
     $size = getMergeSize($job_name);
     echo "<br>Work files size has reach the limit (".$size."), merging...<br>";
     $data_file = fopen($done_directory.$job_name.'.dat', 'a');

     $files = glob( $done_directory.'spairs.add.*', GLOB_MARK );
     foreach( $files as $file ){
       $work_fp = fopen($file, 'r');
       while (!feof($work_fp)){
         fwrite($data_file, fread($work_fp, 1024*8));
         set_time_limit(0);
         //flush(); 
       }
       echo basename($file)." merged. <br>";
       fclose($work_fp);
     }
     fclose($data_file);
     foreach( $files as $file ){
       unlink($file);
     }
     echo "Merge complete.<br>";
   }

   function requestAnyWork(){
     exec("sqlite3 work.db \"select name from job where condition='WORKING' limit 1;\"", $output);
     if (count($output) > 0)
       http_redirect("index.php", array("action" => "request", "work_id" => $output[0]));
     else
       http_redirect("index.php", array("action" => "done"));
   }

  function getMergeSize($work_name){
    if (HARDCODED_MERGE_SIZE == TRUE)
      $size = MERGE_SIZE;
    else{
      exec("sqlite3 work.db \"select targetSize from job where name = '".$work_name."'\"", $output);
      $size = $output[0];
    }
    return $size;
  }

  function ifWorkReady($work_name){
    $folder_size = getDirSize(WORK_DIRECTORY.$work_name."/done");
    $size = getMergeSize($work_name);
    echo "<br>Work files size is at ".strval($folder_size)." bytes, target is ".$size." bytes.<br>";

    return $folder_size >= intval($size);
  }
  
  function showStatus(){
    echo "<table border='1' cellpadding='3'>\n<tr>\n<th>Number</th><th>Name</th><th>Count</th><th>Status</th><th>Start time</th><th>End time</th><th>Current Size</th><th>Target Size</th></tr>";
    echo "<tr>";
    exec("sqlite3 work.db \"select * from job;\"", $output);
    for ($i = 0; $i < count($output); $i++){
      $arr = explode("|", $output[$i]);
      for ($j = 0; $j < count($arr); $j++){
        echo "<td>".$arr[$j]."</td>";
      }
    }
    echo "</tr>";
    echo "</table>";
    return;
  }

  function printHeadhtml(){
    echo "<html>\n<head>\n<title>GNFS Stage 2 siever</title>\n</head>\n<body>";
  }
  function printClosehtml(){
    echo "</body>\n</html>";
  }
 ?>
 <?php
 error_reporting(E_ALL);
 ini_set('display_errors', '1');

 $action = '';
 if (array_key_exists("action", $_GET)) $action = $_GET['action'];
 switch ($action){
   case "done":
     printHeadhtml();
     echo "No work available<br>";
     break;
   case "request":
     if (array_key_exists("work_id", $_GET) && $_GET['work_id'] != ''){
       printHeadhtml();
       requestWork($_GET['work_id']);
     }
     else {
       requestAnyWork();
       return;
     }
     break;
   case "upload":
     printHeadhtml();
     echo "Action is Upload<br>";
     uploadPoly();
     break;
   case "uploadingFile":
     printHeadhtml();
     echo "Action is uploadingFile<br>";
     uploadFile();
     break;
   case "uploadWork":
     printHeadhtml();
     echo "Action is Upload Work<br>";
     uploadWork();
     break;
   case "uploadingWork":
     printHeadhtml();
     echo "Action is Uploading Work<br>";
     uploadingWork();
     break;
   case "status":
     printHeadhtml();
     showStatus();
     break;
   default:
     printHeadhtml();
     echo "Action is nothing<br>";
     break;
 }
 printClosehtml();
 echo "<br/><p>Done</p>";
 ?>
