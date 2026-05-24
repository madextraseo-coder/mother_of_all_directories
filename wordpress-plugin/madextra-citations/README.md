# MadExtra Citations Plugin

Custom WordPress plugin for `directory.madextraseo.com` to manage citation profiles visually in wp-admin and publish a public citations directory at `/citations/`.

## JetEngine-Style Builder Layer (v0.2+)

This plugin now includes a modular builder layer to support a JetEngine-style dynamic workflow:

- `Citations Builder` admin area under `Citation Profiles`
- Builder entities:
  - `field_groups`
  - `templates`
  - `queries`
  - `forms`
  - `relations`
- Dynamic fields assignable to:
  - `citation_profile`
  - `citation_market`
  - `citation_service`
  - `user`
- Frontend profile dashboard shortcode:
  - `[mec_profile_dashboard]`
- Dynamic listing + filter shortcodes:
  - `[mec_listing template="default-table" query="all-profiles"]`
  - `[mec_filters query="all-profiles"]`
- Elementor widgets:
  - `MEC Listing`
  - `MEC Dynamic Field`
  - `MEC Filters`

## What This Includes

- Custom post type: `citation_profile`
- Custom taxonomies:
  - `citation_market`
  - `citation_service`
- Granular action capabilities:
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
- Default roles created on activation:
  - `Citation Manager`
  - `Citation Admin`
- Manual CRUD in wp-admin with required fields + sanitization
- CSV import/export tools in wp-admin
- REST API routes:
  - `GET /wp-json/madextra-citations/v1/profiles`
  - `POST /wp-json/madextra-citations/v1/import`
  - `GET /wp-json/madextra-citations/v1/export`
  - `GET|POST /wp-json/madextra-citations/v1/field-groups`
  - `GET|POST /wp-json/madextra-citations/v1/templates`
  - `GET|POST /wp-json/madextra-citations/v1/queries`
  - `GET|POST /wp-json/madextra-citations/v1/forms`
  - `GET|POST /wp-json/madextra-citations/v1/relations`
  - `GET|PUT|DELETE /wp-json/madextra-citations/v1/{entity}/{id}`
  - `POST /wp-json/madextra-citations/v1/profiles`
  - `PUT|DELETE /wp-json/madextra-citations/v1/profiles/{id}`
- Public grouped directory shortcode:
  - `[madextra_citations_directory]`
- Public submission shortcode:
  - `[mec_public_submit_form]`
- Directory behavior:
  - grouped by city/market
  - regular profiles paged in groups of 25 per city
  - global search across all cities and all loaded profiles
  - up to 3 manually ordered featured profiles per city

## Required Profile Fields

- `directory_name`
- `listing_url`
- `status` (`live`, `pending`, `in_progress`, `needs_fix`, `suspended`)
- `last_verified_date`
- `public_notes`
- `nap_business_name`
- `nap_address`
- `nap_phone`

Optional public profile fields:

- `business_website_url`
- `business_logo_id`
- `business_email`
- `business_description`
- `business_hours`
- `address_street`
- `address_city`
- `address_state`
- `address_zip`

Optional admin-only:

- `internal_notes`
- `is_featured`
- `featured_order`

## Quick Deploy (Beginner Friendly)

1. Set up WordPress on `directory.madextraseo.com` with SSL enabled.
2. Use `wordpress-plugin/madextra-citations-wp-upload.zip` for wp-admin uploads.
3. In WordPress admin, go to `Plugins > Add New > Upload Plugin`.
4. Upload the zip, install, then activate.
5. On activation, the plugin auto-creates:
   - `Citations` at `/citations/` with `[madextra_citations_directory]`
   - `Submit Citation` at `/submit-citation/` with `[mec_public_submit_form]`
6. Go to `Citation Profiles` in wp-admin:
   - Add Markets and Services terms.
   - Add profiles manually or import CSV from `CSV Tools`.
7. Verify public page:
   - `https://directory.madextraseo.com/citations/`

## CSV Format

Accepted CSV columns:

- `directory_name`
- `listing_url`
- `status`
- `last_verified_date`
- `public_notes`
- `nap_business_name`
- `nap_address`
- `nap_phone`
- `business_website_url`
- `business_logo_id`
- `business_email`
- `business_description`
- `business_hours`
- `address_street`
- `address_city`
- `address_state`
- `address_zip`
- `internal_notes`
- `is_featured`
- `featured_order`
- `market`
- `service`

For multiple values in `market` or `service`, separate with `|` or `,`.

## Role and Permission Notes

- `Citation Manager` can create/edit/publish/delete/import/export profiles.
- `Citation Admin` includes all manager permissions plus `manage_citation_settings` for taxonomy/settings control.
- Builder capabilities are auto-synced to `Citation Manager`, `Citation Admin`, and `Administrator` on activation/admin init.
- Administrators receive all plugin capabilities on activation.

## REST Examples

### Public profiles

`GET /wp-json/madextra-citations/v1/profiles?market=delray-beach&service=hair-stylist&status=live&page=1&limit=50`

### Import rows

`POST /wp-json/madextra-citations/v1/import`

JSON body:

```json
{
  "rows": [
    {
      "directory_name": "Google Business Profile",
      "listing_url": "https://example.com/listing",
      "status": "live",
      "last_verified_date": "2026-05-23",
      "public_notes": "Primary local listing",
      "nap_business_name": "Example Salon",
      "nap_address": "123 Main St, Delray Beach, FL",
      "nap_phone": "(561) 555-1212",
      "market": "Delray Beach",
      "service": "Hair Stylist"
    }
  ]
}
```

### Export rows

`GET /wp-json/madextra-citations/v1/export?status=live`

## Final Production Checklist

- Confirm `https://directory.madextraseo.com/citations/` is live and crawlable.
- Confirm profile tables render by market and filters work.
- Confirm no `internal_notes` are visible publicly.
- Confirm nav links from marketing pages point to:
  - `https://directory.madextraseo.com/citations/`
  - `https://directory.madextraseo.com/wp-admin/`
- Submit `/citations/` URL in Google Search Console.
