=== Pool Quote Compare ===
Contributors: pool-quote-compare
Tags: pool, quote, comparison, ai, claude
Requires at least: 6.0
Tested up to: 6.5
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later

AI-powered pool quote comparison using Claude Opus 4.7 (1M context). Customers upload 2+ PDFs or Word docs and receive a structured, evidence-based comparison by email.

== Description ==

* Customer-facing shortcode [pool_quote_compare] with upload form.
* Sends every quote document to Claude Opus 4.7 natively (PDFs) or as extracted text (DOCX).
* Optional web_search tool for verifying equipment models, companies, and market pricing.
* Saves every submission (files + AI response) to a custom database table.
* Emails the comparison to the customer automatically, with admin BCC option.
* Full transparency: the exact system prompt used is shown to customers on the upload page.
* Admin panel to edit the system prompt, view submissions, and re-send emails.

== Installation ==

1. Upload the plugin zip via Plugins > Add New > Upload.
2. Activate the plugin.
3. Go to "Pool Quotes" in the admin menu.
4. Enter your Anthropic API key.
5. Place `[pool_quote_compare]` on any page.

== Changelog ==

= 1.0.0 =
* Initial release.
