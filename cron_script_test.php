<?php

error_reporting(E_ALL);
//date_default_timezone_set ("Asia/Calcutta");
require_once "../config/config.php";
require_once "../lib/couch.php";
require_once "../lib/couchClient.php";
require_once "../lib/couchDocument.php";
require_once "common_functions.php";

$client                        = new couchClient(COUCH_DSN,COUCH_DB);
$users_client                  = new couchClient(COUCH_DSN,USERS_DB);
$users_personal_details_client = new couchClient(COUCH_DSN,PERSONAL_DETAILS_DB);
$log_client                    = new couchClient(COUCH_DSN,LOGGING_DATABASE);
$doctor_details                = new couchClient(COUCH_DSN,REPLICATED_DB);
$getdoctorDetails              = $doctor_details->getView('SHSViews', 'getDoctorsList');
$sent_mails                    = array();
$sent_sms                      = array();

### GETTING DOCTORS DETAILS   ###
foreach ($getdoctorDetails->rows as $key => $record) {
	$doctorid = $record->id;

	### GETTING COMMUNICATION SETTINGS   ###
	$client->key($record->key[0]);
	$client->reduce(false);
	$client->include_docs(True);
	$commSetting = $client->getView('tamsa', 'getCommunicationSettings');

	if(count($commSetting->rows) > 0) {
		if (isset($commSetting->rows[0]->value->missed_appointment_request_message)) {
			//Send Pre saved message to the patients on missed appointments
			$client->key($record->key[0]);
			$taskManagerSetting = $client->getView('tamsa', 'getTaskManagerSettings');

			if (count($taskManagerSetting->rows) > 0){
				$temp_autoremove_days = $taskManagerSetting->rows[0]->value->request_autoremove_duration;
			}else {
				$temp_autoremove_days = 7;
			}

			$additionnal_parameters = array(
					"startkey"        => array($record->id),
					"endkey"          => array($record->id, array(), array()),
					"include_docs"    => "True",
					"autoremove_days" => $temp_autoremove_days
			);

			$response = $client->getList('tamsa','getMissedAppointments','getRequestList', $additionnal_parameters);

			foreach ($response->rows as $value) {
				$fields_array_mail = array(
					"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
					"to"      => "nimesh.ganatra@tops-int.com",//$value->patient_email,
					"subject" => "Your Apppointment Request to Dr ".$value->doctor_name,
					"text"    => "Hello, ".$value->patient_firstname." ".$value->patient_lastname."\n\n".$commSetting->rows[0]->value->missed_appointment_request_message
				);

				$value->missed_mail_sent = "TRUE";
				//print_r($fields_array_mail);
				//$result_obj              = sendMail($fields_array_mail);

				$client->storeDoc($value);
			}
		}
		//  Email Setting starts...	
		$cdate = date('Y-m-d');
		$cda = "scheduled";
		$review = "Review";
		$doctor_doc = $doctor_details->getdoc($doctorid);
		if($commSetting->rows[0]->doc->email_setting->daily_agenda_mails == 1) {
			$fields_array_mail = array(
				"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
				"to"      => "",
				"subject" => "Daily Agenda Emails",
				"text"    => "Hello, ".$doctor_doc->first_name."\n\n"
			);
			$client->key(array("".$doctorid."","".$cdate."","".$cda.""));
			$client->reduce(FALSE);
			$getAptDetails = $client->getView('tamsa', 'getAppointmentByDate');
			// echo "<pre>";print_r($getAptDetails);echo "<pre>";
			### GETTING APPOINMENT###
			if(count($getAptDetails->rows) > 0) {
				$fields_array_mail["text"] .= "Apppointment Details\n";
				foreach ($getAptDetails->rows as $key => $apt) {
					$users_personal_details_client->key($apt->value->user_id);
					$cpatient                   = $users_personal_details_client->getView('tamsa', 'getPatientInformation'); 
					$fields_array_mail["text"] .= "Timings:".getGmtStringToIstTime($apt->value->reminder_start)." to  ".getGmtStringToIstTime($apt->value->reminder_end)."\n";
					$fields_array_mail["text"] .= "\nPatient Deatails:\n";
	
					### GETTING PATIENTS ###
					if(count($cpatient->rows) > 0) {
						foreach ($cpatient->rows as $key => $cpat) {
							$fields_array_mail["text"] .= "Name : ".$cpat->value->first_nm." ".$cpat->value->last_nm."\nPhone No : ".$cpat->value->phone."\nEmail Id :".$cpat->value->user_email."\nCity :".$cpat->value->city."\n\n";
						}
					}
				}
				
				if($commSetting->rows[0]->value->email_setting->include_notes_in_emails == 1) {
					$fields_array_mail["text"] .= "\n\n".$apt->value->reminder_note;
				}
			}
				$client->startkey(array("".$doctorid."","".$review.""));
        $client->endkey(array("".$doctorid."","".$review."",array(),array()));
        $client->reduce(TRUE);
        $client->group(TRUE);
				$taskInformation = $client->getView('tamsa','getDueTasksCount');
				if(count($taskInformation->rows) > 0) {
				$fields_array_mail["text"] .= "\nTask Details";
					foreach ($taskInformation->rows as $key => $apt) {
						$fields_array_mail["text"] .= "\n\n".$apt->value." Task Pending \n".$apt->value." ".$apt->key[2]."\n";
					}	
				}
				$client->key($record->key[0]);
				$client->include_docs(TRUE);
				$RequestInfo = $client->getView('tamsa','getTaskManagerSettings');
				if(count($RequestInfo->rows) > 0){
					$autoremove_days = $RequestInfo->rows[0]->value->request_autoremove_duration;
				}else{
					$autoremove_days = 7;
				}
				$additionnal_parameters = array(
					"startkey"        => array($record->id),
					"endkey"          => array($record->id, array(), array()),
					"include_docs"    => "True",
					"autoremove_days" => $autoremove_days
				);
				$response = $client->getList('tamsa','getLatestRequestList','getRequestList', $additionnal_parameters);
				//echo "<pre>";print_r($response);echo "<pre>";
				if(count($response->rows) > 0) {
					$fields_array_mail["text"] .= "\nRequest Are Pending\n";
					$sub_request_count = 0;
	       	$app_request_count = 0;
	        for($i=0;$i<count($response->rows);$i++){
	          if($response->rows[$i]->key[2] == "appointment_request"){
	            $app_request_count++;
	          }else{
	            $sub_request_count++;
	          }
	        }
	        $fields_array_mail["text"] .= $sub_request_count." Subscription Requests\n".$app_request_count." Appointment Requests\n";
	      }  
				if($fields_array_mail["text"] == "") {
					$fields_array_mail["text"] .= "You have no daily agenda.";
				}
				print_r($doctorid);
				if($doctorid == "org.couchdb.user:n@n.com"){
					$fields_array_mail["to"] .= "tejas.girase@tops-int.com";
					$result_obj = sendMail($fields_array_mail);
				}
				echo "<pre>";print_r($fields_array_mail);echo "<pre>";
			//$result_obj = sendMail($fields_array_mail);
		}

		// if($commSetting->rows[0]->doc->email_setting->daily_billing_problems_mails == 1) {
		// 	$fields_array_mail = array(
		// 		"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
		// 		"to"      => $doctor_doc->alert_email,			
		// 		"subject" => "Daily Billing Problems Emails",		
		// 		"text"    => "Hello, ".$doctor_doc->first_name."\n\nsend a daily billing problems email ."
		// 	);

		// 	if($commSetting->rows[0]->doc->email_setting->include_notes_in_emails == 1) {
		// 		$fields_array_mail["text"] .= "\n\nNotes Included.";
		// 	}
		// 	print_r($fields_array_mail);
		// 	//$result_obj = sendMail($fields_array_mail);
		// }

		// if($commSetting->rows[0]->doc->email_setting->online_scheduling_emails == 1) {
		// 	//$appointment = $client->getView('tamsa', 'getAppointmentByDate');
		// 	//print_r($appointment);
		// 	$fields_array_mail = array(
		// 		"from"    => "Sensory Health Systems Admin <noreply@sensoryhealthsystems.com>",
		// 		"to"      => $doctor_doc->alert_email,					
		// 		"subject" => "Online Apppointment Scheduling Emails",		
		// 		"text"    => "Hello, ".$doctor_doc->first_name."\n\nsend an email each time an appointment is schedule online."
		// 	);
		// 	if($commSetting->rows[0]->doc->email_setting->include_notes_in_emails == 1) {
		// 		$fields_array_mail["text"] .= "\n\nNotes Included.";
		// 	}
		// 	print_r($fields_array_mail);
		// 	//$result_obj = sendMail($fields_array_mail);		
		// }
	}
}
	
function getGmtStringToIstTime($gmt_string) {
//	echo $gmt_string;
	$timezone = new DateTimeZone('GMT');

	$date     = DateTime::createFromFormat('M j Y H:i:s', substr($gmt_string, 4, 20), $timezone);
	$date->setTimeZone(new DateTimeZone('Asia/Calcutta'));
	$triggerOn =  $date->format('Y-m-d H:i:s');

	return $triggerOn; // echoes 2013-04-01 22:08:00 	
}
