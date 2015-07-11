<?php
 /*
  * SugarCrmSoap - just a class of utility functions to
  *                make uploading files and setting 
  *                relationships SugarCRM/SuiteCRM server 
  *                easier.
  *
  * Faber Fedor (faber@fsquared-enterprises.com)
  * 2015-07-01
  *
  */
  
  // gotta love logging!
  include_once('log4php/Logger.php');

  class SugarCrmSoap{
      var $sess;
      var $sess_id;
      var $soapclient;
      var $log;
      var $soap_url;
      var $login_params;

      function SugarCrmSoap(){
        Logger::configure('logconfig.xml');
        $this->log = Logger::getLogger('myLogger');

        // This is really important; it determines what options you cna pass in and out
        // Most other examples around the net leave this out which makes it 
        // incredibly difficult to figure out what's going on.
        $this->soap_url = 'http://192.168.1.10/sugar/service/v4_1/soap.php?wsdl';
        $this->login_params = array(
            'user_name' => 'admin',
            'password'  => md5('admin'),
            'version'   => '.01'
        );
        
        $this->log->debug("Connecting to SOAP URL: $this->soap_url");
        $this->log->debug("with these credentials:");
        $this->log->debug($this->login_params);

        // the trace option allows for better debugging
        $this->soapclient = new SoapClient($this->soap_url, array('trace' => 1));
        return $this->soapclient;
      }

      // We need a session id to pass around
      function login(){
        $result = $this->soapclient->login($this->login_params);
        $this->sess_id= $result->id ;
        return $this->sess;
      }

      // Assignments is a custom module we wrote.
      // Assignments have relationships with Documents
      function getAssignments($query='', $offset=0, $maxnum=0, $orderby=''){
        try {
        $result = $this->soapclient->get_entry_list(
            $this->sess_id,
            'Assignments',
            $query,
            $orderby,
            $offset,
            array(
            ),
            array(),
            $maxnum,
            0,
            false
        );
        return $result;
        } catch (Exception $e) {
          $this->catchError("getAssignments", $e);
        }
      }    

      function getDocuments($query='', $offset=0, $maxnum=0, $orderby=''){
        try {
          $result = $this->soapclient->get_entry_list(
            $this->sess_id,
            'Documents',
            $query,
            $orderby,
            $offset,
            array(
                'id',
                'description',
                'file_name',
                'document_name',
                'document_revision_id',
            ),
            array(),
            $maxnum,
            0,
            false
        );
        return $result;
        } catch (Exception $e) {
          $this->catchError("getDocuments", $e);
        }
      }    
      
      function createDocument($filename) {
          try {
            $this->log->debug("Creating new Document '$filename'");
            $result = $this->soapclient
                           ->set_entry( $this->sess_id,
                                        'Documents',
                                         array(
                                            array ( 'name'  => 'new_with_id',
                                                    'value' => true
                                                  ),
                                            array ( 'name'  => 'document_name',
                                                    'value' => $filename
                                                  )
                                           )  
                            );
                
              $this->log->debug("New document id:");
              $this->log->debug( $result->id);
              return $result->id;
        } catch (Exception $e) {
          $this->catchError("processFile", $e);
        }
      }

      function uploadFile($docID, $revision=1, $rec) {
        try {
          $this->log->debug("Uploading file " . $rec['file_title']); 
          $this->log->debug("using file contents of " . $rec['full_path']);

          // file_get_content spits out a warning about there being no file
          // then successfully gets the content. :-? Hence the 
          // warning suppression
          $file_contents = @file_get_contents($rec['full_path']);
          
          $docArray = array( 'id'       => $docID,
                             'file'     => base64_encode($file_contents),
                             'document_name' => basename($rec['file_title']),
                             'filename' => $rec['file_title'],
                             'revision' => $revision,
                             'assignment_no' => $rec['assignment_no']
              );

          $result = $this->soapclient->set_document_revision ( $this->sess_id, $docArray);

          if ( isset($results->error )) {
              $this->log->error("set_document_revision $results->error");
              throw new Exception("set_document_revision error");
              return;
          }
          $this->log->debug("New document_revision_id is $result->id");
          return $result->id;

        } catch (Exception $e) {
          $this->catchError("uploadFile", $e);
          return (-1);
        }
      }

      // N.B. Your setting a relationship between an Assignment and Documents which
      // is different than a relationship between a Document and an Assignment
      function setAssignmentDocumentRelationship($assignId, $docId) {
        // 
        $result = 'foo';
        try {
          $this->log->debug("setting relationship for assignment id $assignId and document id $docId");
          $result = $this->soapclient->set_relationship( 
                            $this->sess_id,
                            "Assignments",
                            $assignId,
                            "assignments_documents_1",
                            array($docId),
                            array(),
                            0
                  );
          return $result;
        } catch (Exception $e) {
              $this->catchError("setAssignmentDocumentRelationship", $e);
          }
        }

      // sometimes it's just nice to know which modules are available
      // I used this to get the actual names of the available modules
      function getModules($filter='all') {
        $result = $this->soapclient->get_available_modules ( $this->sess_id, $filter);

        if ( isset($results->error )) {
            $this->log->error("get_modules_error: $results->error");
            throw new Exception("get_modules error");
            return;
        }
        
        return $result;
      }
      
      // sometimes it's useful to know which fields a module has
      // the docs aren't clear enough for me
      function getModuleFields($module, $fields='') {
          $result = $this->soapclient->get_module_fields(
                            $this->sess_id,
                            $module,
                            $fields
              );

        if ( isset($results->error )) {
            $this->log->error("get_module_fields: $results->error");
            throw new Exception("get_module_fields error");
            return;
        }
        
        return $result;
      }

      // an all-purpose error handler
      function catchError($function, $e) {
        
              // the 'trace => 1' option up above allows us to get this
              // header information
              $this->log->error( "====== REQUEST HEADERS =====");
              $this->log->error($this->soapclient->__getLastRequestHeaders());
              // the base-64 encoded file will be included in the response if the
              // operation was an upload. We don't want to see
              // that in the log
              if ($function != 'uploadFile') {
                $this->log->error( "========= REQUEST ==========");
                $this->log->error($this->soapclient->__getLastRequest());
              }
              $this->log->error( "====== RESPONSE HEADERS =====");
              $this->log->error($this->soapclient->__getLastResponseHeaders());
              $this->log->error( "========= RESPONSE ==========");
              $this->log->error($this->soapclient->__getLastResponse());
              //$this->log->error($result);

              $this->log->error("$function error: $e()");
              // continue on
              throw new Exception($e);

        }
  }
?>  
