# Content Relay

Content Relay is a WordPress plugin that moves a single page, post or custom post type entry from one WordPress site to another, with every translation, its media and what it needs. The receiving site shows a plan first, nothing is written until an administrator approves it, and every relay can be rolled back.

It is for the moment a piece of content is finished on one site, such as development, and has to appear on another, such as production, without copying a whole database.

## What a relay carries

- The page or post and every translation of it (WPML), with fields, meta, Yoast SEO data and its terms.
- The images and files it uses. The original upload is sent once and the receiving site regenerates the image sizes. A file with the same name but different bytes is saved under a new name, never overwritten.
- References inside the content: block attributes, ACF fields, image IDs in HTML and links to the sending site, all rewritten to the receiving site's IDs and address.
- Terms the content is assigned to that the receiving site lacks, created in the right language.

Content is written byte for byte. Block markup is edited only where a reference changes; it is never re-serialized.

A relay does not carry the theme, menus, widgets, site options, plugin settings, user accounts or comments. The same theme and version must be deployed on both sites first.

## How it works

The same plugin runs on both sites. The receiving site issues a key and the sending site stores it as a connection.

1. **Discover.** The sending site walks from the chosen content to everything it references and builds a manifest.
2. **Upload.** Files are sent in resumable chunks. Every request is signed with Ed25519 and carries a timestamp and a single-use nonce.
3. **Plan.** The receiving site answers with what it will create, update, reuse or refuse, with a diff for anything it would replace.
4. **Apply.** After an administrator approves the plan, the receiving site takes a backup and commits the content in one database transaction.
5. **Check.** Caches are cleared, and the sending site fetches the relayed pages to check their status, language, links and images.

Every relay is logged on both sites under **Tools → Content Relay history** and can be rolled back from either.

## Where you use it

- **Relay to…** on a row in **Pages**, or in any other content list switched on under **Settings → Content Relay**.
- WP-CLI: `wp content-relay plan`, `push`, `push-many`, `verify`, `rollback`, `history`, `keys` and `connections`.
- An offline bundle (`wp content-relay bundle`, `plan`, `apply`) for sites that cannot reach each other.

Content Relay contacts only the sites you connect it to. It uses no third-party service and does no tracking.

## Requirements

- WordPress 6.4 or newer
- PHP 7.4 or newer
- InnoDB tables
- HTTPS on the receiving site

WPML, ACF (including ACF blocks), core blocks and Yoast SEO are supported. Polylang and Elementor content are not.

## Installation

1. Install and activate Content Relay on **both** sites.
2. On the receiving site, open **Settings → Content Relay**, switch **Receiving** on and issue a key. Copy it: it is shown once.
3. On the sending site, open **Settings → Content Relay**, switch **Sending** on and add a connection by pasting the key.
4. Deploy the same theme to both sites.
5. On the sending site, hover a page in **Pages** and choose **Relay to…**.

The full user documentation, including hooks, security details and the FAQ, is in [readme.txt](readme.txt).

## Development

You need PHP, Composer, Node.js and Docker.

```sh
composer install
npm install
npm run env:start
```

`npm run env:start` starts a WordPress site at http://localhost:8900 with the plugin active.

| Command | What it does |
|---|---|
| `composer lint` | PHPCS with the WordPress coding standard |
| `composer phpstan` | PHPStan static analysis |
| `composer test` | Unit tests |
| `npm run test:integration` | Integration tests against real WordPress in wp-env |
| `npm run build` | Builds the distributable plugin in `build/` |
| `npm run plugin-check` | Builds, then runs the WordPress.org Plugin Check on the result |

## License

Content Relay is licensed under the [GNU General Public License v2.0 or later](LICENSE).
