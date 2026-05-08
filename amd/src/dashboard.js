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
 * Embedded discussion dashboard bootstrapper.
 *
 * Listens for {discussion:dashboard} placeholders and hydrates them with
 * a list of new posts in the course since the user's last visit.
 *
 * @module     filter_embeddiscussion/dashboard
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {startTicker} from 'filter_embeddiscussion/timeago';

const SEL_ROOT = '[data-region="filter-embeddiscussion-dashboard"]';
const LAZY_ROOT_MARGIN = '100px 0px';

const initialised = new WeakSet();

let lazyObserver = null;

const getLazyObserver = () => {
    if (lazyObserver || typeof IntersectionObserver === 'undefined') {
        return lazyObserver;
    }
    lazyObserver = new IntersectionObserver((entries, observer) => {
        entries.forEach(entry => {
            if (!entry.isIntersecting) {
                return;
            }
            observer.unobserve(entry.target);
            load(entry.target);
        });
    }, {rootMargin: LAZY_ROOT_MARGIN});
    return lazyObserver;
};

const render = async(root, data) => {
    const {html, js} = await Templates.renderForPromise('filter_embeddiscussion/dashboard', data);
    await Templates.replaceNodeContents(root, html, js);
};

const load = (root) => {
    const courseid = parseInt(root.dataset.courseid, 10);
    Ajax.call([{
        methodname: 'filter_embeddiscussion_get_dashboard',
        args: {courseid},
    }])[0].then(data => render(root, data)).catch(Notification.exception);
};

/**
 * Public entry point. Bootstraps every dashboard placeholder on the page,
 * deferring the AJAX fetch until each placeholder is near the viewport.
 */
export const init = () => {
    const observer = getLazyObserver();
    document.querySelectorAll(SEL_ROOT).forEach(el => {
        if (initialised.has(el)) {
            return;
        }
        initialised.add(el);
        if (observer) {
            observer.observe(el);
        } else {
            load(el);
        }
    });
    startTicker();
};
