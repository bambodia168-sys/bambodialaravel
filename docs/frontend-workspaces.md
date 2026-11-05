# Frontend and Account Workspaces

## Public discovery

The Bambodia homepage displays actual approved marketplace listings (maximum six), real active categories and counts, and the latest approved open jobs (maximum four). It is not seeded with demo content, and empty states appear when there is nothing published. Saved Appearance settings control the hero text, search/categories and default light/dark theme.

Marketplace search accepts text, type, active category, numeric USD minimum and maximum prices, and whitelisted sort order: newest, oldest, lower price, higher price or title. Listings are paginated ten per page. Products require an active linked license and approved cover image before discovery. Listing details include related approved listings and private saved-listing actions.

The public jobs page supports searches, the established allowed job categories, newest/lowest-budget/highest-budget sorting, and ten job cards per page. Unpublished, rejected and closed jobs never appear publicly.

## Private bookmarks

Every authenticated role can store approved listings in /account/saved-listings and published jobs in /account/saved-jobs. Saving is idempotent because the database enforces unique account/item pairs. Removal is scoped to the current signed-in account. After an item is unpublished or closed, the collection hides its nonpublic details but still allows removal. The Save and Saved state appears on homepage, search and detail cards. A bookmark is not an entitlement, application, contract or payment.

## Creator portfolio opt-in

Only existing creator roles may edit /studio/profile. Creators provide a professional headline, biography and optional HTTPS portfolio link. Profiles are private unless the creator explicitly selects Public. Published profiles appear in searchable /creators (ten per page) and at /creators/{eight-digit-public-user-id}.

Public creator pages include display name, public ID, opted-in profile text and only eligible approved listings. They never expose account email, private messages, drafts, financial information, unpublished files, proposals or private account records. The platform does not independently verify creator biographies, external websites or professional qualifications.

## Account dashboards

All account roles have real counts for saved listings, saved jobs, unread notifications, and currently offered/active/disputed contracts involving them. Customers/Subscribers/Super Admin clients also see their submitted jobs. Freelancer users see their proposal counts. Creator accounts see the number of creator listings and open inquiries, with separate Creator Studio counts for approved and pending items. The most recent five personal notifications appear with working read actions. Staff/Admin dashboards retain existing role permissions.

## Security and release gates

User-specific saves use CSRF-protected POST/DELETE, throttling, unique per-account constraints and audit records. Search and sorting parameters are whitelisted and not used to construct unsafe SQL. Public creator profiles require explicit opt-in, and all published products pass license/media checks. User-supplied text is HTML-escaped and external links open with rel=noopener noreferrer.

All related styles remain consolidated in resources/css/app.css and source files use version-free names.

Before release, separately test aaPanel/cPanel migrations, physical devices, accessible keyboard/mobile behavior, regional translations, file scanning, content rights, privacy compliance and legal review. Payment gateways, checkout, escrow, refunds, payouts, taxation settlement and real financial transactions remain disabled. Do not run the clean-install wizard against an existing populated customer database.
