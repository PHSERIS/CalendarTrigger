<?php

// Include required files

error_reporting(E_ALL);
$log_prefix = "/var/www/html/redcap/cal_trigger_log/ct_log";

$action = '';	// Script action
# Running as DET
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_GET['ct'])) {
	$action = 'det';
	define('NOAUTH',true);
	$_GET['pid'] = $_POST['project_id'];
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['addTrigger'])) {
    $_GET['pid'] = $_POST['project_id'];
}

require_once "../../redcap_connect.php";
require_once "cal_common.php";

$calT = new CalTrigger();

# Running as DET
if ($action == 'det') {
    $calT->loadRecordDetails();
    $calT->executeCTTrigger();
    exit;
}

if (isset($_POST['addTrigger'])) {
	$index = $_POST['addTrigger'] + 1;
	print $calT::renderTrigger($index);
	exit;
}

require_once APP_PATH_DOCROOT . 'ProjectGeneral/header.php';

if (isset($_POST['save']) && $_POST['save']) {
    if(isset($_POST['triggers'])) {
	unset($params['save']);
	$triggers = json_decode($_POST['triggers'], true);
	$triggers['last_saved'] = date('y-m-d h:i:s');
	$triggers['modified_by'] = USERID;
	$calT->saveCTChanges($triggers);
	$calT->renderTemporaryMessage('Changes Saved!','','green');
	unset($calT);
	$calT = new CalTrigger();
    }
    else {
	$calT->renderTemporaryMessage('Could not save the changes!','','red');
    }
}

if($calT->another_trigger_exists) {
    $html = RCView::div(array('id'=>$id,'class'=>'red','style'=>'margin-top:20px;padding:10px 10px 15px;'),
	RCView::div(array('style'=>'text-align:center;font-size:20px;font-weight:bold;padding-bottom:5px;'), "Warning: Existing DET Defined").
	RCView::div(array(), "A data entry trigger has already been defined for this project: <b>$data_entry_trigger_url</b>"
		. "<br>If you save this Calendar Trigger configuration you will replace this DET!")
    );
    print $html;
}

print $calT->renderAllTriggers();
print $calT->renderHelpDivs();


?>

<?php if ($isIE && vIE() <= 8) { ?><script type="text/javascript" src="json2.js"></script><?php } ?>

<script type="text/javascript">
	function refresh() {
		window.location = window.location.href;
	}
	
	function addTrigger() {
	    max = 0;
	    $('div.trigger', '#triggers_config').each(
		    function (index,value) {
			    idx = $(this).attr('idx');
			    if ($(this).attr('idx') > max) {
				    max = $(this).attr('idx');
			    };
		    }
	    );
	    $.post('index.php',{addTrigger: max, project_id: pid, pid: pid },
		    function(data) {
			    $('#triggers_config').append(data);
			    updatePage();
		    }
	    );
	    $('#remeber_to_save').css('display','block');
	}

	function removeTrigger(id) {
	    //console.log('Remove Trigger');
	    if ($('div.trigger').length == 1) {
		    alert ('You can not delete the last trigger.');
	    } else {
		    $('div.trigger[idx='+id+']').remove();
		    $('#remeber_to_save').css('display','block');
	    }
	}
	
	function updatePage() {
		// Prepare help buttons
		$('a.info').off('click').click(function(){
			var e = $(this).attr("info");
			$('#'+e).dialog({ title: 'AutoNotification Help', bgiframe: true, modal: true, width: 400, 
				open: function(){fitDialog(this)}, 
				buttons: { Close: function() { $(this).dialog('close'); } } });
		});		
		$('#title').css('font-weight','bold');
	}
	
	function save() {
	    // get all of the trigger divs  
	    i = 1;
	    params = new Object;
	    triggers = new Object;
	    $('div.trigger').each(function ( index, element) {
		triggers[i] = new Object;
		// get the Title
		triggers[i]['title'] = $('#title-'+i).val();
		// Get the Form name
		triggers[i]['form'] = $('#select-form-'+i).val();
		// Get the Date Field
		triggers[i]['date'] = $('#date-field-'+i).val();
		// Get the Time Field
		triggers[i]['time'] = $('#time-field-'+i).val();
		// Get the Calendar Note
		triggers[i]['note'] = $('#note-field-'+i).val();
		// Get the trigger status
		triggers[i]['enabled'] = $('#enabled-'+i).is(':checked');
		
		i++;
	    });
	    
	    params['triggers'] = JSON.stringify(triggers);
	    
	    params['save'] = 1;
            post('', params);
	}
	
	function post(path, params) {
		var form = $('<form></form>');
		form.attr("method", "POST");
		form.attr("action", path);
		$.each(params, function(key, value) {
			var field = $('<input />');
			field.attr("type", "hidden");
			field.attr("name", key);
			field.attr("value", value);
			form.append(field);
		});
		// The form needs to be a part of the document in
		// order for us to be able to submit it.
		$(document.body).append(form);
		form.submit();
	}
	
	function updatePage() {
		// Prepare help buttons
		$('a.info').off('click').click(function(){
			var e = $(this).attr("info");
			$('#'+e).dialog({ title: 'Calendar Trigger(s) Help', bgiframe: true, modal: true, width: 400, 
				open: function(){fitDialog(this)}, 
				buttons: { Close: function() { $(this).dialog('close'); } } });
		});		
		$('#title').css('font-weight','bold');
	}
	$(document).ready(function() {
		updatePage();
	});
</script>	
	
<?php
//Display the project footer
require_once APP_PATH_DOCROOT . 'ProjectGeneral/footer.php';
?>
