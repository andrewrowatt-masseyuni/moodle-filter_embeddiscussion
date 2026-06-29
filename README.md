# Embedded discussion filter (filter_embeddiscussion)

A Moodle text filter that turns simple tokens such as `{discussion}` into
inline, threaded discussions. Authors drop a token into any filter-rendered
content — a Book chapter, Page, Label, section summary, and so on — and the
filter replaces it with a live discussion where users can post, reply, edit,
delete and react to comments without leaving the page.

## Features

- Embed a discussion anywhere the standard text filters run, using a token.
- Optional **anonymous** mode where students see each other under stable
  two-word handles (for example "Bright Ibis") while staff still see real names.
- Emoji reactions on posts, configured site-wide.
- A per-course `{discussiondashboard}` that aggregates recent activity across
  the discussions a user can see.
- Optional backward compatibility with the legacy `[[filter_disqus]]` and
  `{comments}` tokens.

## Requirements

- Moodle 4.5 (build 2024100700) or later. Supported on Moodle 4.5 and 5.1.

## Installation

1. Copy the plugin into the `filter/embeddiscussion` directory of your Moodle
   site (or install the ZIP via *Site administration → Plugins → Install
   plugins*).
2. Log in as an administrator and complete the upgrade when prompted.
3. Enable the filter at *Site administration → Plugins → Filters → Manage
   filters* and set it to apply to content (and, if desired, headings).

## Usage

Add one of the following tokens to any content processed by filters:

| Token | Result |
| --- | --- |
| `{discussion:Thread name}` | An embedded discussion with the given name. |
| `{discussion}` | Inside a Book chapter the name defaults to "*Book \ Chapter*"; elsewhere a name is required. |
| `{anondiscussion:Thread name}` | An anonymous discussion (alias: `{anonymousdiscussion:...}`). |
| `{discussiondashboard}` | A summary of recent activity across the current course's discussions. |

The same token in the same location always resolves to the same thread, so
content can be backed up, restored and revisited without losing posts.

## Settings

Settings live at *Site administration → Plugins → Filters → Embedded
discussion*:

- **Legacy tokens** — optionally convert `[[filter_disqus]]` and `{comments}`
  to `{discussion}` during filtering. Both are off by default.
- **Anonymous handles** — the word lists (adjectives and animals) used to build
  anonymous handles.
- **Emoji set** — the `shortcode:emoji` pairs offered as reactions.

## Capabilities

- `filter/embeddiscussion:createpost`
- `filter/embeddiscussion:createthread`
- `filter/embeddiscussion:editownpost`
- `filter/embeddiscussion:deleteownpost`
- `filter/embeddiscussion:deleteanypost`
- `filter/embeddiscussion:manageposts`
- `filter/embeddiscussion:managethreads`
- `filter/embeddiscussion:viewallauthorsinanonymousthreads`

## Privacy

The plugin stores users' posts, reactions and the anonymous handles assigned to
them, and implements the Moodle Privacy API for export and deletion.

## Third-party libraries

- [Quill](https://github.com/slab/quill) 2.0.3 (BSD 3-Clause) — rich text editor.

## License

Copyright 2026 Andrew Rowatt <A.J.Rowatt@massey.ac.nz>

Licensed under the GNU GPL v3 or later. See [LICENSE](LICENSE).
