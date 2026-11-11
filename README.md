# Kimai MCP server

An MCP server inside [Kimai](https://www.kimai.org), so Claude and other MCP clients can read logged time, budgets and planning, and make the changes you approve, with Kimai's own permissions deciding what each caller may see and do.

**Status: phase 0 spike.** One tool (`whoami`). Not ready for use.

## How it works

- The endpoint is `POST /api/mcp`, behind Kimai's API firewall: callers authenticate with a normal Kimai API token (`Authorization: Bearer …`), and every tool runs as that user.
- Built on the official [PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk). Kimai never installs a plugin's Composer dependencies, so the SDK ships inside the plugin in `vendor-prefixed/`, with every namespace prefixed by [Strauss](https://github.com/BrianHenryIE/strauss) so it cannot clash with Kimai's own libraries.
- Sessions are kept in the database, so the server works behind a load balancer without sticky sessions. A session belongs to the user whose token started it.
- Two permissions, given to super admins by default: `mcp_access` (use the endpoint) and `mcp_write` (tools that change data).

## Building the bundled libraries

`vendor-prefixed/` is committed, so the plugin works straight from a git tag or a download, like any other Kimai plugin. Rebuild it only when the libraries change (edit and update `tools/deps/composer.json`, whose lock file pins exactly what goes in), and commit the result:

```sh
cd tools/deps
composer install
composer prefix      # runs Strauss: writes ../../vendor-prefixed/
```

## Installing (development)

Symlink the repository into Kimai's `var/plugins/McpServerBundle`, then:

```sh
bin/console kimai:reload
bin/console kimai:plugins --install
```

## Licence

AGPL-3.0-or-later. The bundled libraries keep their own licences (Apache-2.0 and MIT), included in `vendor-prefixed/`.
