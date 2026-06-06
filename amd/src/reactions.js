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
 * Emoji reactions rendering helpers (GitHub-style compact pill + picker).
 *
 * Ported from local_reactions/utils so fixes can flow between the two plugins.
 * The filter renders the whole thread itself, so this module only needs to build
 * the bar template context and inject/replace the bar inside a post — there is no
 * IndexedDB cache, polling, skeleton or mutation observer.
 *
 * @module     filter_embeddiscussion/reactions
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Templates from 'core/templates';

const TEMPLATE_BAR = 'filter_embeddiscussion/reactions_bar';
const SEL_POST_ACTIONS = '[data-region="post-actions"]';
const SEL_BAR = '[data-region="reactions-bar"]';
const SEL_PICKER = '[data-region="reactions-picker"]';
const SEL_WRAPPER = '.embeddisc-reactions-picker-wrapper';

/**
 * Render a Mustache template and return its first element plus the JS to run.
 *
 * @param {string} templateName
 * @param {Object} context
 * @returns {Promise<{element: HTMLElement, js: string}>}
 */
export const renderToElement = async(templateName, context) => {
    const {html, js} = await Templates.renderForPromise(templateName, context);
    const container = document.createElement('div');
    container.innerHTML = html;
    return {element: container.firstElementChild, js};
};

/**
 * Build the reactions bar Mustache context from a post's reaction data.
 *
 * @param {Object} data Reaction data with a `counts` array of {emoji, count}.
 * @param {Object} emojis Map of shortcode => unicode, in display order.
 * @param {Object} [options={}]
 * @param {boolean} [options.canreact=false]
 * @param {boolean} [options.compactview=false]
 * @param {string[]} [options.userreactions=[]] Shortcodes the viewer has reacted with.
 * @returns {Object} Template context.
 */
export const buildTemplateContext = (data, emojis, {canreact = false, compactview = false, userreactions = []} = {}) => {
    const countsMap = {};
    (data?.counts || []).forEach((c) => {
        countsMap[c.emoji] = c.count;
    });

    const buttons = [];
    let totalCount = 0;
    const reactedEmojis = [];
    let hasAnySelected = false;

    for (const [shortcode, unicode] of Object.entries(emojis)) {
        const count = countsMap[shortcode] || 0;
        const isSelected = userreactions.includes(shortcode);
        buttons.push({
            shortcode: shortcode,
            unicode: unicode,
            count: count,
            hascount: count > 0,
            selected: isSelected,
            canreact: canreact,
        });
        if (count > 0) {
            totalCount += count;
            reactedEmojis.push({unicode: unicode});
            if (isSelected) {
                hasAnySelected = true;
            }
        }
    }

    return {
        buttons: buttons,
        canreact: canreact,
        compactview: compactview,
        hasanycount: totalCount > 0,
        totalcount: totalCount,
        reactedEmojis: reactedEmojis,
        selected: hasAnySelected,
    };
};

/**
 * Render (or re-render) the compact reactions bar inside a post element.
 *
 * @param {HTMLElement} postEl The post root ([data-region="post"]).
 * @param {Object} data Reaction data ({counts, userreactions}).
 * @param {Object} cfg
 * @param {Object} cfg.emojis Map of shortcode => unicode.
 * @param {boolean} cfg.canreact Whether the viewer can react.
 * @returns {Promise<?HTMLElement>} The rendered bar, or null if there is nowhere to put it.
 */
export const renderReactionsBarInto = async(postEl, data, {emojis, canreact}) => {
    const actions = postEl.querySelector(SEL_POST_ACTIONS);
    if (!actions) {
        return null;
    }
    const context = buildTemplateContext(data, emojis, {
        canreact: canreact,
        compactview: true,
        userreactions: (data && data.userreactions) || [],
    });
    const {element, js} = await renderToElement(TEMPLATE_BAR, context);
    const existing = actions.querySelector(SEL_BAR);
    if (existing) {
        existing.replaceWith(element);
    } else {
        actions.prepend(element);
    }
    Templates.runTemplateJS(js);
    return element;
};

/**
 * Close every open emoji picker under the given root.
 *
 * @param {ParentNode} [root=document]
 */
export const closeAllPickers = (root = document) => {
    root.querySelectorAll(`${SEL_PICKER}:not([hidden])`).forEach((picker) => {
        picker.hidden = true;
        const trigger = picker.closest(SEL_WRAPPER)?.querySelector('[data-action="open-picker"]');
        if (trigger) {
            trigger.setAttribute('aria-expanded', 'false');
        }
    });
};

/**
 * Toggle the emoji picker belonging to the bar that owns the given trigger.
 * Positioned with fixed coordinates to escape overflow:hidden parents.
 *
 * @param {HTMLElement} trigger The clicked open-picker control (smiley or compact pill).
 */
export const openPicker = (trigger) => {
    const bar = trigger.closest(SEL_BAR);
    const picker = bar ? bar.querySelector(SEL_PICKER) : null;
    if (!picker) {
        return;
    }
    const isOpen = !picker.hidden;
    closeAllPickers();
    if (isOpen) {
        return;
    }
    const rect = trigger.getBoundingClientRect();
    picker.style.left = rect.left + 'px';
    picker.hidden = false;
    // Calculate top now that it's visible and has a real height.
    picker.style.top = (rect.top - picker.offsetHeight - 6) + 'px';
    const smiley = bar.querySelector('[data-action="open-picker"].embeddisc-reactions-trigger');
    if (smiley) {
        smiley.setAttribute('aria-expanded', 'true');
    }
};
