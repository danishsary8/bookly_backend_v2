# Phase 8: rule-based assistants

Customer questions use a replaceable engine interface and local deterministic rules. A public endpoint may identify a verified customer through Sanctum; order lookup is always scoped to that customer and never distinguishes another customer's order from a nonexistent one. Catalogue filters reuse the existing public catalogue. Responses are plain text and links to existing pages; no outside service sees the question.

Staff questions require a two-factor staff token. Each data topic checks the source page's ability before calling a dashboard or support service. Staff manage bilingual FAQs and unanswered questions through separately protected support-management routes. Unknown questions are redacted before aggregation; short values are indexed by a digest so repeated submissions increment one row atomically. Questions and FAQ contents are excluded from audit and application logs.

Both interfaces live in the existing shells. The customer starts on an automatic-assistant tab and may explicitly transfer the current question to the human support form; there is no automatic message submission. Native controls and the existing dialog provide keyboard and screen-reader support. The explicit FAQ seeder is idempotent and is never part of production startup.
