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
 * Library code for the SQL Query Reports.
 *
 * @package    report_sqlqueries
 * @copyright  2021 The Training Room Online {@link https://ttro.com}
 * @copyright  based on work by 2009 The Open University
 * @license    {@link http://www.gnu.org/copyleft/gpl.html} GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/validateurlsyntax.php');

define('REPORT_SQLQUERIES_LIMIT_EXCEEDED_MARKER', '-- ROW LIMIT EXCEEDED --');

function report_sqlqueries_execute_query($sql, $params = null, $limitnum = null) {
    global $CFG, $DB;

    if ($limitnum === null) {
        $limitnum = get_config('report_sqlqueries', 'querylimitdefault');
    }

    $sql = preg_replace('/\bprefix_(?=\w+)/i', $CFG->prefix, $sql);

    foreach ($params as $name => $value) {
        if (((string) (int) $value) === ((string) $value)) {
            $params[$name] = (int) $value;
        }
    }

    // Note: throws Exception if there is an error.
    return $DB->get_recordset_sql($sql, $params, 0, $limitnum);
}

function report_sqlqueries_prepare_sql($report, $timenow) {
    global $USER;
    $sql = $report->querysql;
    if ($report->runable != 'manual') {
        list($end, $start) = report_sqlqueries_get_starts($report, $timenow);
        $sql = report_sqlqueries_substitute_time_tokens($sql, $start, $end);
    }
    $sql = report_sqlqueries_substitute_user_token($sql, $USER->id);
    return $sql;
}

/**
 * Extract all the placeholder names from the SQL.
 * @param string $sql The sql.
 * @return array placeholder names including the leading colon.
 */
function report_sqlqueries_get_query_placeholders($sql) {
    preg_match_all('/(?<!:):[a-z][a-z0-9_]*/', $sql, $matches);
    return $matches[0];
}

/**
 * Extract all the placeholder names from the SQL, and work out the corresponding form field names.
 *
 * @param string $querysql The sql.
 * @return string[] placeholder name => form field name.
 */
function report_sqlqueries_get_query_placeholders_and_field_names(string $querysql): array {
    $queryparams = [];
    foreach (report_sqlqueries_get_query_placeholders($querysql) as $queryparam) {
        $queryparams[substr($queryparam, 1)] = 'queryparam' . substr($queryparam, 1);
    }
    return $queryparams;
}

/**
 * Return the type of form field to use for a placeholder, based on its name.
 * @param string $name the placeholder name.
 * @return string a formslib element type, for example 'text' or 'date_time_selector'.
 */
function report_sqlqueries_get_element_type($name) {
    $regex = '/^date|date$/';
    if (preg_match($regex, $name)) {
        return 'date_time_selector';
    }
    return 'text';
}

function report_sqlqueries_generate_csv($report, $timenow) {
    global $DB;
    $starttime = microtime(true);

    $sql = report_sqlqueries_prepare_sql($report, $timenow);

    $queryparams = !empty($report->queryparams) ? unserialize($report->queryparams) : array();
    $querylimit  = $report->querylimit ?? get_config('report_sqlqueries', 'querylimitdefault');
    // Query one extra row, so we can tell if we hit the limit.
    $rs = report_sqlqueries_execute_query($sql, $queryparams, $querylimit + 1);

    $csvfilenames = array();
    $csvtimestamp = null;
    $count = 0;
    $file = null;
    foreach ($rs as $row) {
        if (!$csvtimestamp) {
            list($csvfilename, $csvtimestamp) = report_sqlqueries_csv_filename($report, $timenow);
            $csvfilenames[] = $csvfilename;

            if (!file_exists($csvfilename)) {
                $handle = fopen($csvfilename, 'w');
                report_sqlqueries_start_csv($handle, $row, $report);
            } else {
                $handle = fopen($csvfilename, 'a');
            }
        }

        $data = get_object_vars($row);
        foreach ($data as $name => $value) {
            if (report_sqlqueries_get_element_type($name) == 'date_time_selector' &&
                    report_sqlqueries_is_integer($value) && $value > 0) {
                $data[$name] = userdate($value, '%F %T');
            }
        }
        if ($report->singlerow) {
            array_unshift($data, strftime('%Y-%m-%d', $timenow));
        }
        report_sqlqueries_write_csv_row($handle, $data);
        $count += 1;
    }
    $rs->close();

    if (!empty($handle)) {
        if ($count > $querylimit) {
            report_sqlqueries_write_csv_row($handle, [REPORT_SQLQUERIES_LIMIT_EXCEEDED_MARKER]);
        }

        fclose($handle);
    }

    // Update the execution time in the DB.
    $updaterecord = new stdClass();
    $updaterecord->id = $report->id;
    $updaterecord->lastrun = time();
    $updaterecord->lastexecutiontime = round((microtime(true) - $starttime) * 1000);
    $DB->update_record('report_sqlqueries_queries', $updaterecord);

    // Report is runable daily, weekly or monthly.
    if ($report->runable != 'manual') {
        if ($csvfilenames) {
            foreach ($csvfilenames as $csvfilename) {
                if (!empty($report->emailto)) {
                    report_sqlqueries_email_report($report, $csvfilename);
                }
                if (!empty($report->customdir)) {
                    report_sqlqueries_copy_csv_to_customdir($report, $timenow, $csvfilename);
                }
            }
        } else { // If there is no data.
            if (!empty($report->emailto)) {
                report_sqlqueries_email_report($report);
            }
            if (!empty($report->customdir)) {
                report_sqlqueries_copy_csv_to_customdir($report, $timenow);
            }
        }
    }
    return $csvtimestamp;
}

/**
 * @param mixed $value some value
 * @return bool whether $value is an integer, or a string that looks like an integer.
 */
function report_sqlqueries_is_integer($value) {
    return (string) (int) $value === (string) $value;
}

function report_sqlqueries_csv_filename($report, $timenow) {
    if ($report->runable == 'manual') {
        return report_sqlqueries_temp_cvs_name($report->id, $timenow);

    } else if ($report->singlerow) {
        return report_sqlqueries_accumulating_cvs_name($report->id);

    } else {
        list($timestart) = report_sqlqueries_get_starts($report, $timenow);
        return report_sqlqueries_scheduled_cvs_name($report->id, $timestart);
    }
}

function report_sqlqueries_temp_cvs_name($reportid, $timestamp) {
    global $CFG;
    $path = 'admin_report_sqlqueries/temp/'.$reportid;
    make_upload_directory($path);
    return array($CFG->dataroot.'/'.$path.'/'.strftime('%Y%m%d-%H%M%S', $timestamp).'.csv',
                 $timestamp);
}

function report_sqlqueries_scheduled_cvs_name($reportid, $timestart) {
    global $CFG;
    $path = 'admin_report_sqlqueries/'.$reportid;
    make_upload_directory($path);
    return array($CFG->dataroot.'/'.$path.'/'.strftime('%Y%m%d-%H%M%S', $timestart).'.csv',
                 $timestart);
}

function report_sqlqueries_accumulating_cvs_name($reportid) {
    global $CFG;
    $path = 'admin_report_sqlqueries/'.$reportid;
    make_upload_directory($path);
    return array($CFG->dataroot.'/'.$path.'/accumulate.csv', 0);
}

function report_sqlqueries_get_archive_times($report) {
    global $CFG;
    if ($report->runable == 'manual' || $report->singlerow) {
        return array();
    }
    $files = glob($CFG->dataroot.'/admin_report_sqlqueries/'.$report->id.'/*.csv');
    $archivetimes = array();
    foreach ($files as $file) {
        if (preg_match('|/(\d\d\d\d)(\d\d)(\d\d)-(\d\d)(\d\d)(\d\d)\.csv$|', $file, $matches)) {
            $archivetimes[] = mktime($matches[4], $matches[5], $matches[6], $matches[2],
                                     $matches[3], $matches[1]);
        }
    }
    rsort($archivetimes);
    return $archivetimes;
}

function report_sqlqueries_substitute_time_tokens($sql, $start, $end) {
    return str_replace(array('%%STARTTIME%%', '%%ENDTIME%%'), array($start, $end), $sql);
}

function report_sqlqueries_substitute_user_token($sql, $userid) {
    return str_replace('%%USERID%%', $userid, $sql);
}

function report_sqlqueries_url($relativeurl) {
    global $CFG;
    return $CFG->wwwroot.'/report/sqlqueries/'.$relativeurl;
}

function report_sqlqueries_capability_options() {
    return array(
        'report/sqlqueries:view' => get_string('anyonewhocanveiwthisreport', 'report_sqlqueries'),
        'moodle/site:viewreports' => get_string('userswhocanviewsitereports', 'report_sqlqueries'),
        'moodle/site:config' => get_string('userswhocanconfig', 'report_sqlqueries')
    );
}

function report_sqlqueries_runable_options($type = null) {
    if ($type === 'manual') {
        return array('manual' => get_string('manual', 'report_sqlqueries'));
    }
    return array('manual' => get_string('manual', 'report_sqlqueries'),
                 'daily' => get_string('automaticallydaily', 'report_sqlqueries'),
                 'weekly' => get_string('automaticallyweekly', 'report_sqlqueries'),
                 'monthly' => get_string('automaticallymonthly', 'report_sqlqueries')
    );
}

function report_sqlqueries_daily_at_options() {
    $time = array();
    for ($h = 0; $h < 24; $h++) {
        $hour = ($h < 10) ? "0$h" : $h;
        $time[$h] = "$hour:00";
    }
    return $time;
}

function report_sqlqueries_email_options() {
    return array('emailnumberofrows' => get_string('emailnumberofrows', 'report_sqlqueries'),
            'emailresults' => get_string('emailresults', 'report_sqlqueries'),
            'emailattachment' => get_string('emailattachment', 'report_sqlqueries'),
    );
}

function report_sqlqueries_bad_words_list() {
    return array('ALTER', 'CREATE', 'DELETE', 'DROP', 'GRANT', 'INSERT', 'INTO',
                 'TRUNCATE', 'UPDATE');
}

function report_sqlqueries_contains_bad_word($string) {
    return preg_match('/\b('.implode('|', report_sqlqueries_bad_words_list()).')\b/i', $string);
}

function report_sqlqueries_log_delete($id) {
    $event = \report_sqlqueries\event\query_deleted::create(
            array('objectid' => $id, 'context' => context_system::instance()));
    $event->trigger();
}

function report_sqlqueries_log_edit($id) {
    $event = \report_sqlqueries\event\query_edited::create(
            array('objectid' => $id, 'context' => context_system::instance()));
    $event->trigger();
}

function report_sqlqueries_log_view($id) {
    $event = \report_sqlqueries\event\query_viewed::create(
            array('objectid' => $id, 'context' => context_system::instance()));
    $event->trigger();
}

/**
 * Returns all reports for a given type sorted by report 'displayname'.
 *
 * @param int $categoryid
 * @param string $type, type of report (manual, daily, weekly or monthly)
 * @return stdClass[] relevant rows from report_sqlqueries_queries.
 */
function report_sqlqueries_get_reports_for($categoryid, $type) {
    global $DB;
    $records = $DB->get_records('report_sqlqueries_queries',
        array('runable' => $type, 'categoryid' => $categoryid));

    return report_sqlqueries_sort_reports_by_displayname($records);
}

/**
 * Display a list of reports of one type in one category.
 *
 * @param object $reports, the result of DB query
 * @param string $type, type of report (manual, daily, weekly or monthly)
 */
function report_sqlqueries_print_reports_for($reports, $type) {
    global $OUTPUT;

    if (empty($reports)) {
        return;
    }

    if (!empty($type)) {
        $help = html_writer::tag('span', $OUTPUT->help_icon($type . 'header', 'report_sqlqueries'));
        echo $OUTPUT->heading(get_string($type . 'header', 'report_sqlqueries') . $help, 3);
    }

    $context = context_system::instance();
    $canedit = has_capability('report/sqlqueries:definequeries', $context);
    $capabilities = report_sqlqueries_capability_options();
    foreach ($reports as $report) {
        if (!empty($report->capability) && !has_capability($report->capability, $context)) {
            continue;
        }

        echo html_writer::start_tag('p');
        echo html_writer::tag('a', format_string($report->displayname),
                              array('href' => report_sqlqueries_url('view.php?id='.$report->id))).
             ' '.report_sqlqueries_time_note($report, 'span');
        if ($canedit) {
            $imgedit = $OUTPUT->pix_icon('t/edit', get_string('edit'));
            $imgdelete = $OUTPUT->pix_icon('t/delete', get_string('delete'));
            echo ' '.html_writer::tag('span', get_string('availableto', 'report_sqlqueries',
                                      $capabilities[$report->capability]),
                                      array('class' => 'admin_note')).' '.
                 html_writer::tag('a', $imgedit,
                         ['title' => get_string('editreportx', 'report_sqlqueries', format_string($report->displayname)),
                          'href' => report_sqlqueries_url('edit.php?id='.$report->id)]) . ' ' .
                 html_writer::tag('a', $imgdelete,
                            array('title' => get_string('deletereportx', 'report_sqlqueries', format_string($report->displayname)),
                                  'href' => report_sqlqueries_url('delete.php?id='.$report->id)));
        }
        echo html_writer::end_tag('p');
        echo "\n";
    }
}

/**
 * Get the list of actual column headers from the list of raw column names.
 *
 * This matches up the 'Column name' and 'Column name link url' columns.
 *
 * @param string[] $row the row of raw column headers from the CSV file.
 * @return array with two elements: the column headers to use in the table, and the columns that are links.
 */
function report_sqlqueries_get_table_headers($row) {
    $colnames = array_combine($row, $row);
    $linkcolumns = [];
    $colheaders = [];

    foreach ($row as $key => $colname) {
        if (substr($colname, -9) === ' link url' && isset($colnames[substr($colname, 0, -9)])) {
            // This is a link_url column for another column. Skip.
            $linkcolumns[$key] = -1;

        } else if (isset($colnames[$colname . ' link url'])) {
            $colheaders[] = $colname;
            $linkcolumns[$key] = array_search($colname . ' link url', $row);
        } else {
            $colheaders[] = $colname;
        }
    }

    return [$colheaders, $linkcolumns];
}

/**
 * Prepare the values in a data row for display.
 *
 * This deals with $linkcolumns as detected above and other values that looks like links.
 * Auto-formatting dates is handled when the CSV is generated.
 *
 * @param string[] $row the row of raw data.
 * @param int[] $linkcolumns
 * @return string[] cell contents for output.
 */
function report_sqlqueries_display_row($row, $linkcolumns) {
    $rowdata = array();
    foreach ($row as $key => $value) {
        if (isset($linkcolumns[$key]) && $linkcolumns[$key] === -1) {
            // This row is the link url for another row.
            continue;
        } else if (isset($linkcolumns[$key])) {
            // Column with link url coming from another column.
            if (validateUrlSyntax($row[$linkcolumns[$key]], 's+H?S?F?E?u-P-a?I?p?f?q?r?')) {
                $rowdata[] = '<a href="' . s($row[$linkcolumns[$key]]) . '">' . s($value) . '</a>';
            } else {
                $rowdata[] = s($value);
            }
        } else if (validateUrlSyntax($value, 's+H?S?F?E?u-P-a?I?p?f?q?r?')) {
            // Column where the value just looks like a link.
            $rowdata[] = '<a href="' . s($value) . '">' . s($value) . '</a>';
        } else {
            $rowdata[] = s($value);
        }
    }
    return $rowdata;
}

function report_sqlqueries_time_note($report, $tag) {
    if ($report->lastrun) {
        $a = new stdClass;
        $a->lastrun = userdate($report->lastrun);
        $a->lastexecutiontime = $report->lastexecutiontime / 1000;
        $note = get_string('lastexecuted', 'report_sqlqueries', $a);

    } else {
        $note = get_string('notrunyet', 'report_sqlqueries');
    }

    return html_writer::tag($tag, $note, array('class' => 'admin_note'));
}


function report_sqlqueries_pretify_column_names($row, $querysql) {
    $colnames = [];

    foreach (get_object_vars($row) as $colname => $ignored) {
        // Databases tend to return the columns lower-cased.
        // Try to get the original case from the query.
        if (preg_match('~SELECT.*?\s(' . preg_quote($colname, '~') . ')\b~is',
                $querysql, $matches)) {
            $colname = $matches[1];
        }

        // Change underscores to spaces.
        $colnames[] = str_replace('_', ' ', $colname);
    }
    return $colnames;
}

/**
 * Writes a CSV row and replaces placeholders.
 * @param resource $handle the file pointer
 * @param array $data a data row
 */
function report_sqlqueries_write_csv_row($handle, $data) {
    global $CFG;
    $escapeddata = array();
    foreach ($data as $value) {
        $value = str_replace('%%WWWROOT%%', $CFG->wwwroot, $value);
        $value = str_replace('%%Q%%', '?', $value);
        $value = str_replace('%%C%%', ':', $value);
        $value = str_replace('%%S%%', ';', $value);
        $escapeddata[] = '"'.str_replace('"', '""', $value).'"';
    }
    fwrite($handle, implode(',', $escapeddata)."\r\n");
}

function report_sqlqueries_start_csv($handle, $firstrow, $report) {
    $colnames = report_sqlqueries_pretify_column_names($firstrow, $report->querysql);
    if ($report->singlerow) {
        array_unshift($colnames, get_string('queryrundate', 'report_sqlqueries'));
    }
    report_sqlqueries_write_csv_row($handle, $colnames);
}

/**
 * @param int $timenow a timestamp.
 * @param int $at an hour, 0 to 23.
 * @return array with two elements: the timestamp for hour $at today (where today
 *      is defined by $timenow) and the timestamp for hour $at yesterday.
 */
function report_sqlqueries_get_daily_time_starts($timenow, $at) {
    $hours = $at;
    $minutes = 0;
    $dateparts = getdate($timenow);
    return array(
        mktime((int)$hours, (int)$minutes, 0,
                $dateparts['mon'], $dateparts['mday'], $dateparts['year']),
        mktime((int)$hours, (int)$minutes, 0,
                $dateparts['mon'], $dateparts['mday'] - 1, $dateparts['year']),
        );
}

function report_sqlqueries_get_week_starts($timenow) {
    $dateparts = getdate($timenow);

    // Get configured start of week value. If -1 then use the value from the site calendar.
    $startofweek = get_config('report_sqlqueries', 'startwday');
    if ($startofweek == -1) {
        $startofweek = \core_calendar\type_factory::get_calendar_instance()->get_starting_weekday();
    }
    $daysafterweekstart = ($dateparts['wday'] - $startofweek + 7) % 7;

    return array(
        mktime(0, 0, 0, $dateparts['mon'], $dateparts['mday'] - $daysafterweekstart,
               $dateparts['year']),
        mktime(0, 0, 0, $dateparts['mon'], $dateparts['mday'] - $daysafterweekstart - 7,
               $dateparts['year']),
    );
}

function report_sqlqueries_get_month_starts($timenow) {
    $dateparts = getdate($timenow);

    return array(
        mktime(0, 0, 0, $dateparts['mon'], 1, $dateparts['year']),
        mktime(0, 0, 0, $dateparts['mon'] - 1, 1, $dateparts['year']),
    );
}

function report_sqlqueries_get_starts($report, $timenow) {
    switch ($report->runable) {
        case 'daily':
            return report_sqlqueries_get_daily_time_starts($timenow, $report->at);
        case 'weekly':
            return report_sqlqueries_get_week_starts($timenow);
        case 'monthly':
            return report_sqlqueries_get_month_starts($timenow);
        default:
            throw new Exception('unexpected $report->runable.');
    }
}

function report_sqlqueries_delete_old_temp_files($upto) {
    global $CFG;

    $count = 0;
    $comparison = strftime('%Y%m%d-%H%M%S', $upto).'csv';

    $files = glob($CFG->dataroot.'/admin_report_sqlqueries/temp/*/*.csv');
    if (empty($files)) {
        return;
    }
    foreach ($files as $file) {
        if (basename($file) < $comparison) {
            unlink($file);
            $count += 1;
        }
    }

    return $count;
}

/**
 * Check the list of userids are valid, and have permission to access the report.
 *
 * @param array $userids user ids.
 * @param string $capability capability name.
 * @return string|null null if all OK, else error message.
 */
function report_sqlqueries_validate_users($userids, $capability) {
    global $DB;
    if (empty($userstring)) {
        return null;
    }

    $a = new stdClass();
    $a->capability = $capability;
    $a->whocanaccess = get_string('whocanaccess', 'report_sqlqueries');

    foreach ($userids as $userid) {
        // Cannot find the user in the database.
        if (!$user = $DB->get_record('user', ['id' => $userid])) {
            return get_string('usernotfound', 'report_sqlqueries', $userid);
        }
        // User does not have the chosen access level.
        $context = context_user::instance($user->id);
        $a->userid = $userid;
        $a->name = fullname($user);
        if (!has_capability($capability, $context, $user)) {
            return get_string('userhasnothiscapability', 'report_sqlqueries', $a);
        }
    }
    return null;
}

function report_sqlqueries_get_message_no_data($report) {
    // Construct subject.
    $subject = get_string('emailsubjectnodata', 'report_sqlqueries',
            report_sqlqueries_plain_text_report_name($report));
    $url = new moodle_url('/report/sqlqueries/view.php', array('id' => $report->id));
    $link = get_string('emailink', 'report_sqlqueries', html_writer::tag('a', $url, array('href' => $url)));
    $fullmessage = html_writer::tag('p', get_string('nodatareturned', 'report_sqlqueries') . ' ' . $link);
    $fullmessagehtml = $fullmessage;

    // Create the message object.
    $message = new stdClass();
    $message->subject           = $subject;
    $message->fullmessage       = $fullmessage;
    $message->fullmessageformat = FORMAT_HTML;
    $message->fullmessagehtml   = $fullmessagehtml;
    $message->smallmessage      = null;
    return $message;
}

function report_sqlqueries_get_message($report, $csvfilename) {
    $handle = fopen($csvfilename, 'r');
    $table = new html_table();
    $table->head = fgetcsv($handle);
    $countrows = 0;
    while ($row = fgetcsv($handle)) {
        $rowdata = array();
        foreach ($row as $value) {
            $rowdata[] = $value;
        }
        $table->data[] = $rowdata;
        $countrows++;
    }
    fclose($handle);

    // Construct subject.
    if ($countrows == 0) {
        $subject = get_string('emailsubjectnodata', 'report_sqlqueries',
                report_sqlqueries_plain_text_report_name($report));
    } else if ($countrows == 1) {
        $subject = get_string('emailsubject1row', 'report_sqlqueries',
                report_sqlqueries_plain_text_report_name($report));
    } else {
        $subject = get_string('emailsubjectxrows', 'report_sqlqueries',
                ['name' => report_sqlqueries_plain_text_report_name($report), 'rows' => $countrows]);
    }

    // Construct message without the table.
    $fullmessage = '';
    if (!html_is_blank($report->description)) {
        $fullmessage .= html_writer::tag('p', format_text($report->description, FORMAT_HTML));
    }

    if ($countrows === 1) {
        $returnrows = html_writer::tag('span', get_string('emailrow', 'report_sqlqueries', $countrows));
    } else {
        $returnrows = html_writer::tag('span', get_string('emailrows', 'report_sqlqueries', $countrows));
    }
    $url = new moodle_url('/report/sqlqueries/view.php', array('id' => $report->id));
    $link = get_string('emailink', 'report_sqlqueries', html_writer::tag('a', $url, array('href' => $url)));
    $fullmessage .= html_writer::tag('p', $returnrows . ' ' . $link);

    // Construct message in html.
    $fullmessagehtml = null;
    if ($report->emailwhat === 'emailresults') {
        $fullmessagehtml = html_writer::table($table);
    }
    $fullmessagehtml .= $fullmessage;

    // Create the message object.
    $message = new stdClass();
    $message->subject           = $subject;
    $message->fullmessage       = $fullmessage;
    $message->fullmessageformat = FORMAT_HTML;
    $message->fullmessagehtml   = $fullmessagehtml;
    $message->smallmessage      = null;

    if ($report->emailwhat === 'emailattachment') {
        $fs = get_file_storage();
        $file = $fs->create_file_from_pathname(
            [
                'contextid' => context_system::instance()->id,
                'component' => 'report_sqlqueries',
                'filearea' => 'admin_report_sqlqueries',
                'itemid' => 0,
                'filepath' => dirname($csvfilename) . '/',
                'filename' => basename($csvfilename),
            ],
            $csvfilename
        );
        $message->attachment = $file;
        $message->attachname = $file->get_filename();
    }

    return $message;
}

function report_sqlqueries_email_report($report, $csvfilename = null) {
    global $DB;

    // If there are no recipients return.
    if (!$report->emailto) {
        return;
    }
    // Get the message.
    if ($csvfilename) {
        $message = report_sqlqueries_get_message($report, $csvfilename);
    } else {
        $message = report_sqlqueries_get_message_no_data($report);
    }

    // Email all recipients.
    $userids = preg_split("/[\s,]+/", $report->emailto);
    foreach ($userids as $userid) {
        $recipient = $DB->get_record('user', array('id' => $userid), '*', MUST_EXIST);
        $messageid = report_sqlqueries_send_email_notification($recipient, $message);
        if (!$messageid) {
            mtrace(get_string('emailsentfailed', 'report_sqlqueries', fullname($recipient)));
        }
    }
}

function report_sqlqueries_get_ready_to_run_daily_reports($timenow) {
    global $DB;
    $reports = $DB->get_records_select('report_sqlqueries_queries', "runable = ?", array('daily'), 'id');

    $reportstorun = array();
    foreach ($reports as $id => $r) {
        // Check whether the report is ready to run.
        if (!report_sqlqueries_is_daily_report_ready($r, $timenow)) {
            continue;
        }
        $reportstorun[$id] = $r;
    }
    return $reportstorun;
}

/**
 * Sends a notification message to the reciepients.
 *
 * @param object $recipient the message recipient.
 * @param object $message the message object.
 * @return mixed result of {@link message_send()}.
 */
function report_sqlqueries_send_email_notification($recipient, $message) {

    // Prepare the message.
    $eventdata = new \core\message\message();
    $eventdata->component         = 'report_sqlqueries';
    $eventdata->name              = 'notification';
    $eventdata->notification      = 1;
    $eventdata->courseid          = SITEID;
    $eventdata->userfrom          = \core_user::get_support_user();
    $eventdata->userto            = $recipient;
    $eventdata->subject           = $message->subject;
    $eventdata->fullmessage       = $message->fullmessage;
    $eventdata->fullmessageformat = $message->fullmessageformat;
    $eventdata->fullmessagehtml   = $message->fullmessagehtml;
    $eventdata->smallmessage      = $message->smallmessage;

    if (isset($message->attachment)) {
        $eventdata->attachment        = $message->attachment;
        $eventdata->attachname        = $message->attachname;
    }

    return message_send($eventdata);
}

/**
 * Check if the report is ready to run.
 *
 * @param object $report
 * @param int $timenow
 * @return boolean
 */
function report_sqlqueries_is_daily_report_ready($report, $timenow) {
    // Time when the report should run today.
    list($runtimetoday) = report_sqlqueries_get_daily_time_starts($timenow, $report->at);

    // Values used to check whether the report has already run today.
    list($today) = report_sqlqueries_get_daily_time_starts($timenow, 0);
    list($lastrunday) = report_sqlqueries_get_daily_time_starts($report->lastrun, 0);

    if (($runtimetoday <= $timenow) && ($today > $lastrunday)) {
        return true;
    }
    return false;
}

function report_sqlqueries_category_options() {
    global $DB;
    return $DB->get_records_menu('report_sqlqueries_categories', null, 'name ASC', 'id, name');
}

/**
 * Copies a csv file to an optional custom directory or file path.
 *
 * @param object $report
 * @param integer $timenow
 * @param string $csvfilename
 */
function report_sqlqueries_copy_csv_to_customdir($report, $timenow, $csvfilename = null) {

    // If the filename is empty then there was no data so we can't export a
    // new file, but if we are saving over the same file then we should delete
    // the existing file or it will have stale data in it.
    if (empty($csvfilename)) {
        $filepath = $report->customdir;
        if (!is_dir($filepath)) {
            file_put_contents($filepath, '');
            mtrace("No data so resetting $filepath");
        }
        return;
    }

    $filename = $report->id . '-' . basename($csvfilename);
    if (is_dir($report->customdir)) {
        $filepath = realpath($report->customdir) . DIRECTORY_SEPARATOR . $filename;
    } else {
        $filepath = $report->customdir;
    }

    copy($csvfilename, $filepath);
    mtrace("Exported $csvfilename to $filepath");
}

/**
 * Get a report name as plain text, for use in places like cron output and email subject lines.
 *
 * @param object $report report settings from the database.
 * @return string the usable version of the name.
 */
function report_sqlqueries_plain_text_report_name($report): string {
    return format_string($report->displayname, true,
            ['context' => context_system::instance()]);
}

/**
 * Returns all reports for a given type sorted by report 'displayname'.
 *
 * @param array $records relevant rows from report_sqlqueries_queries
 * @return array
 */
function report_sqlqueries_sort_reports_by_displayname(array $records): array {
    $sortedrecords = [];

    foreach ($records as $record) {
        $sortedrecords[$record->displayname] = $record;
    }

    ksort($sortedrecords, SORT_NATURAL);

    return $sortedrecords;
}
