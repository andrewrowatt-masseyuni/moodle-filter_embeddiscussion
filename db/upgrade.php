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
 * Upgrade steps for filter_embeddiscussion.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Execute filter_embeddiscussion upgrade.
 *
 * @param int $oldversion
 * @return bool
 */
function xmldb_filter_embeddiscussion_upgrade(int $oldversion): bool {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026050800) {
        $table = new xmldb_table('filter_embeddiscussion_thread');

        $namefield = new xmldb_field('name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'id');
        $idnumberfield = new xmldb_field('idnumber', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'id');
        if ($dbman->field_exists($table, $namefield) && !$dbman->field_exists($table, $idnumberfield)) {
            $dbman->rename_field($table, $namefield, 'idnumber');
        }

        $pagetitlefield = new xmldb_field('pagetitle', XMLDB_TYPE_CHAR, '255', null, null, null, null, 'idnumber');
        if (!$dbman->field_exists($table, $pagetitlefield)) {
            $dbman->add_field($table, $pagetitlefield);
        }

        if ($dbman->field_exists($table, $idnumberfield) && $dbman->field_exists($table, $pagetitlefield)) {
            $recordset = $DB->get_recordset('filter_embeddiscussion_thread', null, '', 'id, idnumber, pagetitle');
            foreach ($recordset as $thread) {
                if (trim((string)($thread->pagetitle ?? '')) !== '') {
                    continue;
                }
                $DB->set_field(
                    'filter_embeddiscussion_thread',
                    'pagetitle',
                    (string)($thread->idnumber ?? ''),
                    ['id' => (int)$thread->id]
                );
            }
            $recordset->close();
        }

        upgrade_plugin_savepoint(true, 2026050800, 'filter', 'embeddiscussion');
    }

    return true;
}
