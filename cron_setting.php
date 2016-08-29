<?php

error_reporting(E_ALL);
require_once "../config/config.php";
require_once "../lib/couch.php";
require_once "../lib/couchClient.php";
require_once "../lib/couchDocument.php";
require_once "common_functions.php";

// Fetching all the cron-records with the operation case 8 which is for sending a mail to pharmacy.
$client                        = new couchClient(COUCH_DSN,COUCH_DB);
$users_client                  = new couchClient(COUCH_DSN,USERS_DB);
$users_personal_details_client = new couchClient(COUCH_DSN,PERSONAL_DETAILS_DB);
$log_client 				   = new couchClient(COUCH_DSN,LOGGING_DATABASE);
$doctor_details				   = new couchClient(COUCH_DSN,REPLICATED_DB);
$response                      = $client->getView('tamsa', 'getCronRecords');

$count = array(
		"1"  => 0,
		"2"  => 0,
		"3"  => 0,
		"4"  => 0,
		"5"  => 0,
		"6"  => 0,
		"7"  => 0,
		"8"  => 0,
		"9"  => 0,
		"10" => 0,
		"11" => 0,
		"12" => 0,
		"13" => 0,
		"14" => 0,
		"15" => 0,
		"16" => 0,
		"17" => 0,
		"18" => 0,
		"19" => 0,
		"20" => 0,
		"21" => 0,
		"22" => 0,
		"23" => 0,
		"24" => 0,
		"25" => 0,
		"26" => 0
	);

foreach ($response->rows as $key => $record){

	switch ($record->value->operation_case){
		case '18':
			break;
			try {
				$doc        = $client->getDoc($record->value->appointment_id);

				// If in the appointment doc doctor_id is saved than only procced further else mark the record processed
				if (isset($doc->doctor_id)) {
					$doctor_doc = $users_client->getDoc($doc->doctor_id);
				}
				else {
					$record->value->processed = "Yes";
					$response = $client->storeDoc($record->value);
					break;
				}

				if (isset($doc->consultant_id)) {
					$consultant_doc = $users_client->getDoc($doc->consultant_id);
				}

				if (!isset($doc->user_id)) {
				    $client->deleteDoc($doc);
				    $record->value->processed = "Yes";
					$response                 = $client->storeDoc($record->value);
					$count["18"]++;
					break;
				}
				$user_doc = $users_client->getDoc($doc->doctor_id);
	       		$users_personal_details_client->key($doc->user_id);
				$response = $users_personal_details_client->getView('tamsa','getPatientInformation');

				if (count($response->rows) > 0) {
					$client->key($doctor_doc->dhp_code);
					$client->reduce(false);
					$client->include_docs(True);
					$comsettings = $client->getView('tamsa', 'getCommunicationSettings');
					if($comsettings->rows[0]->value->email_setting->online_scheduling_emails == 1){
						$html = getAppointmentHtml("".$response->rows[0]->value->first_nm." ".$response->rows[0]->value->last_nm."", getGmtStringToIstTime($doc->reminder_start), $user_doc->hospital_affiliated, $user_doc->city, $user_doc->hospital_phone);

						$attachment = array();
						if(isset($record->value->hospital_document)) {
							echo ("<pre>");print_r($record);echo("</pre>");
							$hospital_document           = $client->getDoc($record->value->hospital_document);
							$hospital_document_file_name = array_keys((array)$hospital_document->_attachments)[0];
							set_time_limit(0);
							$url  = urldecode("http://".DB_HOST."/".COUCH_DB."/".$record->value->hospital_document."/".$hospital_document_file_name);
							$file = file_get_contents(str_replace(" ","%20",$url));
							file_put_contents($hospital_document_file_name, $file);
							$attachment[] = $hospital_document_file_name;	
						}

						if (isset($record->value->service_documents)) {
							foreach ($record->value->service_documents as $service_doc_id) {
								$service_doc           = $client->getDoc($service_doc_id);
								$service_doc_file_name = array_keys((array)$service_doc->_attachments)[0];
								set_time_limit(0);
								$url  = urldecode("http://".DB_HOST."/".COUCH_DB."/".$service_doc_id."/".$service_doc_file_name);
								$file = file_get_contents(str_replace(" ","%20",$url));
								file_put_contents($service_doc_file_name, $file);
								$attachment[] = $service_doc_file_name;
							}
						}

						$fields_array_mail = array(
							"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
							"to"      => $response->rows[0]->value->user_email,
							"subject" => "Appointment Mail",
							"html"    => $html
					    );

						if (count($attachment) > 0) {
							$attachment_to_send = array("attachment" => $attachment);
							$result_obj = sendMail($fields_array_mail,$attachment_to_send);
							foreach ($attachment as $file_to_delete) {
								unlink($file_to_delete);
							}
						}
						else {
							$result_obj = sendMail($fields_array_mail);
						}
					}	

		      // SEND SMS TO DOCTOR***************************
		      $yes = "Yes";
					if($comsettings->rows[0]->doc->sms_setting->sms_to_doctor->new_appointment == 1) {
						$fields_array_sms = array(
							"To"   => $doctor_doc->alert_phone,
							"From" => "+14085602499",
							"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has scheduled appointment\nAppointment Note: ". $doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
						);
						$result_sms_obj = sendSms($fields_array_sms);
          }

					// SMS TO hospital_admin

					if($comsettings->rows[0]->doc->sms_setting->sms_to_hospital_admin->new_appointment == 1) {
						$doctor_details->key(array("".$doctor_doc->dhp_code."","".$yes.""));
            $doctor_details->include_docs(True);
            $hospital_details = $doctor_details->getView('tamsa', 'getUserByDhpId');
            if(count($hospital_details->rows)>0){
            	for($i=0;$i<count($hospital_details->rows);$i++){
            		$fields_array_sms = array(
									"To"   => $hospital_details->rows[$i]->doc->alert_phone,
									"From" => "+14085602499",
									"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has scheduled appointment\nAppointment Note: ". $doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
								);
								$result_sms_obj = sendSms($fields_array_sms);		
							}
            }
					}

					if (isset($consultant_doc)) {
						$client->key($consultant_doc->dhp_code);
						$client->reduce(false);
						$client->include_docs(True);
						$consultant_comsettings = $client->getView('tamsa', 'getCommunicationSettings');
						
		         		if($consultant_comsettings->rows[0]->doc->sms_setting->sms_to_doctor->new_appointment == 1) {
							$fields_array_sms = array(
								"To"   => $consultant_doc->alert_phone,
								"From" => "+14085602499",
								"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has scheduled appointment\nAppointment Note: ". $doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
							);
							$result_sms_obj = sendSms($fields_array_sms);
						}
					}

					$record->value->processed = "Yes";
					$response = $client->storeDoc($record->value);
				}
			} 
			catch (Exception $e) {
				if ( $e->getCode() == 404 ) {
        	echo "Document some_doc_id does not exist !";
        	echo ("<pre>");print_r($record->value->operation_case);echo("</pre>");
          $record->value->processed = "Yes";
	   			$response = $client->storeDoc($record->value);
	   	  }	
			}
			//$count["18"]++;
			break;

		// Apointment rescheduled
		case '19':
			break;
			try {
				$doc = $client->getDoc($record->value->appointment_id);
				
				if (!isset($doc->user_id)) {
				  $client->deleteDoc($doc);
					$record->value->processed = "Yes";
					$response = $client->storeDoc($record->value);
					$count["19"]++;
					break;
				}

				if (isset($doc->consultant_id) && $doc->consultant_id != "noselect") {
					$consultant_doc = $users_client->getDoc($doc->consultant_id);
				}

				$user_doc = $users_client->getDoc($doc->doctor_id);
				
				$users_personal_details_client->key($doc->user_id);
				$response = $users_personal_details_client->getView('tamsa','getPatientInformation');
			
				if (count($response->rows) > 0) {
					$fields_array_mail = array(
						"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
						"to"      => $response->rows[0]->value->user_email,
						"subject" => "Appointment Mail",
						"text"    => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has rescheduled your appointment\n Appointment Note: ".$doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
				    );

		      $result_obj = sendMail($fields_array_mail);

					$client->key($doc->dhp_code);
					$client->reduce(false);	
					$client->include_docs(True);	
					$comsettings          = $client->getView('tamsa', 'getCommunicationSettings');
					$apt_reseduled_select = $comsettings->rows[0]->doc->sms_to_patient_setting->appointment_rescheduling;
					
		         	// SEND SMS TO PATEINTS
					if($apt_reseduled_select != "Never") {
						if($apt_reseduled_select == "Immediately") {
							$fields_array_sms = array(
								"To"   => $response->rows[0]->value->phone,
								"From" => "+14085602499",
								"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has rescheduled your appointment\nAppointment Note: ". $doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
							);
								
			        $result_sms_obj = sendSms($fields_array_sms);
						}
					}

					// SEND SMS TO DOCTOR
					if($comsettings->rows[0]->doc->sms_setting->sms_to_doctor->appointment_rescheduling == 1) {
						$fields_array_sms = array(
							"To"   => $user_doc->alert_phone,
							"From" => "+14085602499",
							"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has rescheduled appointment.\nPatinet name: ".$response->rows[0]->value->first_nm." ".$response->rows[0]->value->last_nm."\nAppointment Note: ". $doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
						);
						$result_sms_obj_doc = sendSms($fields_array_sms);
					}

					// SMS TO HOSPITAL ADMIN
					$yes = "Yes";
					if($comsettings->rows[0]->doc->sms_setting->sms_to_hospital_admin->appointment_rescheduling == 1) {
						$doctor_details->key(array("".$user_doc->dhp_code."","".$yes.""));
            $doctor_details->include_docs(True);
            $hospital_details = $doctor_details->getView('tamsa', 'getUserByDhpId');
            if(count($hospital_details->rows)>0){
            	for($i=0;$i<count($hospital_details->rows);$i++){
            		$fields_array_sms = array(
									"To"   => $hospital_details->rows[$i]->doc->alert_phone,
									"From" => "+14085602499",
									"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has rescheduled appointment.\nPatinet name: ".$response->rows[0]->value->first_nm." ".$response->rows[0]->value->last_nm."\nAppointment Note: ". $doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
								);

							$result_sms_obj = sendSms($fields_array_sms);		
							}
            }
					} 

					if (isset($consultant_doc)) {
						$client->key($consultant_doc->dhp_code);
						$client->reduce(false);
						$client->include_docs(True);
						$consultant_comsettings = $client->getView('tamsa', 'getCommunicationSettings');
						
	         	if($consultant_comsettings->rows[0]->doc->sms_setting->sms_to_doctor->appointment_rescheduling == 1) {
							$fields_array_sms = array(
								"To"   => $consultant_doc->alert_phone,
								"From" => "+14085602499",
								"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name." has rescheduled appointment.\nPatinet name: ".$response->rows[0]->value->first_nm." ".$response->rows[0]->value->last_nm."\nAppointment Note: ". $doc->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($doc->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($doc->reminder_start),-9)." to".substr(getGmtStringToIstTime($doc->reminder_end),-9)
							);
							
			        $result_sms_obj = sendSms($fields_array_sms);
						}
					}
					//Setting cron record processed
					$record->value->processed = "Yes";
					$response = $client->storeDoc($record->value);
				}		
			} 
			catch (Exception $e) {
				if ( $e->getCode() == 404 ) {
        	echo "Document some_doc_id does not exist !";
        	echo ("<pre>");print_r($record->value->operation_case);echo("</pre>");
          $record->value->processed = "Yes";
	   			$response = $client->storeDoc($record->value);
	   	  }	
			}
			//$count["19"]++;
			break;

	// Appointment Cancel
		case '20':
			break;
			echo "helloq";
			if (isset($record->value->consultant_id) && $record->value->consultant_id != "noselect" ) {
				$consultant_doc = $users_client->getDoc($record->value->consultant_id);
			}

			if (isset($record->value->doctor_id)) {
					$user_doc = $users_client->getDoc($record->value->doctor_id);
			}
				
			
			$client->key($record->value->dhp_code);
			$client->reduce(false);
			$client->include_docs(True);
			$comsettings       = $client->getView('tamsa', 'getCommunicationSettings');
			$apt_cancel_select = $comsettings->rows[0]->doc->sms_to_patient_setting->appointemnt_cancellation;
      $users_personal_details_client->key($record->value->user_id);
			$response = $users_personal_details_client->getView('tamsa','getPatientInformation');
			if (count($response->rows) > 0) {
				$fields_array_mail = array(
					"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
					"to"      => $response->rows[0]->value->user_email,
					"subject" => "Appointment Cancellation",
					"text"    => "Dr ".$record->value->doctor_name." has Cancel appointment\nAppointment Note: ".$record->value->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($record->value->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($record->value->reminder_start),-9)." to".substr(getGmtStringToIstTime($record->value->reminder_end),-9)
			    );
				$result_obj = sendMail($fields_array_mail);

				//SMS Sending start....
				if($apt_cancel_select != "Never") {
					if($apt_cancel_select == "Immediately") {
						$fields_array_sms = array(
							"To"   => $response->rows[0]->value->phone,
							"From" => "+14085602499",
							"Body" => "Dr ".$record->value->doctor_name." has Cancel your appointment\nAppointment Note: ".$record->value->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($record->value->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($record->value->reminder_start),-9)." to".substr(getGmtStringToIstTime($record->value->reminder_end),-9)
						);
						$result_sms_obj = sendSms($fields_array_sms);
					}
				}
				
				// SEND SMS TO DOCTOR
				if($comsettings->rows[0]->doc->sms_setting->sms_to_doctor->appointment_cancellation == 1) {
					$fields_array_sms = array(
						"To"   => $user_doc->alert_phone,
						"From" => "+14085602499",
						"Body" => "Dr ".$record->value->doctor_name." has Cancel appointment\nAppointment Note: ".$record->value->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($record->value->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($record->value->reminder_start),-9)." to".substr(getGmtStringToIstTime($record->value->reminder_end),-9)
					);
					$result_sms_obj = sendSms($fields_array_sms);
				}

				// SMS TO HOSPITAL ADMIN
				$yes = "Yes";
				if($comsettings->rows[0]->doc->sms_setting->sms_to_hospital_admin->appointment_cancellation == 0) {
					
					$doctor_details->key(array("".$user_doc->dhp_code."","".$yes.""));
          $doctor_details->include_docs(True);
          $hospital_details = $doctor_details->getView('tamsa', 'getUserByDhpId');
          if(count($hospital_details->rows)>0){
          	for($i=0;$i<count($hospital_details->rows);$i++){
          		$fields_array_sms = array(
								"To"   => $hospital_details->rows[$i]->doc->alert_phone,
								"From" => "+14085602499",
								"Body" => "Dr ".$record->value->doctor_name." has Cancel appointment\nAppointment Note: ".$record->value->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($record->value->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($record->value->reminder_start),-9)." to".substr(getGmtStringToIstTime($record->value->reminder_end),-9)
							);
							$result_sms_obj = sendSms($fields_array_sms);				
						}
          }
				} 

				if (isset($consultant_doc)) {
					$client->key($consultant_doc->dhp_code);
					$client->reduce(false);
					$client->include_docs(True);
					$consultant_comsettings = $client->getView('tamsa', 'getCommunicationSettings');
					
       		if($consultant_comsettings->rows[0]->doc->sms_setting->sms_to_doctor->new_appointment == 1) {
						$fields_array_sms = array(
							"To"   => $consultant_doc->alert_phone,
							"From" => "+14085602499",
							"Body" => "Dr ".$record->value->doctor_name." has Cancel appointment\nAppointment Note: ".$record->value->reminder_note."\n Appointment Date: ".substr(getGmtStringToIstTime($record->value->reminder_start),0,-9)."\n Appointment time:".substr(getGmtStringToIstTime($record->value->reminder_start),-9)." to".substr(getGmtStringToIstTime($record->value->reminder_end),-9)
						);
						$result_sms_obj = sendSms($fields_array_sms);
					}
				}
				$record->value->processed = "Yes";
				$response = $client->storeDoc($record->value);
			}
			//$count["20"]++;
			break;
		case '11':
			break;
			try {
				$lab_doc  = $client->getDoc($record->value->lab_doc_id);
				$user_doc = $users_client->getDoc($record->value->doctor_id);

				$client->key($user_doc->dhp_code);
				$client->reduce(false);
				$client->include_docs(True);
				$comsettings = $client->getView('tamsa', 'getCommunicationSettings');
				//Sending Email to lab...
				$fields_array_mail = array(
					"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
					"to"      => $lab_doc->contact_person_email,
					"subject" => "Lab Order",
					"text"    => "Dr. ".$user_doc->first_name." \n ".$user_doc->last_name. " has ordered a lab with following details \n Patinent Name : ".$record->value->patient_name."\n Order Number : ".$record->value->order_number."\n Tests: ".implode(" ,", $record->value->tests)
			    );
					$result_obj = sendMail($fields_array_mail);


					// SEND SMS TO DOCTOR
         	if($comsettings->rows[0]->doc->sms_setting->sms_to_doctor->lab_new_order == 1) {
	         	$fields_array_sms = array(
							"To"   => $user_doc->alert_email,
							"From" => "+14085602499",
							"Body" => "Hello ".$user_doc->first_name." ".$user_doc->last_name	.",\n Patinent Name ".$record->value->patient_name." Ordered lab,\n Test Name : ".implode(" ,",$record->value->tests)."\n Order Number : ".$record->value->order_number
						);
						$result_sms_obj = sendSms($fields_array_sms);
					}

				// SMS TO hospital_admin
				$yes = "Yes";
				if($comsettings->rows[0]->doc->sms_setting->sms_to_hospital_admin->lab_new_order == 1) {
					$doctor_details->key(array("".$user_doc->dhp_code."","".$yes.""));
          $doctor_details->include_docs(True);
          $hospital_details = $doctor_details->getView('tamsa', 'getUserByDhpId');
          if(count($hospital_details->rows)>0){
          	for($i=0;$i<count($hospital_details->rows);$i++){
          		$fields_array_sms = array(
								"To"   => $hospital_details->rows[$i]->doc->alert_phone,
								"From" => "+14085602499",
								"Body" => "Dr ".$user_doc->first_name." ".$user_doc->last_name	." patient:".$record->value->patient_name." Ordered lab ,\n Test Name : ".implode(" ,", $record->value->tests)."\n Comment : ".$record->value->order_number
							);
							
							$result_sms_obj = sendSms($fields_array_sms);
          	}
          }		
				}
					$record->value->processed = "Yes";
   				$response                 = $client->storeDoc($record->value);
			} 
			catch (Exception $e) {
				if ( $e->getCode() == 404 ) {
		           echo "Document some_doc_id does not exist !";
			    }
			    echo ("<pre>");print_r($record->value->operation_case);echo("</pre>");
			}

			//$count["11"]++;
			break;	

		case '26':
			break;
			$doc       = $client->getDoc($record->value->document_id);
			$doctor_doc = $users_client->getDoc($doc->doctor_id);
			$client->key($doc->dhp_code);
			$client->reduce(false);
			$client->include_docs(True);	
			$comsettings = $client->getView('tamsa', 'getCommunicationSettings');
			
			$users_personal_details_client->key($doc->user_id);						
			$users_personal_details_client->reduce(false);
			$user_details = $users_personal_details_client->getView('tamsa', 'getPatientInformation');
			if($comsettings->rows[0]->doc->sms_to_patient_setting->new_lab_order_results_availabel == "Immediately") {
				
				$fields_array_sms = array(
						"To"   => $user_details->rows[0]->value->phone,
						"From" => "+14085602499",
						"Body" => "Hello ".$user_details->rows[0]->value->first_nm." ".$user_details->rows[0]->value->last_nm.",Your lab result available,\n Document Name :".$doc->document_name."\nDocument category:".$doc->document_category."\n By : Dr.".$doc->doctor_name
				);
					$result_sms_obj = sendSms($fields_array_sms);
			}

			// SEND SMS TO DOCTOR
     	if($comsettings->rows[0]->doc->sms_setting->sms_to_doctor->lab_upload_doctor == 1) {
				$fields_array_sms = array(
					"To"   => $doctor_doc->alert_phone,
					"From" => "+14085602499",
					"Body" => "Hello Dr.".$doc->doctor_name.",lab result available,\nDocument Name :".$doc->document_name."\nDocument category:".$doc->document_category
				);
					$result_sms_obj = sendSms($fields_array_sms);
			}

			// SMS TO hospital_admin
			if($comsettings->rows[0]->doc->sms_setting->sms_to_hospital_admin->lab_upload_doctor == 1) {
				$fields_array_sms = array(
					"To"   => $doctor_doc->alert_phone,
					"From" => "+14085602499",
					"Body" => "Hello, ".$user_details->rows[0]->value->first_nm." ".$user_details->rows[0]->value->last_nm." patient lab result available,\n Document Name :".$doc->document_name."\nDocument category:".$doc->document_category
				);
				$result_sms_obj = sendSms($fields_array_sms);
			}

			//$record->value->processed = "Yes";
			//$response                 = $client->storeDoc($record->value);

			break;
		case '9' :
			try {
					$pharmacy_doc = $client->getDoc($record->value->pharmacy_doc_id);
					$doctor_doc = $users_client->getDoc($record->value->doctor_id);
					
					$users_personal_details_client->key($record->value->user_id);
					$user_info = $users_personal_details_client->getView('tamsa', 'getPatientInformation');

					$client->key($record->value->prescription_id);
					$client->include_docs(True);
					$medication_info = $client->getView('tamsa', 'getMedicationByPrescriptionId');
					if (count($user_info->rows) > 0) {
						$html = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h4>Following medicine has been prescribed to:</h4><table><tr><td><strong>Patient:</strong></td><td>'.$user_info->rows[0]->value->first_nm.'</td></tr>';
							if (count($medication_info->rows) > 0) {
								for ($i=0; $i <count($medication_info->rows) ; $i++) { 
										$html .= "<tr><td><strong>Drug Name:</strong></td><td>".$medication_info->rows[$i]->value->drug."</td></tr><tr><td><strong>Quantity:</strong></td><td>".$medication_info->rows[$i]->value->drug_quantity."</td></tr><tr><td><strong>Desperse Form:</strong></td><td>".$medication_info->rows[$i]->value->desperse_form."</td></tr>";		
								}
							}
							$html .= '</table><body></html>';

							$fields_array_mail_patient = array(
								"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
								"to"      => $user_info->rows[0]->value->user_email,
								"subject" => "Medication",
								"html"    => $html
							);
					}
					//echo "<pre>"; print_r($fields_array_mail_patient);echo "<pre>";
					//$result_mail_patient_obj = sendMail($fields_array_mail_patient);

					if (isset($record->value->send_ERx_to_pharmacy)) {
						$html = '<html xmlns="http://www.w3.org/1999/xhtml"><body><h4>Following medicine has been prescribed to:</h4><table><tr><td><strong>Patient:</strong></td><td>'.$user_info->rows[0]->value->first_nm.'</td></tr>';
						if (count($medication_info->rows) > 0) {
							for ($i=0; $i <count($medication_info->rows) ; $i++) { 
									$html .= "<tr><td><strong>Drug Name:</strong></td><td>".$medication_info->rows[$i]->value->drug."</td></tr><tr><td><strong>Quantity:</strong></td><td>".$medication_info->rows[$i]->value->drug_quantity."</td></tr><tr><td><strong>Desperse Form:</strong></td><td>".$medication_info->rows[$i]->value->desperse_form."</td></tr>";		
							}
						}
						$html .= '</table><body></html>';

						$fields_array_mail = array(
							"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
							"to"      => $pharmacy_doc->pharmacy_email,
							"subject" => "Medication",
							"html"    => $html
					 	);
						// echo "<pre>"; print_r($fields_array_mail);echo "<pre>";
						//$result_obj = sendMail($fields_array_mail);

						//SMS Sending...	
						$client->key($doctor_doc->dhp_code);
						$client->reduce(false);
						$client->include_docs(True);
						$comsettings = $client->getView('tamsa', 'getCommunicationSettings');
						
						// SEND SMS TO DOCTOR
		         	if($comsettings->rows[0]->doc->sms_setting->sms_to_doctor->pharmacy_new_order == 1) {
			         	$fields_array_sms = array(
									"To"   => $doctor_doc->alert_email,
									"From" => "+14085602499",
									"Body" => "Hello ".$doctor_doc->first_name." ".$doctor_doc->last_name	.",\nFollowing medicine has been prescribed to Patinent Name ".$user_info->rows[0]->value->first_nm."\n Pharmacy Name ".$pharmacy_doc->pharmacy_name
								);
								if (count($medication_info->rows) > 0) {
									for ($i=0; $i <count($medication_info->rows) ; $i++) { 
											$fields_array_sms["Body"] .= "\nDrug Name: ".$medication_info->rows[$i]->value->drug;	
									}
								}
								//$result_sms_obj = sendSms($fields_array_sms);
							}

						// SMS TO hospital_admin
						$yes = "Yes";
						if($comsettings->rows[0]->doc->sms_setting->sms_to_hospital_admin->pharmacy_new_order	 == 1) {
							$doctor_details->key(array("".$doctor_doc->dhp_code."","".$yes.""));
		          $doctor_details->include_docs(True);
		          $hospital_details = $doctor_details->getView('tamsa', 'getUserByDhpId');
		          if(count($hospital_details->rows)>0){
		          	for($i=0;$i<count($hospital_details->rows);$i++){
		          		$fields_array_sms = array(
										"To"   => $hospital_details->rows[$i]->doc->alert_phone,
										"From" => "+14085602499",
										"Body" => "Hello, ".$doctor_doc->first_name." ".$doctor_doc->last_name	.",\nFollowing medicine has been prescribed to Patinent Name ".$user_info->rows[0]->value->first_nm."\n Pharmacy Name ".$pharmacy_doc->pharmacy_name
									);
								if (count($medication_info->rows) > 0) {
									for ($j=0; $j <count($medication_info->rows) ; $j++) { 
											$fields_array_sms["Body"] .= "\nDrug Name: ".$medication_info->rows[$j]->value->drug;	
									}
								}
									//$result_sms_obj = sendSms($fields_array_sms);
		          	}
		          }		
						}
					}
			}
			catch (Exception $e) {
				if ( $e->getCode() == 404 ) {
		           echo "Document some_doc_id does not exist !";
			    }
			    echo ("<pre>");print_r($e);echo("</pre>");
			    echo ("<pre>");print_r($record->value->operation_case);echo("</pre>");
			}

	 		//$record->value->processed = "Yes";
			//$response                 = $client->storeDoc($record->value);
			$count["9"]++;
			break;
	}		
}	






function getAppointmentHtml($name, $datetime, $hospital, $address, $phone) {
	return "<html xmlns='http://www.w3.org/1999/xhtml'>
		<body>
			<p>Hello ".$name.",</p>
			<p>You have an upcoming appointment on ".$datetime."</p>
			<p>To ensure your health and safety, and that of others, if either of the following situations apply to you, please call your primary care office as soon as possible:</p>
			<p>To cancel your upcoming appointment, please call the appointment's office directly. You cannot cancel by replying to this e-mail.</p>
			<p>Because timely communication is important to your health care, please do not mark this message as SPAM, and make sure you have set your SPAM filter to allow messages from info@sensoryhealth.com
			</p>
			<p>Healthy regards, <br />
			Care Team <br />
			".$hospital." <br />
			".$address." <br />
			".$phone."</p>
			<p><b>This is an automated message. Please do not reply to this message</b></p>
		</body>
	</html>";
}

function getGmtStringToIstTime($gmt_string) {
	$timezone = new DateTimeZone('GMT');

	$date     = DateTime::createFromFormat('M j Y H:i:s', substr($gmt_string, 4, 20), $timezone);
	$date->setTimeZone(new DateTimeZone('Asia/Calcutta'));
	$triggerOn =  $date->format('Y-m-d H:i:s');

	return $triggerOn; // echoes 2013-04-01 22:08:00 	
}

function getBillHtml($data) {

	$abc = '<div style="padding-top: 0px;" id="" class="row"><div id="preview_header_parent" style="border-bottom: 1px solid grey;" class="col-lg-12">
         <table class="table common-preview-invoice-details" style="width:100%">
         <tbody><tr>';

		        if ($data['is_display_logo'])
		        $abc .= '<td style="padding: 0px; width: 26%;"><img width="75%" title="Company Logo" alt="Company Logo" src="'.$data['logo_url'].'"></td>';

              $abc .= '<td style="padding-bottom: 0px; width: 74%;">
                     <table class="table common-preview-invoice-details invoice-header" style="float: left; border-right: 1px solid rgb(210, 210, 210); height: 97px; width: 43%;">
                        <tbody>
                           <tr>
                              <td style="line-height: 0.45 !important;padding-bottom:3px !important;padding-top:3px !important;"><img src="http://'.DB_HOST.'/'.COUCH_DB.'/'.'_design/tamsa/locate.png"><span style="margin-top:10px;margin-left: 6px;">'.$data['pdhospital'].'</span></td>
                           </tr>
                           <tr>
                              <td style="line-height:0.45 !important;padding-left:23px;padding-bottom:3px !important;padding-top:3px !important;">'.$data['hospital_address'].'</td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 !important;padding-left:23px;padding-bottom:3px !important;padding-top:3px !important;">'.$data['hospital_secondary_address'].' '.$data['hospital_city'].' </td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 !important;padding-left:23px;padding-bottom:3px !important;padding-top:3px !important;">'.$data['hospital_city'].' '.$data['hospital_state'].', '.$data['hospital_postal_zip_code'].' '.$data['country'].'</td>
                           </tr>
                        </tbody>
                     </table>
                     <div style="border-right:1px solid rgb(210,210,210);float:left;min-height:89px;margin-left:6px;margin-top:7px;padding-right:12px"><img src="http://'.DB_HOST.'/'.COUCH_DB.'/'.'_design/tamsa/_design/tamsa/ph.png">'.$data['phone'].'</div><div style="min-height: 89px; float: left; margin-left: 6px; margin-top: 9px; width: 221px;"><span style="float: left;"><img src="http://'.DB_HOST.'/'.COUCH_DB.'/'.'_design/tamsa/_design/tamsa/glo.png"></span>info@babybeeps.com</span><span style="float: left; width: 100%; margin-left: 24px;">www.babybeeps.com</span></div>
                  </td>
               </tr>
            </tbody>
         </table>
      </div>
      <div class="col-lg-12">
         <table class="table preview-invoice-patient-details common-preview-invoice-details">
            <tbody>
               <tr>
                  <td style="line-height: 0.45 !important;width:40%;">
                     <table class="table common-preview-invoice-details patitentAddress">
                        <tbody>
                           <tr>
                              <td style="line-height: 0.45 !important;padding-top:10px;padding-bottom:13px;"><b style="text-transform:uppercase;color:rgb(119, 119, 119)">Bill To:</b></td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 !important;padding-top:3px;padding-left:26px;">'.$data['first_nm'].' '.$data['last_nm'].' ('.$data['patient_dhp_id'].')</td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 ! important;padding-bottom: 7px;"><img src="http://'.DB_HOST.'/'.COUCH_DB.'/'.'tamsa/locate.png" style="width:20px;">&nbsp;'.$data['address1'].'</td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 ! important; padding-left: 21px; padding-bottom:7px;">&nbsp;'.$data['address2'].'</td>
                           </tr>
                           
                           <tr>
                              <td style="line-height: 0.45 ! important; padding-top: 3px; padding-left: 25px;">'.$data['city'].', '.$data['state'].', '.$data['pincode'].'</td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 ! important; padding-top: 3px; padding-bottom: 11px;"><img src="http://'.DB_HOST.'/'.COUCH_DB.'/'.'tamsa/ph.png">4083861854</td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 !important;padding-top: 3px;padding-bottom:11px;"><span><img src="http://'.DB_HOST.'/'.COUCH_DB.'/'.'tamsa/glo.png">www.babybeeps.com</span></td>
                           </tr>
                        </tbody>
                     </table>
                  </td>
                  <td style="line-height: 0.45 !important">
                     <table style="text-align:right;" class="table common-preview-invoice-details invoice-details">
                        <tbody>
                           <tr>
                              <td style="width:100%;"><span style="font-size: 19px; font-weight: bold; color: rgb(119, 119, 119); margin-right: 19px; float: left; margin-bottom: 9px;">INVOICE</span><span><b style="color: rgb(119, 119, 119); font-size: 15px;">DHP Code : </b><span style="color: rgb(51, 51, 51); font-weight: bold;">H-testingdhp</span><span style="background: rgb(255, 255, 255) none repeat scroll 0% 0%; margin-left: 24px;" class="preview-invoice-no"><b style="color: rgb(119, 119, 119); font-size: 15px;">INVOICE NO : </b><span style="color:#333;">#'.$data['invoice_no'].'</span></span></span></td>
                           </tr>
                           <tr>
                              <td style="line-height: 0.45 ! important; padding-right: 0px;">
                                 <div style="background: rgb(242, 187, 92) none repeat scroll 0% 0%; border-radius: 10px 10px 0px 0px; color: rgb(255, 255, 255); text-align: left; float: left; padding: 16px; margin-right: 5px; width: 111px;"><b style="color:#fff;font-weight:bold;">Total Due</b><br><br><br><span style="margin-left: 78px;">'.$data['total_balance_due'].'</span></div>
                                 <div style="border-radius: 10px 10px 0px 0px; color: rgb(255, 255, 255); text-align: left; padding: 16px; margin-right: 5px; width: 111px; float: left; background: rgb(103, 162, 45) none repeat scroll 0% 0%;"><b style="color: rgb(255, 255, 255); font-size: 15px;">Invoice Date</b><br><br><br><span style="margin-left: 32px;">'.$data['invoice_date'].'</span></div>
                                 <div style="border-radius: 10px 10px 0px 0px; color: rgb(255, 255, 255); text-align: left; float: left; padding: 16px; margin-right: 5px; width: 111px; background: rgb(103, 162, 45) none repeat scroll 0% 0%;"><b style="color: rgb(255, 255, 255); font-size: 15px;">Due Date</b><br><br><br><span style="margin-left: 28px;">'.$data['bill_due_date'].'</span></div>
                              </td>
                           </tr>
                        </tbody>
                     </table>
                  </td>
               </tr>
            </tbody>
         </table>
      </div>
      <div class="row">
         <div style="padding-right:15px;width:810px;">
            <table cellspacing="0" cellpadding="0" border="0" width="700px" style="padding: 0px; margin: 0px auto;">
               <tbody>
                  <tr>
                     <td style="line-height: 0.45 !important">
                        <table cellspacing="0" cellpadding="0" border="0" width="790" style="margin: 4px auto;">
                           <tbody>
                              <tr>
                                 <td style="line-height: 0.45 !important">
                                    <table cellspacing="0" cellpadding="0" border="0" width="765" class="mrg-top invoice-table" style="margin-top: 5px;">
                                       <tbody>
                                          <tr>
                                             <td height="22" align="left" valign="top" style="font-size:13px; color:#000; font-family:arial; font-weight:bold;">
                                                <table width="100%">
                                                   <thead>
                                                      <tr>
                                                         <th style="background: none !important;color: #67a22d;text-transform: uppercase;padding:15px 15px 15px 1px">Sr. No</th>
                                                         <th style="background: none !important;color: #67a22d;text-transform: uppercase;padding:5px;">Date Of Service</th>
                                                         <th style="background: none !important;color: #67a22d;text-transform: uppercase;padding:5px;">Description</th>
                                                         <th style="background: none !important;color: #67a22d;text-transform: uppercase;padding:5px;">Diagnosis Code</th>
                                                         <th style="background: none !important;color: #67a22d;text-transform: uppercase;padding:5px;">Billing Code</th>
                                                         <th style="background: none !important;color: #67a22d;text-transform: uppercase;padding:5px;">Total</th>
                                                      </tr>
                                                   </thead>
                                                   <tbody>';

														foreach ($data['bill_history'][count($data['bill_history']) -1]->diagnosis_procedures_details as $key => $diagnosis_procedures_details) {
                                                    $abc .= '<tr>
                                                         <td><span style="background: rgb(103, 162, 45) none repeat scroll 0% 0%; border-radius: 106px; padding: 6px 11px; color: rgb(255, 255, 255);">1</span></td>
                                                         <td>'.$diagnosis_procedures_details->date.'</td>
                                                         <td style="line-height:16px;">'.$diagnosis_procedures_details->diagnosis_code.'<br><b>('.$diagnosis_procedures_details->diagnosis_procedure_type.')</b></td>
                                                         <td style="line-height:16px;">'.$diagnosis_procedures_details->billing_code.'</td>
                                                         <td style="line-height:16px;">'.$diagnosis_procedures_details->description.'</td>
                                                         <td>'.$diagnosis_procedures_details->charges.'</td>
                                                      </tr>';
                                                    }

                                              $abc .= '</tbody>
                                                </table>
                                             </td>
                                          </tr>
                                          <tr>
                                             <td align="left" valign="top" style="font-size: 14px; color: rgb(0, 0, 0); font-family: arial; font-weight: bold; padding-top: 17px; float: left; width: 54%;">
                                                <h5 style="text-transform:uppercase;">Additional Notes:</h5>
                                                <textarea style="height: 60px; background: rgb(239, 239, 239) none repeat scroll 0% 0%; border: medium none; float: left; width: 365px; font-weight: normal; font-size: 13px; padding: 5px;">'.$data['bill_history'][count($data['bill_history']) -1]->bill_additional_notes.'</textarea>
                                                <div style="float:left;width:100%;">
                                                   <h5 style="text-transform:uppercase;">Standard Memo:</h5>
                                                   <h5><textarea style="height:60px; background: rgb(239, 239, 239) none repeat scroll 0% 0%; border: medium none; float: left; width: 365px; font-weight: normal; font-size: 13px; padding: 5px;">'.$data['standard_memo'].'</textarea></h5>
                                                </div>
                                             </td>
                                             <td style="padding: 30px 0px; float: left; width: 46%;">
                                                <table cellspacing="0" cellpadding="0" border="0" width="100%" style="padding-left: 24px;" class="invoicetotal">
                                                   <tbody>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;"><strong>Total Charges:</strong></td>
                                                         <td height="20" align="left" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->total_bill_amount.'</td>
                                                      </tr>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;"><strong>Total Discounts:</strong></td>
                                                         <td height="20" align="left" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->total_bill_discount.'</td>
                                                      </tr>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;"><strong>Total:</strong></td>
                                                         <td height="20" align="left" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->total_bill_topay.'</td>
                                                      </tr>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;"><strong>Advance Paid:</strong></td>
                                                         <td height="20" align="left" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->advance_paid.'</td>
                                                      </tr>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;"><strong>Patient Credit:</strong></td>
                                                         <td height="20" align="left" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->patient_credit.'</td>
                                                      </tr>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;"><strong>Total Paid Via Cash</strong></td>
                                                         <td height="20" align="left" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->total_cash_paid.'</td>
                                                      </tr>
                                                      <tr style="background:#67a22d;">
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size: 13px; font-family: arial; padding: 7px; color: rgb(255, 255, 255);padding-left: 38px;"><strong>Total Balance Due:</strong></td>
                                                         <td height="20" align="left" valign="middle" style="font-size: 13px; font-family: arial;color:rgb(255, 255, 255);font-weight:bold;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->total_balance_due.'</td>
                                                      </tr>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">Insurance Balance Due:</td>
                                                         <td height="20" align="left" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->insurance_balance_due.'</td>
                                                      </tr>
                                                      <tr>
                                                         <td height="20" align="left" width="180" valign="middle" style="font-size:13px; color:#000; font-family:arial;padding-left: 38px;">Total Paid Via Credit Card</td>
                                                         <td height="20" align="left" valign="middle" style="font-size: 13px; font-family: arial; padding: 7px; color: rgb(255, 255, 255);padding-left: 38px;">'.$data['bill_history'][count($data['bill_history']) -1]->total_online_paid.'</td>
                                                      </tr>
                                                   </tbody>
                                                </table>
                                             </td>
                                          </tr>
                                       </tbody>
                                    </table>
                                 </td>
                              </tr>
                              <tr>
                                 <td>
                                    <h5 style="text-transform: uppercase; color: rgb(0, 0, 0) !important;">Term And Condition </h5>
                                    <div style="font-size:13px; margin-bottom: 11px; line-height: 20px;">Lorem ipsum dolor sit amet, cinsectetur adipisicing elit, see do aiusmod tempot incididunt utlabore et dolore magna alique. ut enim ad minim veniam, quis mostrud exerctiona ullamco laboris nisi aliquieo</div>
                                 </td>
                              </tr>
                              <tr></tr>
                           </tbody>
                        </table>
                     </td>
                  </tr>
               </tbody>
            </table>
         </div>
      </div>
   </div>
   <div class="modal-footer" style="padding-top: 0px; padding-bottom: 10px; text-align:left;">
      <div class="col-lg-12 text-center">I authorize the release of any medical information necessary to process this claim.</div>
      <div style="padding-top: 15px; clear: both; border-top: 1px solid grey; margin-top: 0px;"><span style="color: rgb(0, 0, 0); font-family: arial; font-weight: bold; font-size: 9px;">This bill is generated with Digital Health Pulse.<br>*Digital Health Pulse(DHP) is online (cloud) based clinical Practice Management product from Sensory Health Systems.Works in offline mode when required.</span><br><span style="font-size:11px; color:#000; font-family:arial; font-weight:bold;">Interested? Call us at or Email us at info@sensoryhealth.com</span></div>
   </div>';

	return $abc;
}