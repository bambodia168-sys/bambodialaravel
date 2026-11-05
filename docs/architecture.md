# Bambodia — Application Architecture

Version: 0.0.1
Author: Ton Samith — https://tonsamith.com
Runtime: Laravel 13, PHP 8.3+, MySQL 8+, Tailwind CSS 4, JavaScript.

## Release policy

The active repository is source code. It is not a validated production installer bundle. Deployment requires a complete vendor directory and compiled Vite assets. Do not remove any existing website until you have an independently tested release and rollback backup.

## Identity and URL contracts

- User: 8-digit random public_id with a database unique constraint. /account?={public_id}, /studio?={public_id}; both routes enforce account ownership and authentication.
- Marketplace listings: 9-digit random public_id and canonical /product?={id}-{slug}, /project?={id}-{slug}, /freelance?={id}-{slug}, /service?={id}-{slug}.
- Policy: /policies?={id}-{slug} with published-only access.
- CMS pages/posts: /{9-digit-public-id}_{slug} with published-only access.
- Database numeric id columns remain internal Eloquent primary keys; public IDs are not authorization tokens.

## Live, implemented source modules

- Five-step installer (requirements, database, application, administrator, finish). Pre-Laravel entry exists only before installed.lock.
- Auth: registration, login, logout, password-reset request and token reset (email provider configuration required for email delivery).
- Public user identities, roles, account profile editing and language preference storage.
- Admin role-controlled dashboard, user table with search, ten items per page and a role-edit modal.
- Creator listing submission, pending moderation, approval/rejection, published-only marketplace search and detail pages.
- Policy drafts, administrator-reviewed publication, searchable public policy index and document pages.
- Page and post draft/published CMS with unique public URLs and blog listing.
- Authenticated ticket creation, ticket privacy, customer conversations and staff status/reply workflow.
- Shared responsive theme and styles using one resources/css/app.css and one resources/js/app.js.

## Role boundaries

Super Admin: system administration, user roles, reviews, content, support.
Admin: reviews, pages, posts, policies and support.
Staff: operational overview and support tickets.
Customer / Subscriber: account and personal support tickets.
Freelancer: account, creator studio, listing submission and support.
Roles are protected by server-side Laravel middleware, not only by hiding menu links.

## Not implemented / never shown as working

Purchases, checkout, external payment gateways, refunds, payouts, tax invoices, fulfillment and license-key delivery; freelance contracts and escrow; job applications; verified resumes; media library; API token vault; plugin execution; security incident analytics; live chat; email provider management; international policy/legal certification; per-locale translated text; and other requested manager modules outside the current implemented workflow.

No financial transaction must be accepted until provider-side verification, refunds/chargeback handling, payout accounting and merchant eligibility are independently validated. A legal professional must approve and publish policies appropriate to each served jurisdiction.

## Naming and file governance

Avoid version suffixes on CSS, JavaScript, PHP, Blade and documentation filenames. Put versions in Git tags/release metadata. Build new modules only when they have migrations, validation, permissions, user-visible actions, tests and verified operation. Keep nonfunctional controls out of navigation.

## Installation and security

Use a new empty database; current installer refuses existing tables and existing environment configuration. Use the private access code stored at storage/app/installer-access.txt; it is not a product purchase code and does not verify a license. Remove access file automatically after successful setup. Keep the web server document root at public/, block access to private directories, enable HTTPS, configure SMTP if password reset emails are needed, and test on isolated aaPanel/cPanel before production cutover.

A one-click release archive must include Composer vendor dependencies and compiled public/build assets, or the wizard correctly refuses to proceed.
