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
 * Privacy Subsystem implementation for report_sqlqueries.
 *
 * @package    report_sqlqueries
 * @copyright  2021 The Training Room Online {@link https://ttro.com}
 * @copyright  based on work by 2018 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace report_sqlqueries\privacy;
defined('MOODLE_INTERNAL') || die();
/**
 * Privacy Subsystem for report_sqlqueries implementing null_provider.
 *
 * @copyright  2021 The Training Room Online {@link https://ttro.com}
 * @copyright  based on work by 2018 The Open University
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements \core_privacy\local\metadata\null_provider {

    use \core_privacy\local\legacy_polyfill;

    /**
     * Get the language string identifier with the component's language
     * file to explain why this plugin stores no data.
     *
     * @return  string
     */
    public static function _get_reason() {
        return 'privacy:metadata';
    }
}
