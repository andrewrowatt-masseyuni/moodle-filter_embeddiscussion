# Embedded discussion filter (filter_embeddiscussion)

Part of **embedded discussions** - see `local/embeddiscussion/README.md` for the design, which covers
all three plugins:

```
local/embeddiscussion
filter/embeddiscussion
lib/editor/tiny/plugins/embeddiscussion
```

Replaces each embedded discussion token -
`<div class="embeddiscussion-embed" data-embeddiscussion="KEY"></div>`, put there by the editor's
**Embedded discussion** button - with a placeholder the discussion loads into. The key names a thread
in `local_embeddiscussion`, where its anonymity lives; the filter only finds the thread in the
place the text belongs to - the activity, or for a section summary any of the course's sections, since
a filter is not told which section it is formatting.

Nothing is ever created here. A token whose thread belongs somewhere else - pasted in from another
activity, say - shows a short notice to someone who may embed discussions, and nothing to anyone else.
A token anywhere no discussion can belong (the front page, a block, a user's profile) is removed.

The filter switches itself on site-wide when installed, at the end of the filter order. It must stay
**On**: without it no one sees any discussion.

Requires `local_embeddiscussion`. GNU GPL v3 or later.
