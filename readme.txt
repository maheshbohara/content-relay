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
