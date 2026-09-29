# Changelog

## 1.0.0 — 2026-09-29

First release.

- Assignments (`mod_assign`): online text, PDF, Word (.docx), text and Markdown files.
- Analysis in the background after the final submission, or 60 seconds after the
  last save when the assignment does not require a submit click.
- Teacher badge next to each source, report with highlighted passages.
- Manual analysis of work handed in before Lucide was switched on, per
  submission or for a whole assignment from its settings.
- One charge per source version: idempotency key frozen before the first call.
- Pause on rate limits and credit shortage, retries with backoff, sending stopped
  and reported when the key is refused.
- Erasure at Lucide once the report is stored in Moodle; local report retention.
- Privacy API: export and deletion, including group submissions.
- English and French.
- Tested on Moodle 4.5 and 5.2 (PostgreSQL, PHP 8.3).
