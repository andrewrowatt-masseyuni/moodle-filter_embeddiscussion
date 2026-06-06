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
 * Embedded discussion bootstrapper / controller.
 *
 * @module     filter_embeddiscussion/discussion
 * @copyright  2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Templates from 'core/templates';
import Notification from 'core/notification';
import {get_string as getString} from 'core/str';
import {loadQuill, makeEditor} from 'filter_embeddiscussion/editor';
import {startTicker, tick as tickTimeAgo, format} from 'filter_embeddiscussion/timeago';
import {renderReactionsBarInto, openPicker, closeAllPickers} from 'filter_embeddiscussion/reactions';

const SEL_ROOT = '[data-region="filter-embeddiscussion"]';
const MAX_VISUAL_INDENT = 3;
// Start fetching when the placeholder is within ~one viewport of the scroll position.
const LAZY_ROOT_MARGIN = '100px 0px';
// Hash format used by the dashboard to deep-link to a specific post. Captures the post id.
const HASH_POST_RE = /^#embeddisc-post-(\d+)$/;

const initialised = new WeakSet();

let lazyObserver = null;
let pickerCloserBound = false;

/**
 * Close any open emoji picker when the user clicks outside a reactions bar.
 * Bound once for the page; clicks inside a bar are handled by the discussion's
 * own delegated handler.
 */
const bindPickerCloser = () => {
    if (pickerCloserBound) {
        return;
    }
    pickerCloserBound = true;
    document.addEventListener('click', (e) => {
        if (!e.target.closest('[data-region="reactions-bar"]')) {
            closeAllPickers();
        }
    });
};

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
            const d = new Discussion(entry.target);
            d.load();
        });
    }, {rootMargin: LAZY_ROOT_MARGIN});
    return lazyObserver;
};

/**
 * If the URL points at a specific post via #embeddisc-post-N, return that id;
 * otherwise null. Used to bypass the lazy-load wait so the targeted post
 * scrolls into view immediately.
 *
 * @returns {?number}
 */
const targetedPostId = () => {
    const match = HASH_POST_RE.exec(window.location.hash || '');
    return match ? parseInt(match[1], 10) : null;
};

/**
 * Public entry point. Bootstraps every embedded discussion placeholder on the page,
 * deferring the thread fetch until each placeholder is near the viewport.
 */
export const init = () => {
    bindPickerCloser();
    const targeted = targetedPostId();
    const observer = targeted === null ? getLazyObserver() : null;
    document.querySelectorAll(SEL_ROOT).forEach(el => {
        if (initialised.has(el)) {
            return;
        }
        initialised.add(el);
        if (observer) {
            observer.observe(el);
        } else {
            // Either the browser lacks IntersectionObserver, or the URL points at
            // a specific post — eager-load so the anchor target renders immediately.
            const d = new Discussion(el);
            if (targeted !== null) {
                d.scrollToPostId = targeted;
            }
            d.load();
        }
    });
    startTicker();
};

class Discussion {
    constructor(root) {
        this.root = root;
        this.threadid = parseInt(root.dataset.threadid, 10);
        this.thread = null; // Server payload.
        this.emojis = {}; // Map of shortcode => unicode, in display order.
        this.sortMode = 'oldest';
        this.composerEditor = null;
        this.activeReply = null; // {parentId, container, editor}.
        this.activeEdit = null; // {postId, container, editor, originalContent}.
        this.scrollToPostId = null; // Set by init() when the URL hash targets a post.
    }

    load() {
        Ajax.call([{
            methodname: 'filter_embeddiscussion_get_thread',
            args: {
                threadid: this.threadid,
            },
        }])[0].then(data => this.render(data))
            .catch(Notification.exception);
    }

    async render(data) {
        this.thread = data;
        this.emojis = {};
        (data.emojis || []).forEach(e => {
            this.emojis[e.shortcode] = e.unicode;
        });
        const html = await Templates.renderForPromise('filter_embeddiscussion/discussion', data);
        await Templates.replaceNodeContents(this.root, html.html, html.js);
        await this.renderPosts();
        this.bind();
        if (this.scrollToPostId !== null) {
            const target = this.root.querySelector(`[data-postid="${this.scrollToPostId}"]`);
            if (target) {
                target.scrollIntoView({behavior: 'smooth', block: 'start'});
            }
        }
    }

    /**
     * Render the post list into [data-region="posts"] respecting current sort.
     */
    async renderPosts() {
        const container = this.root.querySelector('[data-region="posts"]');
        const empty = this.root.querySelector('[data-region="empty"]');
        const countEl = this.root.querySelector('[data-region="post-count"]');
        if (!container) {
            return;
        }
        container.innerHTML = '';

        const posts = this.thread.posts.map(p => ({...p, indent: 0}));

        // Sort top-level by chosen mode; replies always chronological.
        const tops = posts.filter(p => p.parentid === 0);
        tops.sort((a, b) => this.sortMode === 'newest'
            ? b.timecreated - a.timecreated
            : a.timecreated - b.timecreated);

        for (const top of tops) {
            await this.appendTree(container, top, 0, posts);
        }

        if (empty) {
            empty.classList.toggle('d-none', this.thread.postcount > 0);
        }

        if (countEl) {
            const key = this.thread.postcount === 1 ? 'onecomment' : 'comments';
            countEl.textContent = await getString(key, 'filter_embeddiscussion', this.thread.postcount);
        }

        tickTimeAgo();
    }

    /**
     * Recursively append a post and its replies into a container.
     *
     * @param {HTMLElement} container
     * @param {object} post
     * @param {number} depth visual indent depth (0..MAX_VISUAL_INDENT)
     * @param {Array<object>} all flat list of all posts in the thread
     */
    async appendTree(container, post, depth, all) {
        const ctx = {...post, indent: Math.min(depth, MAX_VISUAL_INDENT)};
        const {html, js} = await Templates.renderForPromise('filter_embeddiscussion/post', ctx);
        const tmp = document.createElement('div');
        tmp.innerHTML = html;
        const node = tmp.firstElementChild;
        // Inject the emoji reactions bar before the node is attached (no flash).
        if (!post.deleted) {
            await renderReactionsBarInto(node, post.reactions, {
                emojis: this.emojis,
                canreact: this.thread.canreact,
            });
        }
        container.appendChild(node);
        Templates.runTemplateJS(js);

        const repliesContainer = node.querySelector('[data-region="replies"]');
        const children = all.filter(p => p.parentid === post.id)
            .sort((a, b) => a.timecreated - b.timecreated);
        for (const child of children) {
            await this.appendTree(repliesContainer, child, depth + 1, all);
        }
    }

    /**
     * Wire up delegated event handlers.
     */
    bind() {
        this.root.addEventListener('click', this.onClick.bind(this));
    }

    onClick(e) {
        const target = e.target.closest('[data-action]');
        if (!target || !this.root.contains(target)) {
            return;
        }
        const action = target.dataset.action;
        switch (action) {
            case 'open-composer': this.openComposer(); break;
            case 'cancel-compose': this.cancelComposer(); break;
            case 'submit-compose': this.submitComposer(); break;
            case 'sort': this.changeSort(target.dataset.sort); break;
            case 'reply': this.openReply(target); break;
            case 'edit': this.openEdit(target); break;
            case 'delete': this.confirmDelete(target); break;
            case 'open-picker': openPicker(target); break;
            case 'toggle-reaction': this.toggleReaction(target); break;
            default:
        }
    }

    async openComposer() {
        const collapsed = this.root.querySelector('[data-region="composer-collapsed"]');
        const expanded = this.root.querySelector('[data-region="composer-expanded"]');
        if (!collapsed || !expanded) {
            return;
        }
        collapsed.classList.add('d-none');
        expanded.classList.remove('d-none');
        if (!this.composerEditor) {
            await loadQuill();
            this.composerEditor = makeEditor(expanded.querySelector('[data-region="editor"]'));
        }
        this.composerEditor.focus();
    }

    cancelComposer() {
        const collapsed = this.root.querySelector('[data-region="composer-collapsed"]');
        const expanded = this.root.querySelector('[data-region="composer-expanded"]');
        if (this.composerEditor) {
            this.composerEditor.setText('');
        }
        expanded.classList.add('d-none');
        collapsed.classList.remove('d-none');
    }

    async submitComposer() {
        if (!this.composerEditor) {
            return;
        }
        const html = this.composerEditor.root.innerHTML.trim();
        if (!html || html === '<p><br></p>') {
            return;
        }
        try {
            const data = await Ajax.call([{
                methodname: 'filter_embeddiscussion_create_post',
                args: {
                    threadid: this.thread.threadid,
                    parentid: 0,
                    content: html,
                },
            }])[0];
            this.thread = data;
            this.composerEditor.setText('');
            this.cancelComposer();
            await this.renderPosts();
        } catch (e) {
            Notification.exception(e);
        }
    }

    async changeSort(sort) {
        this.sortMode = sort;
        this.root.querySelectorAll('[data-action="sort"]').forEach(btn => {
            btn.classList.toggle('embeddisc-sort-active', btn.dataset.sort === sort);
        });
        await this.renderPosts();
    }

    async openReply(button) {
        const postEl = button.closest('[data-region="post"]');
        if (!postEl) {
            return;
        }
        const parentId = parseInt(postEl.dataset.postid, 10);
        const repliesEl = postEl.querySelector('[data-region="replies"]');
        if (!repliesEl) {
            return;
        }
        if (this.activeReply) {
            this.closeReply();
        }
        await loadQuill();
        const cancelStr = await getString('cancel', 'filter_embeddiscussion');
        const postStr = await getString('post', 'filter_embeddiscussion');
        const wrap = document.createElement('div');
        wrap.className = 'embeddisc-inline-composer';
        wrap.innerHTML =
            '<div class="embeddisc-editor" data-region="reply-editor"></div>' +
            '<div class="embeddisc-composer-actions">' +
            `<button type="button" class="btn btn-link" data-action="cancel-reply">${cancelStr}</button>` +
            `<button type="button" class="btn btn-primary" data-action="submit-reply">${postStr}</button>` +
            '</div>';
        repliesEl.prepend(wrap);
        const editor = makeEditor(wrap.querySelector('[data-region="reply-editor"]'));
        editor.focus();
        this.activeReply = {parentId, container: wrap, editor};
        wrap.querySelector('[data-action="cancel-reply"]').addEventListener('click', () => this.closeReply());
        wrap.querySelector('[data-action="submit-reply"]').addEventListener('click', () => this.submitReply());
    }

    closeReply() {
        if (!this.activeReply) {
            return;
        }
        this.activeReply.container.remove();
        this.activeReply = null;
    }

    async submitReply() {
        if (!this.activeReply) {
            return;
        }
        const html = this.activeReply.editor.root.innerHTML.trim();
        if (!html || html === '<p><br></p>') {
            return;
        }
        try {
            const data = await Ajax.call([{
                methodname: 'filter_embeddiscussion_create_post',
                args: {
                    threadid: this.thread.threadid,
                    parentid: this.activeReply.parentId,
                    content: html,
                },
            }])[0];
            this.thread = data;
            this.closeReply();
            await this.renderPosts();
        } catch (e) {
            Notification.exception(e);
        }
    }

    async openEdit(button) {
        const postEl = button.closest('[data-region="post"]');
        if (!postEl) {
            return;
        }
        const postId = parseInt(postEl.dataset.postid, 10);
        const contentEl = postEl.querySelector('[data-region="post-content"]');
        if (!contentEl) {
            return;
        }
        if (this.activeEdit) {
            this.closeEdit();
        }
        await loadQuill();
        const cancelStr = await getString('cancel', 'filter_embeddiscussion');
        const saveStr = await getString('save', 'filter_embeddiscussion');
        const original = contentEl.innerHTML;
        const wrap = document.createElement('div');
        wrap.className = 'embeddisc-inline-composer';
        wrap.innerHTML =
            '<div class="embeddisc-editor" data-region="edit-editor"></div>' +
            '<div class="embeddisc-composer-actions">' +
            `<button type="button" class="btn btn-link" data-action="cancel-edit">${cancelStr}</button>` +
            `<button type="button" class="btn btn-primary" data-action="submit-edit">${saveStr}</button>` +
            '</div>';
        contentEl.replaceWith(wrap);
        const editor = makeEditor(wrap.querySelector('[data-region="edit-editor"]'));
        editor.root.innerHTML = original;
        editor.focus();
        this.activeEdit = {postId, container: wrap, editor, originalContent: original};
        wrap.querySelector('[data-action="cancel-edit"]').addEventListener('click', () => this.closeEdit());
        wrap.querySelector('[data-action="submit-edit"]').addEventListener('click', () => this.submitEdit());
    }

    closeEdit() {
        if (!this.activeEdit) {
            return;
        }
        const restore = document.createElement('div');
        restore.className = 'embeddisc-post-content';
        restore.dataset.region = 'post-content';
        restore.innerHTML = this.activeEdit.originalContent;
        this.activeEdit.container.replaceWith(restore);
        this.activeEdit = null;
    }

    async submitEdit() {
        if (!this.activeEdit) {
            return;
        }
        const html = this.activeEdit.editor.root.innerHTML.trim();
        if (!html || html === '<p><br></p>') {
            return;
        }
        try {
            const data = await Ajax.call([{
                methodname: 'filter_embeddiscussion_edit_post',
                args: {
                    postid: this.activeEdit.postId,
                    content: html,
                },
            }])[0];
            this.thread = data;
            this.activeEdit = null;
            await this.renderPosts();
        } catch (e) {
            Notification.exception(e);
        }
    }

    async confirmDelete(button) {
        const postEl = button.closest('[data-region="post"]');
        if (!postEl) {
            return;
        }
        const postId = parseInt(postEl.dataset.postid, 10);
        try {
            await Notification.deleteCancelPromise(
                await getString('delete', 'filter_embeddiscussion'),
                await getString('deleteconfirm', 'filter_embeddiscussion')
            );
        } catch {
            return;
        }
        try {
            const data = await Ajax.call([{
                methodname: 'filter_embeddiscussion_delete_post',
                args: {postid: postId},
            }])[0];
            this.thread = data;
            await this.renderPosts();
        } catch (e) {
            Notification.exception(e);
        }
    }

    /**
     * Toggle an emoji reaction on a post and re-render its reactions bar in place.
     *
     * @param {HTMLElement} button the clicked toggle-reaction control
     */
    async toggleReaction(button) {
        const postEl = button.closest('[data-region="post"]');
        if (!postEl) {
            return;
        }
        const postId = parseInt(postEl.dataset.postid, 10);
        const emoji = button.dataset.emoji;
        const post = this.thread.posts.find(p => p.id === postId);
        closeAllPickers();
        try {
            const result = await Ajax.call([{
                methodname: 'filter_embeddiscussion_react_post',
                args: {postid: postId, emoji},
            }])[0];
            const reactions = {counts: result.counts, userreactions: result.userreactions};
            // Update local model and re-render the bar in place.
            if (post) {
                post.reactions = reactions;
            }
            await renderReactionsBarInto(postEl, reactions, {
                emojis: this.emojis,
                canreact: this.thread.canreact,
            });
        } catch (e) {
            Notification.exception(e);
        }
    }

}

// Re-export format so tests can import it.
export {format};
