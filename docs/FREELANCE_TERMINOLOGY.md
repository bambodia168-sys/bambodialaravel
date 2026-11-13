# Bambodia terminology: Freelance, Freelancer and Creator

This guide governs **user-facing copy** in public pages, builder workflows, account screens, admin review pages, search, metadata, emails and translations. It does **not** authorize changes to persisted types, account roles, database values or existing URLs.

| Word | Meaning | Correct examples |
| --- | --- | --- |
| **Freelance** | A kind of work or a marketplace listing type | Freelance, Freelance Offers, Freelance collection, Freelance work |
| **Freelancer** | One person with the applicable account role | Freelancer profile, Contact this freelancer |
| **Freelancers** | Two or more people | Freelancers, Browse freelancers (only when the destination lists people) |
| **Creator / Creators** | Public creator portfolio identity/directory, including special site-owner cases | Creator Profile, Browse Creators |

## Destination-first labeling

- The URL `/search?type=freelance` displays published **offers**, not a directory of people. Its links should say **Find Freelance Offers**, **Explore Freelance Offers**, or use the compact category label **Freelance**.
- The public `/creators` route displays a broader creator directory. Label links **Browse Creators** / **Browse Creator Profiles**, rather than guaranteeing that every result is a freelancer.
- A homepage section listing multiple users should use **Freelancers**, never singular **Freelancer**.
- A single freelance listing detail may say **Contact this freelancer** when describing its individual publisher.
- **Services** remains its own published listing type. Do not rename `service` to `freelance` or conflate their records.

## Internal compatibility

**Do not rename these established machine identifiers as a copy change:**

- Marketplace type value `freelance` and supported public routes such as `/freelance` and `/search?type=freelance`
- User account role `freelancer`
- Stored listing types, policies, permissions, database columns, API fields, license records or URL parameters

For localization, `ui.freelance` remains the compact listing-type label. `ui.find_freelance_offers` is for links to the filtered offers page. Keep `ui.find_freelancers` reserved for actual freelancer/people-directory destinations. Translate the **meaning** of the UI label, never the machine slug.

## Review checklist

Before publishing UI copy, inspect the target destination: **listings or people?** Check singular/plural agreement, filters, category labels, cards, homepage, footer, creator portfolios, builder, admin headings and translated variants. Preserve existing CSS selectors, stable IDs, routes and feature tests. Do not infer that a creator profile is verified simply from its public availability.
