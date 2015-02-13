<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Displays indicator reports for a chosen course
 *
 * @package    report_engagement
 * @copyright  2012 NetSpot Pty Ltd
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(dirname(__FILE__).'/../../config.php');
require_once($CFG->dirroot . '/report/engagement/locallib.php');

// allow csv manipulation
require_once($CFG->libdir . '/csvlib.class.php');

$id = required_param('id', PARAM_INT); // Course ID.
$userid = optional_param('userid', 0, PARAM_INT);

// for exporting report as csv
$exportcsv = optional_param('exportcsv', 0, PARAM_INT);

$pageparams = array('id' => $id);
if ($userid) {
    $pageparams['userid'] = $userid;
}

$PAGE->set_url('/report/engagement/index.php', $pageparams);
$PAGE->set_pagelayout('report');

$course = $DB->get_record('course', array('id' => $id), '*', MUST_EXIST);
if ($userid) {
    $namefields = get_all_user_name_fields();
    $user = $DB->get_record('user', array('id' => $userid), 'id, email, '.implode(',', $namefields), MUST_EXIST);
    $PAGE->navbar->add(fullname($user), new moodle_url('/report/engagement/index.php', $pageparams));
}

require_login($course);
$context = context_course::instance($course->id);
$PAGE->set_context($context);
$updateurl = new moodle_url('/report/engagement/edit.php', array('id' => $id));
$PAGE->set_button($OUTPUT->single_button($updateurl, get_string('updatesettings', 'report_engagement'), 'get'));
$PAGE->set_heading($course->fullname);

require_capability('report/engagement:view', $context);

/*if (!$userid) {
    add_to_log($course->id, "course", "report engagement", "report/engagement/index.php?id=$course->id", $course->id);
} else {
    add_to_log($course->id, "course", "report engagement",
        "report/engagement/index.php?id=$course->id&userid=$user->id", $course->id);
}*/

$stradministration = get_string('administration');
$strreports = get_string('reports');
$renderer = $PAGE->get_renderer('report_engagement');



/*$dat = $DB->get_records_sql("SELECT *
                FROM {forum_read} fr
                JOIN {forum} f ON (f.id = fr.forumid)
                WHERE f.course = $COURSE->id");*/
/*$dat = $DB->get_records_sql("SELECT * FROM {forum} where course = $COURSE->id");*/
/*$dat = $DB->get_records_sql("SELECT p.id, p.userid, p.created, p.parent
                FROM {forum_posts} p
                JOIN {forum_discussions} d ON (d.id = p.discussion)
                WHERE d.course = $COURSE->id ORDER BY p.created DESC LIMIT 50");*/
//$dat = $DB->get_records_sql("SELECT * FROM {logstore_standard_log} WHERE courseid = $COURSE->id ORDER BY timecreated DESC LIMIT 50");
/*$dat = $DB->get_records_sql("SELECT * FROM {log} WHERE course = $COURSE->id 
	AND time > 1411999200 AND time < 1412085600
	ORDER BY time DESC LIMIT 150");*/
/*echo "<pre>";
var_dump($dat);
echo "</pre>";
die();*/



if (!$exportcsv) {
	echo $OUTPUT->header();
	echo $OUTPUT->heading('Quick aggregate query');
}

$pluginman = core_plugin_manager::instance();
$indicators = get_plugin_list('engagementindicator');
foreach ($indicators as $name => $path) {
    $plugin = $pluginman->get_plugin_info('engagementindicator_'.$name);
    if (!$plugin->is_enabled()) {
        unset($indicators[$name]);
    }
}
$weightings = $DB->get_records_menu('report_engagement', array('course' => $id), '', 'indicator, weight');

// get global settings
$settingsnames = array('queryspecifydatetime', 'querystartdatetime', 'queryenddatetime');
$querysettings = new stdClass();
foreach ($settingsnames as $name) {
	$tempvar = $DB->get_record_sql("SELECT * FROM {report_engagement} WHERE course = $COURSE->id AND indicator = '$name'");
	$querysettings->{"$name"} = $tempvar->configdata;
}

if (!$exportcsv) {
	// show query limits if necessary
	if (isset($querysettings->queryspecifydatetime) && $querysettings->queryspecifydatetime) {
		echo $OUTPUT->notification('query limit set: from ' . date('Y-m-d H:i:s', $querysettings->querystartdatetime) . ' to ' . date('Y-m-d H:i:s', $querysettings->queryenddatetime) . " [$querysettings->querystartdatetime $querysettings->queryenddatetime]");
	}
}

// prepare exportcsv if necessary
if ($exportcsv == 1) {
	$csvwriter = new csv_export_writer();
	$csvfilename = 'report_engagement_aggregate_summary_course' . $COURSE->id;
	if (isset($querysettings->queryspecifydatetime) && $querysettings->queryspecifydatetime) {
		// if query datetime limit is set, reflect in filename
		date_default_timezone_set(usertimezone()); // hack, not sure why timezone is not reflected correctly here
		$csvfilename .= '_' . 
						date('Ymd_His', $querysettings->querystartdatetime) . '-' .
						date('Ymd_His', $querysettings->queryenddatetime) ;
	}
	$csvwriter->filename = $csvfilename . '.csv';
}

$headers = array('username', 
				 'forum total postings', 'forum new', 'forum replies', 'forum read', 
				 'total logins', 'average session length', 'average logins per week');

if ($exportcsv == 1) {
	$csvwriter->add_data($headers);
}

$data = array();

foreach ($indicators as $name => $path) {
	if (file_exists("$path/indicator.class.php")) {
		require_once("$path/indicator.class.php");
		$classname = "indicator_$name";
		$indicator = new $classname($id);
		// run in order to process data
		$indicatorrisks = $indicator->get_course_risks();
		// fetch raw data
		$rawdata = $indicator->get_course_rawdata();
		// fetch array of userids
		$users = $indicator->get_course_users();
		
		if (empty($data)) {
			foreach ($users as $userid) {
				$data[$userid] = [];
			}
		}
		foreach ($users as $userid) {
			$data[$userid][$name] = [];
		}
		
		switch ($name) {
			case 'forum':
				/*echo "<pre>";
				var_dump($rawdata);
				echo "</pre>";*/
				foreach ($rawdata->posts as $userid => $record) {
					$data[$userid]['forum']['total'] = $record['total']; // total postings (not readings)
					$data[$userid]['forum']['new'] = $record['new'];
					$data[$userid]['forum']['replies'] = $record['replies'];
					$data[$userid]['forum']['read'] = $record['read'];
				}
				break;
			case 'login':
				foreach ($rawdata as $userid => $record) {
					$data[$userid]['login']['totaltimes'] = count($record['lengths']);
					if ($record['total'] > 0) {
						$data[$userid]['login']['averagesessionlength'] = array_sum($record['lengths']) / count($record['lengths']);
						$data[$userid]['login']['averageperweek'] = array_sum($record['weeks']) / count($record['weeks']);
					} else {
						$data[$userid]['login']['averagesessionlength'] = "";
						$data[$userid]['login']['averageperweek'] = "";					
					}
				}
				break;
			case 'assessment':
				// TODO
				break;
		}
	}
}

$table = new html_table();
$table->head = $headers;
foreach ($data as $userid => $record) {
	$row = [];
	$studentrecord = $DB->get_record('user', array('id' => $userid));
	$row[] = $studentrecord->username;
	$row[] = $record['forum']['total'];
	$row[] = $record['forum']['new'];
	$row[] = $record['forum']['replies'];
	$row[] = $record['forum']['read'];
	$row[] = $record['login']['totaltimes'];
	$row[] = round($record['login']['averagesessionlength'], 1);
	$row[] = round($record['login']['averageperweek'], 1);
	$table->data[] = $row;
	// add data to csvwriter if exporting csv
	if ($exportcsv == 1) {
		$csvwriter->add_data($row);
	}
}

if ($exportcsv == 1) {
	$csvwriter->download_file();
	die();
} else {
	// show export csv link
	echo $OUTPUT->action_link(new moodle_url('/report/engagement/query.php', array('id' => $COURSE->id, 'exportcsv' => 1)), 'Download CSV');
	// show table
	echo html_writer::table($table);
}

//var_dump($data);

/*global $DB;
$query = "SELECT {log}.id, {log}.time, {user}.username, {log}.course, {log}.module, {log}.cmid, {log}.action, {log}.url, {log}.info
			FROM {log} 
			INNER JOIN {user} ON {user}.id = {log}.userid
			WHERE {log}.course = $id 
			LIMIT 500";

$interactions = array(
	"loggedin" => "loggedin",
	"forumviewdiscussion" => "forum view discussion",
	"forumpost" => "forum post"
	
);		
$table = new html_table();
//$table->head = array_keys($data[0]);
$table->data = $data;
echo html_writer::table($table);*/	

if (!$exportcsv) {
	echo $OUTPUT->footer();
}
