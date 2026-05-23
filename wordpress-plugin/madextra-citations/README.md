# MadExtra Citations Plugin

Custom WordPress plugin for `directory.madextraseo.com` to manage citation profiles visually in wp-admin and publish a public citations directory at `/citations/`.

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
- Default roles created on activation:
  - `Citation Manager`
  - `Citation Admin`
- Manual CRUD in wp-admin with required fields + sanitization
- CSV import/export tools in wp-admin
- REST API routes:
  - `GET /wp-json/madextra-citations/v1/profiles`
  - `POST /wp-json/madextra-citations/v1/import`
  - `GET /wp-json/madextra-citations/v1/export`
- Public grouped directory shortcode:
  - `[madextra_citations_directory]`

## Required Profile Fields

- `directory_name`
- `listing_url`
- `status` (`live`, `pending`, `in_progress`, `needs_fix`, `suspended`)
- `last_verified_date`
- `public_notes`
- `nap_business_name`
- `nap_address`
- `nap_phone`

Optional admin-only:

- `internal_notes`
- `is_featured`

## Quick Deploy (Beginner Friendly)

1. Set up WordPress on `directory.madextraseo.com` with SSL enabled.
2. In this folder, zip the plugin directory as `madextra-citations.zip`.
3. In WordPress admin, go to `Plugins > Add New > Upload Plugin`.
4. Upload the zip, install, then activate.
5. On activation, the plugin auto-creates a `Citations` page at `/citations/` with `[madextra_citations_directory]`.
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
- `internal_notes`
- `is_featured`
- `market`
- `service`

For multiple values in `market` or `service`, separate with `|` or `,`.

## Role and Permission Notes

- `Citation Manager` can create/edit/publish/delete/import/export profiles.
- `Citation Admin` includes all manager permissions plus `manage_citation_settings` for taxonomy/settings control.
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
