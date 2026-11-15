# Bambodia taxonomy: ten Job categories and three Product subcategories

Status: proposed code only. **Not automatically applied to production.** Preserve the
40 previously imported Project/Product/Freelance/Service parent categories and all
existing published listings and jobs.

## Scope

- Store ten active `job` rows in `marketplace_categories`, each with an official slug,
  name, public ID, description, order, and administrator creator.
- Store three active Product children below the existing **Plugins & Extensions** parent:
  Browser Extensions, Laravel Packages, Integrations.
- Do not fold WordPress Plugins into Plugins & Extensions.
- Use the database as source of truth for Job creation and Job filters. Keep legacy
  category strings readable and filterable for existing jobs; do not overwrite old data.
- Keep all Job approval, customer ownership, and published-content protections.
- Product parent searches include immediate children; child filters remain specific.

## Isolated aaPanel staging acceptance

1. Take a tested database snapshot of an isolated clone. Confirm 40 original categories
   are present and existing published counts are recorded.
2. Install the feature branch code in the isolated clone, then run migrations:
   `php artisan migrate --force`
3. Seed the 13 entries idempotently:
   `php artisan db:seed --class=Database\\Seeders\\BambodiaTaxonomyThirteenSeeder --force`
4. Verify with read-only command:
   `php artisan bambodia:taxonomy:verify`
5. Repeat seeding and verifier to confirm there are still exactly 13 matching new
   entries and no duplicates.
6. In Category Manager, check Job type, Product parent selector, 10/10 Job names,
   3/3 Product children, and no change to 40 existing category records.
7. In Builder Studio, check new Job category options and Product child choices.
   Create **unpublished** test items only, then remove them.
8. Job public filter: all new slugs work; preexisting old-category jobs still show
   and can be filtered. Editing an old job preserves its category unless the user
   explicitly changes it.
9. Marketplace search: selecting parent Plugins & Extensions includes its children;
   selecting an individual child returns that child only; other types remain isolated.
10. Test admin validation (wrong parent type, nested parent, self-parent, type changes
    with existing listings/jobs/children) and ownership/approval protections.
11. Check backup/restore, migration compatibility (MySQL), view compilation,
    PHP syntax, tests, and mobile browser form behavior.

## Production rollout guard

Do not merge or run the migration on the live site before the isolated QA above.
Deploy together: source + schema migration + controlled seed + verifier.
Do not rename, reassign, close, or republish any existing listings or jobs.
If any acceptance fails, do not activate the new category selectors on production.
Rollback must use the captured database backup; dropping the hierarchy column
would destroy parent links and is not a safe automatic reversal after use.
