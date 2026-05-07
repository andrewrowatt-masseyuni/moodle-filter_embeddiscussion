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
 * Site-wide settings for filter_embeddiscussion.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    $settings->add(new admin_setting_heading(
        'filter_embeddiscussion/legacytokens',
        get_string('settings_legacytokens', 'filter_embeddiscussion'),
        get_string('settings_legacytokens_desc', 'filter_embeddiscussion')
    ));

    $settings->add(new admin_setting_configcheckbox(
        'filter_embeddiscussion/legacycomments',
        get_string('setting_legacycomments', 'filter_embeddiscussion'),
        get_string('setting_legacycomments_desc', 'filter_embeddiscussion'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'filter_embeddiscussion/legacyfilterdisqus',
        get_string('setting_legacyfilterdisqus', 'filter_embeddiscussion'),
        get_string('setting_legacyfilterdisqus_desc', 'filter_embeddiscussion'),
        0
    ));

    $settings->add(new admin_setting_heading(
        'filter_embeddiscussion/general',
        get_string('settings_general', 'filter_embeddiscussion'),
        ''
    ));

    $settings->add(new admin_setting_configtextarea(
        'filter_embeddiscussion/adjectives',
        get_string('setting_adjectives', 'filter_embeddiscussion'),
        get_string('setting_adjectives_desc', 'filter_embeddiscussion'),
        get_string('setting_adjectives_default', 'filter_embeddiscussion'),
        PARAM_TEXT
    ));

    $settings->add(new admin_setting_configtextarea(
        'filter_embeddiscussion/animals',
        get_string('setting_animals', 'filter_embeddiscussion'),
        get_string('setting_animals_desc', 'filter_embeddiscussion'),
        get_string('setting_animals_default', 'filter_embeddiscussion'),
        PARAM_TEXT
    ));
}
