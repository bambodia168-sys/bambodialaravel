# Bambodia action-button icon convention

## Effective rule

Text-labeled interactive actions use their label **without a decorative arrow after the text**. This applies globally to the public site, marketplace listings, creator pages, jobs, blog, support center, builder, account dashboards, administrator, and developer workspaces, including newly developed screens.

**Do:**
- `<a class="btn" href="...">View all posts</a>`
- `<button type="submit" class="btn primary">Save changes</button>`
- `<a href="...">Read</a>`
- `<span class="workspace-overview-row-action">Manage</span>`

**Don't:**
- `View all posts <x-icon name="arrow-up-right" .../>`
- `Explore all jobs <x-icon name="chevron-right" .../>`
- `Read <x-icon name="arrow-up-right" .../>`
- plain Unicode arrows (`↗`, `→`) appended to button labels.

Retain meaningful **icon-only** controls when the icon is the actual interface affordance (for example, carousel previous/next buttons, the arrow-only Back to Top button, and compact directional tools). Retain leading icons where they indicate the action type, such as a download or external resource, and directional arrows used in data values such as a license-domain change.

Do not change navigation destinations, action handlers, labels, access permissions, or pagination state to implement this visual convention. Use the shared Blade components rather than adding a new arrow in a view-specific button.

## Acceptance checks

- Inspect the public homepage, Blog, Latest Jobs, Discover Independent Talent, marketplace cards and detail actions, and support knowledge cards.
- Inspect authenticated account, creator, admin and developer workspaces.
- Run `php artisan test --filter=ActionButtonArrowPolicyTest` and the existing browser-emulation suite.
- Check mobile/desktop, light/dark, keyboard focus, carousel control accessibility and Back to Top.
- Never replace installed site-specific headers with an older source version just to remove an arrow.
