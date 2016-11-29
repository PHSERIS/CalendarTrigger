<?php
/**
 * This is the Common class for the Calendar Data Entry Trigger (DET)
 * This plugin is desinged to work with REDCap 5.9.14 LTS and above
 * © 2014 Partners HealthCare System, Inc. All Rights Reserved.
 * 
 * Special thanks to Andy Martin for some of the design inspiration
 * @author Dimitar Dimitrov
 * @date August 6, 2014
 */
error_reporting(E_ALL);
require_once 'common_static_functions.php';


/**
 * Common class for the Calendar triggers plugin
 */
class CalTrigger {
    
    private $config, $config_encoded; // configuration is stored in json-encoded form
    
    private $project_id, $instrument, $record, $redcap_event_name, $event_id, $redcap_data_access_group, $instrument_complete;
    
    public function getProject_id() {
	return $this->project_id;
    }

    public function setProject_id($project_id) {
	$this->project_id = $project_id;
    }

        public $another_trigger_exists = false;
    
    private $Proj;
    
    function __construct() {
	global $Proj;
	global $data_entry_trigger_url;
	$params = array();
	$this->project_id = $Proj->project_id;
	$query = parse_url($data_entry_trigger_url, PHP_URL_QUERY);
	if ($query) parse_str($query,$params);
	$this->config_encoded = $params['ct'];	
	if(!isset($params['ct']) && strlen($data_entry_trigger_url)>1) $this->another_trigger_exists = true;
	$this->config = isset($this->config_encoded) ? $this->decrypt($this->config_encoded) : '';
    }
    
    public function loadRecordDetails () {
	global $Proj;
	$this->project_id = $this->from_request('project_id');
	$this->instrument = $this->from_request('instrument');
	$this->record = $this->from_request('record');
	$this->redcap_event_name = $this->from_request('redcap_event_name');
	if (REDCap::isLongitudinal()) {
	    $events = REDCap::getEventNames(true,false);
	    $this->event_id = array_search($this->redcap_event_name, $events);
	}
	else {
	    $events =  $Proj->events;
	    $events = array_shift($events);
	    $events = $events['events'];
	    if(count($events)==1) {
		foreach ( array_keys($events) as $key ) {
		    $this->event_id = $key;
		}
	    }	    
	}
	$this->redcap_data_access_group = $this->from_request('redcap_data_access_group');
	$this->instrument_complete = $this->from_request($this->instrument.'_complete');
	$this->log_event('Loading Record Details for DET ... ');
	$this->log_event('ProjectID='.$this->project_id);
	$this->log_event('Instrument='.$this->instrument);
	$this->log_event('Record='.$this->record);
	$this->log_event('Event Name='.$this->redcap_event_name);
	$this->log_event('EventID='.$this->event_id);
	$this->log_event('Instument Complete='.$this->instrument_complete); 
	$this->log_event('DONE Loading DET Details ... about to execute the trigger');
    }
    
    // Load a stored trigger
    // Triggers will be stored in the "ct" GET / REQUEST parameter
    public function executeCTTrigger() {
	global $Proj;
	// Get the record data so we can retrieve the information	
	$record_data_original = Records::getData($this->project_id, 'array', $this->record); //Records::getData('array', $this->record); 
	$fields_metadata = $Proj->metadata;
	
	if (REDCap::isLongitudinal()) {
	    $record_data = $record_data_original[$this->record][$this->event_id]; // get the data for the specific Event ID
	}
	else {
	    $record_data = array_shift ($record_data_original[$this->record]);
	}
	
	// Loop through the triggers
	if (isset($this->config) && is_array($this->config)) { 
	    foreach ( array_keys($this->config) as $key ) {
		if($key == 'last_saved') continue;
		if($key == 'modified_by') continue;
		
		$trigger_data = $this->config[$key];
		if($trigger_data['enabled'] == true) {
		    $this->log_event('Executing trigger: '.$trigger_data['title']);
		    // Check to see if the instrument matches the current instument
		    if($trigger_data['form'] == $this->instrument) {
			// See if the form is completed
			if($this->instrument_complete == 2) {
			    $date_field_value = '';
			    $time_field_value = '';
			    if(isset($record_data[$trigger_data['date']])) $date_field_value = $record_data[$trigger_data['date']];
			    if(isset($record_data[$trigger_data['time']])) $time_field_value = $record_data[$trigger_data['time']];
			    
			    // Get the pipe data
			    $piped_text = Piping::replaceVariablesInLabel(
				$trigger_data['note'], 
				$this->record, 
				$this->event_id,
				$record_data_original,
				true,
				$this->project_id
			    );
			    $piped_text = strip_tags($piped_text);
			    
			    $previous_header = self::find_preceding_header($fields_metadata, $trigger_data['date']);				
			    
			    if(isset($fields_metadata) && isset($fields_metadata[$trigger_data['date']]['element_label']))
				$piped_text = $previous_header . ' ' . $fields_metadata[$trigger_data['date']]['element_label'] . '</br>' . $piped_text;
			    
			    // Check to see if a record exists already
			    $sql_lookup = "SELECT count(*) as cnt FROM redcap_events_calendar "
				    . "WHERE "
				    . "event_id = '".prep($this->event_id)."' "
				    . "AND project_id = ".prep($this->project_id)." "
				    . "AND record = '".prep($this->record)."' "
				    . "AND extra_notes = '".prep($fields_metadata[$trigger_data['date']]['element_label'])."'";
			    $q_r = db_query ( $sql_lookup );
			    $num_matches = 0;
			    while ( $row = db_fetch_assoc ( $q_r ) ) {
				$num_matches = $row['cnt'];
			    }
			    
			    if(isset($date_field_value) && strlen($date_field_value)>1) {
				if ( $num_matches > 0 ) {
				    // Update
				    $update_sql = "UPDATE redcap_events_calendar "
					    . "SET "
					    . "event_date = '".prep($date_field_value)."', "
					    . "event_time = '".prep($time_field_value)."', "
					    . "extra_notes = '".prep($fields_metadata[$trigger_data['date']]['element_label'])."', "
					    . "notes = '".prep($piped_text)."' "
					    . "WHERE "
					    . "event_id = '".prep($this->event_id)."' "
					    . "AND project_id = ".prep($this->project_id)." "
					    . "AND record = '".prep($this->record)."' "
					    . "AND extra_notes = '".prep($fields_metadata[$trigger_data['date']]['element_label'])."'";
				    if (db_query($update_sql)) {
					$this->log_event('Trigger: '.$trigger_data['title'].' update executed successfully!'.$update_sql);
				    }
				    else {
					$this->log_event('Trigger: '.$trigger_data['title'].' FAILED TO EXECUTE - couldn\'t insert new record: '.$update_sql);
				    }
				}
				else {
				    // Insert the record in the redcap_events_calendar table
				    $sql = "insert into redcap_events_calendar 
					(record, project_id, group_id, event_id, event_date, event_time,event_status, extra_notes, notes) 
					values 
					('".prep($this->record)."', "
					    . "$this->project_id, "
					    . "" . checkNull($this->redcap_data_access_group) . ", "
					    . "'".prep($this->event_id)."',"
					    . "'".prep($date_field_value)."', "
					    . "'".prep($time_field_value)."', "
					    . "0, "
					    . "'".prep($fields_metadata[$trigger_data['date']]['element_label'])."', "
					    . "'".prep($piped_text)."');";
				    //$this->log_event('SQL='.$sql);
				    if (db_query($sql)) {
					$this->log_event('Trigger: '.$trigger_data['title'].' executed successfully!');
				    }
				    else {
					$this->log_event('Trigger: '.$trigger_data['title'].' FAILED TO EXECUTE - couldn\'t insert new record: '.$sql);
				    }
				}				
			    }
			    else {
				if ( $num_matches > 0 ) {
				    // Update and set that field to null in case it was there
				    $update_sql = "UPDATE redcap_events_calendar "
					    . "SET "
					    . "event_date = NULL, "
					    . "event_time = NULL, "
					    . "extra_notes = '".prep($fields_metadata[$trigger_data['date']]['element_label'])."', "
					    . "notes = '".prep($piped_text)."' "
					    . "WHERE "
					    . "event_id = '".prep($this->event_id)."' "
					    . "AND project_id = ".prep($this->project_id)." "
					    . "AND record = '".prep($this->record)."' "
					    . "AND extra_notes = '".prep($fields_metadata[$trigger_data['date']]['element_label'])."'";
				    if (db_query($update_sql)) {
					$this->log_event('Trigger: '.$trigger_data['title'].' update executed successfully!'.$update_sql);
				    }
				    else {
					$this->log_event('Trigger: '.$trigger_data['title'].' FAILED TO EXECUTE - couldn\'t insert new record: '.$update_sql);
				    }
				}
				else {
				    $this->log_event('Trigger: '.$trigger_data['title'].' FAILED TO EXECUTE - date field is empty!');
				}
			    }
			}
		    }
		    else {
			$this->log_event('Trigger: '.$trigger_data['title'].' will not execute - not the right instrument!');
		    }
		}
		else {
		    $this->log_event('Trigger: '.$trigger_data['title'].' is disabled - will not execute!');
		}
	    }
	}	
    }
    
    public function saveCTChanges($triggers) {
	global $data_entry_trigger_url;
	$encoded_data = $this->encode($triggers);
	// Create a  URL:
	$base = 'https://'.$_SERVER['SERVER_NAME'];
	$parse_url = parse_url($_SERVER['PHP_SELF']);
	$path = dirname($parse_url['path']);
	$data_entry_trigger_url = $base . $path . '/index.php?ct=' . $encoded_data;
	$sql = "update redcap_projects set data_entry_trigger_url = '".prep($data_entry_trigger_url)."' where project_id = " . PROJECT_ID . " LIMIT 1;";
	$q = db_query($sql);
	//print	 $sql.'<p/>';
    }
    
    public function renderAllTriggers() {
	$html = RCView::div(array('id'=>'remeber_to_save','class'=>'green','style'=>'margin-top:20px;padding:10px 10px 15px; display: none'),
		RCView::div(array(), 'Please remember to hit "Save Configuration" in order to save the changes')
	);
	$html .= "<div id='triggers_config'>";
	// button to add another calendar trigger
	$html .= RCView::button(array('class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only',
	    'onclick'=>'javascript:addTrigger();'), RCView::img(array('src'=>'add.png', 'class'=>'imgfix')).' Add another calendar trigger');

	// and the button to save the configuration
	$html .= RCView::button(array('class'=>'jqbuttonmed ui-button ui-widget ui-state-default ui-corner-all ui-button-text-only',
	    'onclick'=>'javascript:save();'), RCView::img(array('src'=>'bullet_disk.png', 'class'=>'imgfix')).'<b>Save Configuration</b>');

	$html .= RCView::h2(array(), '<br/>*NOTE: Triggers will be executed only when records are marked as "Completed"');
	
	if (isset($this->config) && is_array($this->config)) {
	    $i = 1;
	    foreach ( array_keys($this->config) as $key ) {
		if($key == 'last_saved') continue;
		if($key == 'modified_by') continue;
		
		$trigger_data = $this->config[$key];
		$html .= self::renderTrigger($i, $trigger_data['title'], $trigger_data['form'], $trigger_data['date'], $trigger_data['time'], 
			$trigger_data['note'], ($trigger_data['enabled']==true ? 1 : 0) );
		$i++;
	    }
	} else {
	    $html .= self::renderTrigger(1);
	}

	$html .= "</div>";
	return $html;
    }
    
    /**
     * This function renders the trigger. It uses the RCView redcap class to render the HTML
     * @param type $ct_id - required
     * @param type $title - title
     * @param type $date_field - date field
     * @param type $time_field - (optional) time field
     * @param type $note_field - note field that can have Piped data in it
     * @param type $enabled - 1 or 0 - enabled / disabled
     */
    public function renderTrigger($ct_id, $title = '', $form_name = '', $date_field = '', $time_field = '', $note_text = '', $enabled = 1) {
	$html = RCView::div(array('class'=>'round chklist trigger','idx'=>"$ct_id"),
		RCView::div(array('class'=>'chklisthdr', 'style'=>'color:rgb(0,128,0); margin-bottom:5px; padding-bottom:5px; border-bottom:1px solid #AAA;'), "Calendar Trigger $ct_id: $title".
				RCView::a(array('href'=>'javascript:','onclick'=>"removeTrigger('$ct_id')"), RCView::img(array('style'=>'float:right;padding-top:0px;', 'src'=>'cross.png')))
			).
			RCView::table(array('cellspacing'=>'3', 'class'=>'tbi'),
				self::renderRow('title-'.$ct_id,'Trigger Title',$title, 'title').
				self::renderFormSelectionRow($ct_id,'Form Name',$form_name, 'form_name').
				self::renderDateField($ct_id,'Date Field', $form_name, $date_field, 'date_field').
				self::renderTimeField($ct_id,'Time Field (optional)', $form_name, $time_field, 'time_field').
				self::renderNoteField($ct_id,'Calendar Note', $note_text, 'note_text').
				self::renderEnabledRow($ct_id,'<nobr>Trigger Status</nobr>', $enabled)
			)
		);
	return $html;
    }
    
    // Render a general row
    public function renderRow($ct_id, $label, $value, $help_div_id = null) {
	$help_div_id = ( $help_div_id ? $help_div_id : $ct_id);
	$row = RCView::tr(array(),
		RCView::td(array('class'=>'td1'), self::insertHelp($help_div_id)).
		RCView::td(array('class'=>'td2'), "<label for='$ct_id'><b>$label:</b></label>").
		RCView::td(array('class'=>'td3'),
			RCView::input(array('class'=>'tbi x-form-text x-form-field','id'=>$ct_id,'name'=>$ct_id.'_'.$help_div_id,'value'=>$value,
			    'style'=>'height:30px;border:1px;width:300px;text-align: center;'))
		)		
	);
	
	return $row;
    }
    
    // Render a form-selection row from a drop-down list
    public function renderFormSelectionRow($ct_id, $label, $value, $help_div_id = null) {
	global $Proj;
	
	// Get all of the defined forms
	$forms = $Proj->forms;	
	$select_options = array();
	$select_options['no_choice_made_yet'] = '-- select a form --';
	foreach(array_keys($forms) as $form_name) {	    
	    $select_options[$form_name] = $forms[$form_name]['menu'];	    
	}
	$row = RCView::tr(array(),
		    RCView::td(array('class'=>'td1'), self::insertHelp($help_div_id)). // The help button
		    RCView::td(array('class'=>'td2'), "<label for='select-form-$ct_id'><b>Form:</b></label>").
		    RCView::td(array('class'=>'td3'),
			    RCView::select(array('id'=>"select-form-$ct_id", 'name'=>"select-form-$ct_id", 'class'=>"tbi x-form-text x-form-field", 
				    'style'=>'height:30px;border:1px;width:300px;text-align: center;', 
				), $select_options, $value)
		    )
	);
	
	// add the jquery script for the date field
	$jQuery = "<script type='text/javascript'> $(document).ready(function()".
			"{ $('#select-form-$ct_id').change(function() ".
			"{ $.ajax(".
			"{ ".
				"url: \"ajax_date_fields.php\",".
				"data: {pid: \"".$Proj->project_id."\", fn: $('#select-form-$ct_id').val(),ct_id: \"".$ct_id."\"},".
				"success:function(result)".				
			"{ $(\"#date-field-$ct_id\").replaceWith(result); ".
			//"{ alert(result);".
			"}});".
			"})".
			"});".
			"</script>\n";	
	// add the jQuery script for the time field
	$jQuery .= "<script type='text/javascript'> $(document).ready(function()".
			"{ $('#select-form-$ct_id').change(function() ".
			"{ $.ajax(".
			"{ ".
				"url: \"ajax_time_fields.php\",".
				"data: {pid: \"".$Proj->project_id."\", fn: $('#select-form-$ct_id').val(),ct_id: \"".$ct_id."\"},".
				"success:function(result)".				
			"{ $(\"#time-field-$ct_id\").replaceWith(result); ".
			//"{ alert(result);".
			"}});".
			"})".
			"});".
			"</script>\n";
	
	return $row.$jQuery; // and put the jQuery for this dropdown
    }
    
    // Render a date field based on the selected form field - this will be ajax-dependent on the FormSelectRow
    public function renderDateField($ct_id, $label, $selected_form = null, $date_field, $help_div_id = null) {
	global $Proj;	
	
	// get the field names depending on the selected form
	$field_names = $Proj->forms[$selected_form]['fields'];	
	$field_metadata = $Proj->metadata; // ALWAYS sorted by "Field_Order"
	$select_options = array();
	$select_options['no_choice_made_yet'] = '-- select a date field --';
	// create the select options	
	foreach(array_keys($field_names) as $field_name) {
	    if(isset($field_metadata[$field_name]['element_preceding_header']) && 
		    strlen(trim($field_metadata[$field_name]['element_preceding_header']))>0) 
		$element_preceding_header = strip_tags (trim($field_metadata[$field_name]['element_preceding_header']));
	    if(strpos($field_metadata[$field_name]['element_validation_type'],'date') === false) continue;
	    if(strpos($field_metadata[$field_name]['element_validation_type'],'date') == 0)
		$select_options[
		    (isset($element_preceding_header) && strlen(trim($element_preceding_header))>0) ? $element_preceding_header : ''
		    ][$field_name] = $field_names[$field_name]; // only add fields that validate "date******" values
	}
	$row = RCView::tr(array(),
		    RCView::td(array('class'=>'td1'), self::insertHelp($help_div_id)). // The help button
		    RCView::td(array('class'=>'td2'), "<label for='date-field-$ct_id'><b>Date Field:</b></label>").
		    RCView::td(array('class'=>'td3'),
			    RCView::select(array('id'=>"date-field-$ct_id", 'name'=>"date-field-$ct_id", 'class'=>"tbi x-form-text x-form-field", 
				    'style'=>'height:30px;border:1px;width:300px;text-align: center;'), $select_options, $date_field)
		    )
	);
	
	return $row;
    }
    
    // Render the optional time field - ajax dependent on the FormSelectRow
    public function renderTimeField($ct_id, $label, $selected_form = null, $time_field, $help_div_id = null) {
	global $Proj;	
	
	//$selected_form = 'my_first_instrument'; // FOR TESTING
		
	// get the field names depending on the selected form
	$field_names = $Proj->forms[$selected_form]['fields'];	
	$field_metadata = $Proj->metadata;
	$select_options = array();
	$select_options['no_choice_made_yet'] = '-- select a time field (optional) --';
	// create the select options
	foreach(array_keys($field_names) as $field_name) {
	    if(isset($field_metadata[$field_name]['element_preceding_header']) && 
		    strlen(trim($field_metadata[$field_name]['element_preceding_header']))>0) 
		$element_preceding_header = strip_tags (trim($field_metadata[$field_name]['element_preceding_header']));
	    if(strpos($field_metadata[$field_name]['element_validation_type'],'time') === false) continue;
	    if(strpos($field_metadata[$field_name]['element_validation_type'],'time') == 0)
		$select_options[
		    (isset($element_preceding_header) && strlen(trim($element_preceding_header))>0) ? $element_preceding_header : ''
		][$field_name] = $field_names[$field_name]; // only add fields that validate "date******" values
	}
	$row = RCView::tr(array(),
		    RCView::td(array('class'=>'td1'), self::insertHelp($help_div_id)). // The help button
		    RCView::td(array('class'=>'td2'), "<label for='time-field-$ct_id'><b>Time Field:</b></label>").
		    RCView::td(array('class'=>'td3'),
			    RCView::select(array('id'=>"time-field-$ct_id", 'name'=>"time-form-$ct_id", 'class'=>"tbi x-form-text x-form-field", 
				    'style'=>'height:30px;border:1px;width:300px;text-align: center;'), $select_options, $time_field)
		    )
	);
	
	return $row;
    }
    
    // Render Note field  - this accepts Piped data when triggered
    public function renderNoteField($ct_id, $label, $note_text, $help_div_id = null) {
	global $Proj;
	
	$row = RCView::tr(array(), 
		    RCView::td(array('class' => 'td1'), self::insertHelp($help_div_id)).
		    RCView::td(array('class' => 'td2'), "<label for='note-field-$ct_id'><b>Calendar Note:</b></label>").
		    RCView::td(array('class' => 'td3'), 
			    RCView::textarea(array('class'=>'tbi x-form-text x-form-field','id'=>"note-field-$ct_id",
				'name'=>"note-field-$ct_id", 'style' => 'width: 100%;height: 80px;'), $note_text)
		    )
	);
	return $row;
    }
    
    // Renders the enabled row (thanks Andy)
    public function renderEnabledRow($ct_id, $label, $value) {
	    //error_log('ID:'.$ct_id.' and VALUE:'.$value);
	    $enabledChecked = ($value == 1 ? 'checked' : '');
	    $disabledChecked = ($value == 1 ? '' : 'checked');
	    $row = RCView::tr(array(),
		    RCView::td(array('class'=>'td1'), self::insertHelp('enabled')).
		    RCView::td(array('class'=>'td2'), "<label for='logic-$ct_id'><b>$label:</b></label>").
		    RCView::td(array('class'=>'td3'),
			    RCView::span(array(),
				    RCView::radio(array('id' => "enabled-$ct_id",'name'=>"enabled-$ct_id",'value'=>'1',$enabledChecked=>$enabledChecked)). 
				    'Enabled' . RCView::SP . RCView::SP .
				    RCView::radio(array('id' => "disabled-$ct_id",'name'=>"enabled-$ct_id",'value'=>'0',$disabledChecked=>$disabledChecked)). 
				    'Disabled'
			    )
		    )
	    );
	    return $row;
    }
    
    
    // Insert help (thanks Andy)
    public function insertHelp($e) {
	    return "<span><a href='javascript:;' id='".$e."_info_trigger' info='".$e."_info' class='info' title='Click for help'><img class='imgfix' style='height: 16px; width: 16px;' src='".APP_PATH_IMAGES."help.png'></a></span>";
    }
    
    function renderTemporaryMessage($msg, $title='',$type='green') {
	$id = uniqid();
	$html = RCView::div(array('id'=>$id,'class'=>$type,'style'=>'margin-top:20px;padding:10px 10px 15px;'),
		RCView::div(array('style'=>'text-align:center;font-size:20px;font-weight:bold;padding-bottom:5px;'), $title).
		RCView::div(array(), $msg)
	);
	$js = "<script type='text/javascript'>
	$(function(){
		t".$id." = setTimeout(function(){
			$('#".$id."').hide('blind',1500);
		},10000);
		$('#".$id."').bind( 'click', function() { 
			$(this).hide('blind',1000);
			window.clearTimeout(t".$id.");
		});
	});
	</script>";
	echo $html . $js;
    }

    // This updates the trigger in the redcap_projects database table
    // TODO: What if we want to have more than one data trigger (ex. AutoNotify AND Calendar Trigger)?? They will step on each-other or overwrite each-other?!?!
    public function updateCalDetUrl($an) {
	    global $data_entry_trigger_url;
	    // Create a DET URL:
	    $base = 'https://'.$_SERVER['SERVER_NAME'];
	    $parse_url = parse_url($_SERVER['PHP_SELF']);
	    $path = dirname($parse_url['path']);
	    $data_entry_trigger_url = $base . $path . '/?ct=' . $an;
	    $sql = "update redcap_projects set data_entry_trigger_url = '".prep($data_entry_trigger_url)."' where project_id = " . PROJECT_ID . " LIMIT 1;";
	    $q = db_query($sql);
    }

    // Takes an encoded string and returns the array representation of the object
    public function decrypt($code) {
	    $template_enc = rawurldecode($code);
	    $json = decrypt_static($template_enc);	//json string representation of parameters
	    $params = json_decode($json, true);	//array representation
		if ( is_null ($params) ) {
                        // try decrypting with the 6.x.x version function
                        $json = decrypt ( $template_enc );
                        $params = json_decode($json,true);
                }
	    return $params;
    }

    // Takes an array and returns the encoded string
    public function encode($params) {
	    $json = json_encode($params);
	    $encoded = encrypt_static($json); // This is a REDCap function
	    return rawurlencode($encoded);
    }
    
    // Render the helpful divs (these are hidden until called to be displayed as modal windows)
    // TODO
    public function renderHelpDivs() {
	    $help = RCView::div(array('id'=>'title_info','style'=>'display:none;'),
		    RCView::p(array(),'Specifies the title that will be displayed above this trigger. This title will help you easily spot/identify a specific calendar trigger that has been saved in the system.')
	    ).
	    RCView::div(array('id'=>'form_name_info','style'=>'display:none;'),
		    RCView::p(array(),'Select the FORM name from the project\'s Collection Instruments. You can see a complete list of these instuments in the Project Setup -> Online Designer section. '
			    . 'This form will contain the specific date field you are looking for.')
	    ).
	    RCView::div(array('id'=>'date_field_info','style'=>'display:none;'),
		    RCView::p(array(),'Select the name of the Date Field that specifies the date. This depends on the "Form" field above and will be auto-populated once a form has been selected from that field.')
	    ).RCView::div(array('id'=>'time_field_info','style'=>'display:none;'),
		    RCView::p(array(),'This is an optional field that specifies the Time when this calendar entry should be created. This depends on the "Form" field above and will be auto-populated once a form has been selected from that field.')
	    ).RCView::div(array('id'=>'note_text_info','style'=>'display:none;'),
		    RCView::p(array(),'Specifies the text that will appear in the calendar entry. This field supports data piping. '
			    . '<a href="javascript:;" style="font-size:11px;color:#3E72A8;text-decoration:underline;" onclick="pipingExplanation();">How to use Piping in the survey invitation</a>')
	    ).RCView::div(array('id'=>'enabled_info','style'=>'display:none;'),
		    RCView::p(array(),'Specifies whether this trigger is enabled or disabled.')
	    );

	    echo $help;
    }
    
    function from_request($var) {
	$result = isset($_REQUEST[$var]) ? $_REQUEST[$var] : "";
	return $result;
    }
    
    public function log_event($msg, $level = "INFO") {
	global $log_prefix;
	file_put_contents( $log_prefix . "-" . date( 'Y-m' ) . ".log",	date( 'Y-m-d H:i:s' ) . "\t" . $level . "\t" . $msg . "\n", FILE_APPEND );
    }
    
    public function find_preceding_header($metadata, $var_name) {
	try {
	    $element_preceding_header = '';
	    foreach(array_keys($metadata) as $field_name) {
		if(isset($metadata[$field_name]['element_preceding_header']) && 
			strlen(trim($metadata[$field_name]['element_preceding_header']))>0) 
		    $element_preceding_header = strip_tags (trim($metadata[$field_name]['element_preceding_header']));
		if($var_name == $field_name && strlen($element_preceding_header)>0) return $element_preceding_header.' - ';
	    }
	    return '';
	}
	catch ( Exception $e ) {
	    return ''; // error, but I don't think we care
	}
    }
}

?>
