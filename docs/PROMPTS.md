# LLM prompt templates

Located in `backend/prompts/`.

## `listing_extract.txt`

Used by `ListingParserService` when JSON-LD / Open Graph / embedded coordinates are incomplete.

Returns JSON: `address`, `city`, `country`, `country_code`, `lat`, `lng`, `title`.

## `incident_extract.txt`

Used by `ArticleExtractionService` for each article excerpt.

Returns JSON `{ "incidents": [ … ] }` with crime type, date, location text, precision, status, language, and a one-sentence paraphrase.

Hard rules encoded in the prompt:

- never invent incidents
- never name victims, suspects, or minors
- no safe/unsafe or buy/rent advice
- paraphrase only (no full article text)
