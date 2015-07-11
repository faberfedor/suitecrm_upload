<?php

  /*
   * main.php - example of using 
   *  SugarCRM/SuiteCRM's SOAP server.
   *
   *  See the accompanying README for important details
   *
   *  Faber Fedor (faber@fsquared-enterprises.com)
   *  20150701
   *
   */
  // This file contains various utility function for 
  // database access. Not included in this repo
  require_once "DBO.php";

  // utility functions for cleaner code
  require_once "SugarCrmSoap.php";

  $sugar= new SugarCrmSoap(); 
  // the session_id is passed to every subsequent
  // SugarCRM call
  $sess = $sugar->login();

  // I have a list of files in an SQLite database
  $db = new DBO();

  // Yeah, I'm on a Mac...
  $pathPrefix = '/Volumes/Public/FILES';
  echo "main: Starting run using path prefix $pathPrefix\n";

  if (!file_exists($pathPrefix) ) {
     echo "FATAL ERROR: Can't find $pathPrefix. Exiting...";
     exit;
  }

  // in case we get stuck on the same file
  // we don't want to process it over and over
  $old_file_title = '';

  while(1) {
      // fetch a row from the SQLite DB
      // the record has the following information:
      // assignment number, file name, file path, 
      // file extension, file_title, etc. In short
      // it has everything we need to create a document
      // and relate it to an assignment
      $rec = $db->getAssignmentRecord();

      if (!$rec) { break; };

      echo "Processing " . $rec['file_title'] ."... \n";
  
      if ( $old_file_title == $rec['file_title']) {
        echo "Looks like I'm trying to process a record twice. Skipping...\n";
        $db->updateAssignmentRecord($rec['file_title'], "dupe error");
        next;
      }
      
      if ($rec['revision'] == 'Revision') {
        echo "Someone loaded headers into the table :-|";
        next;
      }

      $rec['full_path'] = $pathPrefix ."/". $rec['file_location'] ."/". $rec['file_name'] . "." .$rec['file_extension'];
      
      // create a new document
      $docId = $sugar->createDocument($rec['file_title']);

      // upload file
      try {
        $revisionId = $sugar->uploadFile($docId, 1, $rec);
      } catch (Exception $e) {
        echo "Error uploading " . $rec['file_title'] .". Please check the logs \n";
        $db->updateAssignmentRecord($rec['file_title'], "upload error");
        continue;
      }

      // get assignment id from the CRM
      try {
        $assignStr = ' assignment_no = "' . $rec['assignment_no'] . '"';
        echo "Searching for $assignStr";

        $assignment = $sugar->getAssignments($assignStr, 0, 1, '');
        $assignId = $assignment->entry_list[0]->id;
        echo "Corresponding id is " . $assignId;

      } catch (Exception $e) {
        echo "Error processing " . $rec['file_title'] .". Please check the logs \n";
        $db->updateAssignmentRecord($rec['file_title'], "getassignment error");
        continue;
      }

      try {
        $sugar->setAssignmentDocumentRelationship($assignId, $docId);
        $db->updateAssignmentRecord($rec['file_title']);

      } catch (Exception $e) {
        // the exception was already logged down below so don't do it again here
        echo "Error processing " . $rec['file_title'] .". Please check the logs \n";
        $db->updateAssignmentRecord($rec['file_title'], "error");
        continue;
      }

      $old_file_title = $rec['file_title'];

      echo "Finished processing " . $rec['file_title'];
      echo " ";

  }

  echo "main: Finished run";
?>  
