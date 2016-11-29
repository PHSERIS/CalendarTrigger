<?php
##
# © 2014 Partners HealthCare System, Inc. All Rights Reserved. 
# author: Dimitar Dimitrov
# plugin: Calendar Trigger
##

// Call the REDCap Connect file in the main "redcap" directory
require_once "../../redcap_connect.php";
include_once 'cal_common.php';

// CHANGE THIS TO THE NAME OF THE FIRST FORM
$select_options = array();
$select_options['no_choice_made_yet'] = '-- No Time Fields Found --';

$no_data_return = RCView::select(array('id'=>"time-field-".$_GET['ct_id'], 'name'=>"time-field-".$_GET['ct_id'], 'class'=>"tbi x-form-text x-form-field", 
			    'style'=>'height:30px;border:1px;width:300px;text-align: center;'), $select_options, '');
	
// If the Form Name or the PID are not set, then we don't know what to do
if(!isset($_GET['pid'])) {
        echo $no_data_return;
	return;
}
if(!isset($_GET['fn'])) {
        echo $no_data_return;
	return;
}
if(!isset($_GET['ct_id'])) {
        echo $no_data_return;
	return;
}

$form_name = $_GET['fn'];
$ct_id = $_GET['ct_id'];

$field_names = $Proj->forms[$form_name]['fields'];
$field_metadata = $Proj->metadata;

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
$row = RCView::select(array('id'=>"time-field-$ct_id", 'name'=>"time-field-$ct_id", 'class'=>"tbi x-form-text x-form-field", 
			    'style'=>'height:30px;border:1px;width:300px;text-align: center;'), $select_options, '');
echo $row;
