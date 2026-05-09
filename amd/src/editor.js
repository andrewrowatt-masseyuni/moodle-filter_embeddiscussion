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
 * Quill loader and factory for filter_embeddiscussion.
 *
 * Loads the locally vendored Quill 2.x snow theme on demand and exposes
 * a small wrapper. The library is shipped under
 * filter/embeddiscussion/thirdparty/quill/ and declared in thirdpartylibs.xml.
 *
 * @module     filter_embeddiscussion/editor
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Config from 'core/config';

const QUILL_JS = `${Config.wwwroot}/filter/embeddiscussion/thirdparty/quill/quill.js`;
const QUILL_CSS = `${Config.wwwroot}/filter/embeddiscussion/thirdparty/quill/quill.snow.css`;

let quillPromise = null;

/**
 * Lazy-load Quill from CDN. Returns a promise that resolves once window.Quill exists.
 *
 * @return {Promise<*>}
 */
export const loadQuill = () => {
    if (window.Quill) {
        return Promise.resolve(window.Quill);
    }
    if (quillPromise) {
        return quillPromise;
    }
    if (!document.querySelector('link[data-embeddisc-quill]')) {
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = QUILL_CSS;
        link.dataset.embeddiscQuill = '1';
        document.head.appendChild(link);
    }
    // Quill's UMD bundle prefers AMD when define.amd is present, which makes
    // it register against Moodle's RequireJS instead of setting window.Quill.
    // Hide define for the duration of the load so the UMD wrapper falls
    // through to the browser-global branch.
    quillPromise = new Promise((resolve, reject) => {
        const savedDefine = window.define;
        window.define = undefined;
        const restore = () => {
            window.define = savedDefine;
        };
        const script = document.createElement('script');
        script.src = QUILL_JS;
        script.async = true;
        script.onload = () => {
            restore();
            resolve(window.Quill);
        };
        script.onerror = () => {
            restore();
            reject(new Error('Failed to load Quill'));
        };
        document.head.appendChild(script);
    });
    return quillPromise;
};

/**
 * Create a Quill snow-theme editor inside the given mount element.
 *
 * @param {HTMLElement} mount the host element
 * @return {*} a Quill instance
 */
export const makeEditor = (mount) => {
    const Quill = window.Quill;
    if (!Quill) {
        throw new Error('Quill is not loaded yet');
    }
    return new Quill(mount, {
        theme: 'snow',
        modules: {
            toolbar: [
                ['bold', 'italic', 'underline', 'strike'],
                ['link', 'blockquote'],
                ['image'],
                ['clean'],
            ],
        },
        placeholder: '',
    });
};
