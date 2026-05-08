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

declare(strict_types=1);

namespace filter_embeddiscussion\reportbuilder\local\entities;

use lang_string;
use core_reportbuilder\local\entities\base;
use core_reportbuilder\local\filters\{boolean_select, date, text};
use core_reportbuilder\local\report\{column, filter};

/**
 * Thread entity for filter_embeddiscussion reports.
 *
 * @package    filter_embeddiscussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class thread extends base {
    /**
     * Default tables.
     *
     * @return string[]
     */
    protected function get_default_tables(): array {
        return ['filter_embeddiscussion_thread'];
    }

    /**
     * Default entity title.
     *
     * @return lang_string
     */
    protected function get_default_entity_title(): lang_string {
        return new lang_string('threadname', 'filter_embeddiscussion');
    }

    /**
     * Initialise the entity.
     *
     * @return base
     */
    public function initialise(): base {
        foreach ($this->get_all_columns() as $column) {
            $this->add_column($column);
        }
        foreach ($this->get_all_filters() as $filter) {
            $this->add_filter($filter)->add_condition($filter);
        }
        return $this;
    }

    /**
     * Columns.
     *
     * @return column[]
     */
    protected function get_all_columns(): array {
        $alias = $this->get_table_alias('filter_embeddiscussion_thread');

        $columns = [];

        $columns[] = (new column(
            'name',
            new lang_string('threadname', 'filter_embeddiscussion'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TEXT)
            ->add_field("{$alias}.threadname", 'name')
            ->set_is_sortable(true)
            ->set_callback(static function (?string $value): string {
                if ($value === null) {
                    return '';
                }
                return format_string($value, true, ['context' => \context_system::instance()]);
            });

        $columns[] = (new column(
            'postcount',
            new lang_string('threadposts', 'filter_embeddiscussion'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_INTEGER)
            ->add_field("(SELECT COUNT(1) FROM {filter_embeddiscussion_post} p
                          WHERE p.threadid = {$alias}.id AND p.deleted = 0)", 'postcount')
            ->set_is_sortable(true);

        $columns[] = (new column(
            'lastpost',
            new lang_string('threadlastpost', 'filter_embeddiscussion'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("(SELECT MAX(p.timecreated) FROM {filter_embeddiscussion_post} p
                          WHERE p.threadid = {$alias}.id AND p.deleted = 0)", 'lastpost')
            ->set_is_sortable(true)
            ->set_callback(static function ($value): string {
                if (empty($value)) {
                    return '&mdash;';
                }
                return userdate((int)$value);
            });

        $columns[] = (new column(
            'anonymous',
            new lang_string('anonymous', 'filter_embeddiscussion'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_BOOLEAN)
            ->add_field("{$alias}.anonymous")
            ->set_is_sortable(true)
            ->set_callback(static function ($value): string {
                return $value ? get_string('yes') : get_string('no');
            });

        $columns[] = (new column(
            'timecreated',
            new lang_string('timecreated', 'core_reportbuilder'),
            $this->get_entity_name()
        ))
            ->add_joins($this->get_joins())
            ->set_type(column::TYPE_TIMESTAMP)
            ->add_field("{$alias}.timecreated")
            ->set_is_sortable(true)
            ->set_callback(static function ($value): string {
                return userdate((int)$value);
            });

        return $columns;
    }

    /**
     * Filters.
     *
     * @return filter[]
     */
    protected function get_all_filters(): array {
        $alias = $this->get_table_alias('filter_embeddiscussion_thread');
        $filters = [];

        $filters[] = (new filter(
            text::class,
            'name',
            new lang_string('threadname', 'filter_embeddiscussion'),
            $this->get_entity_name(),
            "{$alias}.threadname"
        ))->add_joins($this->get_joins());

        $filters[] = (new filter(
            boolean_select::class,
            'anonymous',
            new lang_string('anonymous', 'filter_embeddiscussion'),
            $this->get_entity_name(),
            "{$alias}.anonymous"
        ))->add_joins($this->get_joins());

        $filters[] = (new filter(
            date::class,
            'timecreated',
            new lang_string('timecreated', 'core_reportbuilder'),
            $this->get_entity_name(),
            "{$alias}.timecreated"
        ))->add_joins($this->get_joins());

        return $filters;
    }
}
