# filter_embeddiscussion

A Moodle text filter that converts `{discussion}` (and related) tokens into embedded
discussion threads inside Books, Pages, Labels, section summaries, and other
filter-rendered content.

## Capabilities

| Capability | Default roles | Purpose |
| --- | --- | --- |
| `filter/embeddiscussion:createpost` | student, teacher, editingteacher, manager | Post and reply in an embedded discussion |
| `filter/embeddiscussion:editownpost` | student, teacher, editingteacher, manager | Edit the user's own posts |
| `filter/embeddiscussion:deleteownpost` | student, teacher, editingteacher, manager | Delete the user's own posts |
| `filter/embeddiscussion:manageposts` | teacher, editingteacher, manager | Edit any post |
| `filter/embeddiscussion:deleteanypost` | editingteacher, manager | Delete any post |
| `filter/embeddiscussion:managethreads` | editingteacher, manager | View and manage all threads in a course |
| `filter/embeddiscussion:createthread` | teacher, editingteacher, manager | Initialise a new thread record (or refresh its name / anonymous flag from the token) |

## Thread initialisation and `createthread`

A thread record is created the first time someone with the
`filter/embeddiscussion:createthread` capability views a page that contains a
`{discussion}` token. Until that happens, users without the capability — students
by default — see the surrounding text but no embedded discussion: the placeholder
is dropped silently so that read-only viewers cannot mass-create empty thread
records.

In normal teacher-led use this is the right default: an editor visits the page
to author or review content, the thread is initialised, and students see a live
discussion the next time they visit.

### When to grant `createthread` to students

Consider enabling `filter/embeddiscussion:createthread` for the `student`
archetype (or for an authenticated-user role) if your site does **autonomous
course rollovers** that produce many embedded discussions which may never be
visited by an editor before students arrive. Without this, those threads stay
uninitialised and the discussion silently fails to appear for students.

To grant it site-wide, edit the role definition under *Site administration →
Users → Permissions → Define roles* and set
`filter/embeddiscussion:createthread` to **Allow** for the relevant role.
