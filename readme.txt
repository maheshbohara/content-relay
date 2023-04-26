=== Content Relay ===
Tags: staging, deployment, migration, multilingual, wpml
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Relay a page or post — every translation, its media and what it needs — from one WordPress site to another, with a reviewed plan and rollback.

== Description ==

Content Relay moves a single page, post or custom post type entry between two WordPress sites you control, such as a development site and production, in either direction. It is for the moment a piece of content is finished on one site and has to appear on the other without copying a whole database.

The same plugin runs on both sites. The receiving site issues a key; the sending site stores it. From then on an administrator can hover an item in **Pages** (or any other content list switched on in the settings), choose **Relay to…**, review what the receiving site plans to do, and relay. Many posts can be relayed at once with WP-CLI.

= What a relay carries =

* The page or post and every translation of it (WPML), with fields, meta, Yoast SEO data and its terms.
* The images and files it uses. The original upload is sent once; the receiving site regenerates the image sizes. A file with the same name but different bytes is saved under a new name, never overwritten.
* References inside the content: block attributes, ACF fields (images, files, galleries, posts, terms, users), image IDs in HTML, and links to the sending site, all rewritten to the receiving site's IDs and address.
* Terms the content is assigned to that the receiving site lacks, created in the right language.

Content is written byte for byte. Block markup is edited only where a reference changes; it is never re-serialized.

= Review before anything is written =

The receiving site plans every relay and answers with a list of what it will create, update, reuse or refuse, with a field, meta and content diff for anything it would replace. Nothing is written until an administrator approves that plan. If the receiving site changes in the meantime, the relay is refused and the plan must be approved again.

Content that was edited on the receiving site since its last relay is blocked. It can be relayed with **Overwrite**, which keeps a backup.
