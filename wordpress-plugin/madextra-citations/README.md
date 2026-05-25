# MadExtra Directory Plugin

Custom WordPress plugin for `directory.madextraseo.com` that provides:

- Multi-vertical directory pages
- High-scale CSV snapshot imports (custom tables + background jobs)
- Public claim/upgrade flow (Stripe Payment Links)
- Premium profile pages editable in Elementor
- Builder/dashboard tools for no-code management

Internal compatibility slugs remain unchanged (`citation_profile`, `citation_*`, `madextra-citations/v1`).

## Public URLs + Shortcodes

Preferred public pages:

- `/directory/` -> `[madextra_directory]`
- `/join-directory/` -> `[mec_join_directory_form]` (alias of public submit)
- `/payment-complete/` -> `[mec_payment_complete]`

Preferred shortcodes:

- `[madextra_directory]`
- `[mec_join_directory_form]`
- `[mec_public_profile id="123"]`
- `[madextra_directory_home]`

Compatibility aliases still supported:

- `[madextra_citations_directory]`
- legacy `/citations/` and `/submit-citation/` paths redirect where possible

## Multi-Vertical Architecture

One global system supports all verticals with shared admin flows and rendering.

Default verticals seeded on activation:

- `wellness`
- `medical-spas`
- `roofing-contractors`
- `mechanics`
- `electricians`
- `plumbers`
- `attorneys`

Nested vertical pages are auto-created under `/directory/`:

- `/directory/medical-spas/`
- `/directory/roofing-contractors/`
- `/directory/electricians/`
- `/directory/plumbers/`
- `/directory/attorneys/`

## Data Model (Scale-Ready)

Bulk listings are stored in custom tables:

- `wp_mec_directory_businesses`
- `wp_mec_directory_verticals`
- `wp_mec_directory_import_jobs`
- `wp_mec_directory_import_errors`

Imports are snapshot-based per vertical:

- Manual CSV upload in wp-admin -> creates import job
- Background chunk processing via WP cron
- Upsert key: `(vertical_slug, external_source_id)`
- Missing rows from latest snapshot are marked inactive (not deleted)

Duplicate guard for business rows:

- Fast pass: exact `(vertical_slug, external_source_id)`
- Secondary pass: normalized business identity match
- Secondary rule: duplicate if at least 2 of 4 match:
  - business name
  - phone
  - address
  - website

## CSV Contract

Each upload is one vertical snapshot.

Required stable ID (any one accepted):

- `source_business_id`
- `cid`
- `place_id`
- `kgmid`
- `google_knowledge_url`
- `gmb_url`

If no stable ID is present, the row is skipped and logged as an import error.

Common mapped fields include:

- `name`
- `full_address`
- `street_address`
- `city`
- `state`
- `zip`
- `phone_standard_format`
- `website`
- `email_from_website`
- `hours`
- `facebook_url`
- `instagram_url`
- `linkedin_url`
- `youtube_url`
- `review_url`
- `reviews_count`
- `average_rating`

## Listing States + Rendering

Directory listing states:

- `basic`
- `claimed`
- `premium`

Behavior:

- `basic` + `claimed` render in compact row layout
- `premium` listings are highlighted in featured cards
- Public output hides source/provider directory details

## Premium + Claim Workflow

Stripe Payment Links are supported (self-serve):

- Business reference is passed with `client_reference_id`
- Webhook updates payment/claim status
- Optional auto-upgrade to premium
- Optional auto-generate/refresh public page

Premium uses the same business profile page (no duplicate page type):

- One click actions are available in Premium Queue
- Generated pages are normal WP pages and Elementor-editable
- Regeneration flow avoids wiping existing custom page edits unless explicitly rebuilding

## Builder + Dashboard

Admin areas include:

- Directory Imports
- Directory Builder
- Premium Queue
- Directory Home
- Stripe Settings

Frontend/dashboard tooling includes:

- `[mec_profile_dashboard]`
- `[mec_listing template="..." query="..."]`
- `[mec_filters query="..."]`

## Install (Critical Zip Structure)

Use this exact structure inside the zip:

- `madextra-citations/madextra-citations.php`
- `madextra-citations/includes/...`

If WordPress shows `Plugin file does not exist`, the zip root is wrong.

Correct packaging:

1. Open plugin folder: `wordpress-plugin/madextra-citations`
2. Zip the folder itself (not its parent)
3. Upload in `Plugins > Add New > Upload Plugin`
4. Activate plugin

The activation URL should contain:

- `plugin=madextra-citations%2Fmadextra-citations.php`

It should not contain nested duplicate segments like:

- `madextra-citations-13%2Fmadextra-citations%2Fmadextra-citations.php`

## Quick Start (Beginner-Friendly)

1. Activate plugin on `directory.madextraseo.com`.
2. Visit `Directory Imports` and upload your first vertical snapshot CSV.
3. Wait for job completion in the jobs table (status/progress/errors).
4. Open `/directory/` and verify listings.
5. Open `/directory/medical-spas/` (or another vertical) to verify nested page.
6. Use `Join Directory` page for public submissions.
7. Configure Stripe settings and webhook URL.
8. Test claim flow with a test business.

## Permission Notes

Action-based capabilities are still used internally:

- `manage_citation_profiles`
- `create_citation_profiles`
- `edit_citation_profiles`
- `delete_citation_profiles`
- `publish_citation_profiles`
- `import_citation_profiles`
- `export_citation_profiles`
- `manage_citation_settings`
- `manage_citation_builder`
- `manage_citation_templates`
- `manage_citation_queries`
- `manage_citation_forms`
- `manage_citation_relations`
- `submit_citation_profiles`

Default roles:

- `Directory Manager` (role key: `citation_manager`)
- `Directory Admin` (role key: `citation_admin`)

Administrators are auto-granted plugin caps.

## REST Namespace

REST remains under compatibility namespace:

- `/wp-json/madextra-citations/v1/...`

Includes endpoints for:

- profiles
- directory businesses
- directory import jobs
- directory verticals
- builder entities (field groups/templates/queries/forms/relations)
