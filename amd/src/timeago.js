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
 * Light-weight relative time formatter and ticker.
 *
 * @module     filter_embeddiscussion/timeago
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';

const SECOND = 1;
const MINUTE = 60 * SECOND;
const HOUR = 60 * MINUTE;
const DAY = 24 * HOUR;
const YEAR = 365 * DAY;

const UNIT_FALLBACK_LABELS = {
    second: ['second', 'seconds'],
    minute: ['minute', 'minutes'],
    hour: ['hour', 'hours'],
    day: ['day', 'days'],
    year: ['year', 'years'],
};

const UNIT_FORMATTERS = new Map();

let started = false;
// Localised template for the relative time string. Defaults to "{$a}" so that, before the
// async lang-string fetch resolves (or if it fails), the value renders bare without an
// English-only "ago" stitched on. Populated by startTicker().
let relativeTemplate = '{$a}';

/**
 * Format a duration value with a locale-aware unit label when possible.
 *
 * @param {number} value unit value
 * @param {string} unit Intl.NumberFormat unit key
 * @return {string}
 */
const formatUnit = (value, unit) => {
    if (typeof Intl !== 'undefined' && Intl.NumberFormat) {
        if (!UNIT_FORMATTERS.has(unit)) {
            try {
                UNIT_FORMATTERS.set(unit, new Intl.NumberFormat(undefined, {
                    style: 'unit',
                    unit,
                    unitDisplay: 'long',
                }));
            } catch {
                UNIT_FORMATTERS.set(unit, null);
            }
        }
        const formatter = UNIT_FORMATTERS.get(unit);
        if (formatter) {
            return formatter.format(value);
        }
    }

    const labels = UNIT_FALLBACK_LABELS[unit];
    return `${value} ${value === 1 ? labels[0] : labels[1]}`;
};

/**
 * Format an age in seconds using the same two-part style as Moodle format_time.
 *
 * @param {number} ageSeconds difference between now and timestamp, in seconds
 * @return {string}
 */
export const format = (ageSeconds) => {
    const age = Math.abs(Math.floor(ageSeconds));

    const years = Math.floor(age / YEAR);
    let remainder = age - (years * YEAR);
    const days = Math.floor(remainder / DAY);
    remainder = age - (days * DAY);
    const hours = Math.floor(remainder / HOUR);
    remainder = remainder - (hours * HOUR);
    const mins = Math.floor(remainder / MINUTE);
    const secs = remainder - (mins * MINUTE);

    if (years) {
        return [formatUnit(years, 'year'), days ? formatUnit(days, 'day') : ''].join(' ').trim();
    }
    if (days) {
        return [formatUnit(days, 'day'), hours ? formatUnit(hours, 'hour') : ''].join(' ').trim();
    }
    if (hours) {
        return [formatUnit(hours, 'hour'), mins ? formatUnit(mins, 'minute') : ''].join(' ').trim();
    }
    if (mins) {
        return [formatUnit(mins, 'minute'), secs ? formatUnit(secs, 'second') : ''].join(' ').trim();
    }
    if (secs) {
        return formatUnit(secs, 'second');
    }
    return 'now';
};

/**
 * Refresh all visible relative-time stamps once.
 */
const tick = () => {
    const now = Date.now() / 1000;
    document.querySelectorAll('[data-region="time-ago"][data-timestamp]').forEach(el => {
        const ts = parseInt(el.dataset.timestamp, 10);
        if (Number.isNaN(ts)) {
            return;
        }
        const relative = format(now - ts);
        el.textContent = (relative === 'now') ? relative : relativeTemplate.replace('{$a}', relative);
    });
};

/**
 * Start the page-wide ticker if not already running. Refreshes every 30 seconds.
 */
export const startTicker = async() => {
    if (started) {
        return;
    }
    started = true;

    try {
        // Fetch the template by passing the literal "{$a}" placeholder through; core/str
        // does a single non-recursive replace, so we get the raw template back to reuse
        // per tick rather than calling getString per element.
        relativeTemplate = await getString('relativetime', 'filter_embeddiscussion', '{$a}');
    } catch {
        relativeTemplate = '{$a}';
    }

    setInterval(tick, 30 * 1000);
};
