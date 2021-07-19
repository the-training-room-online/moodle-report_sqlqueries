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
 * version.php file for the SQL Queries admin report.
 *
 * @package    report_sqlqueries
 * @copyright  2021 The Training Room Online {@link https://ttro.com}
 * @copyright  based on work by 2015 The Open University
 * @license    {@link http://www.gnu.org/copyleft/gpl.html} GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$plugin->version   = 2021071600;
$plugin->requires  = 2020061500;
$plugin->component = 'report_sqlqueries';
$plugin->maturity  = MATURITY_STABLE;
$plugin->release   = '4.1 for Moodle 3.9+';

$plugin->outestssufficient = true;
