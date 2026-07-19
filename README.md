# JeedomMCP

A Jeedom plugin that exposes an [MCP (Model Context Protocol)](https://modelcontextprotocol.io) server, enabling AI agents to interact with your home automation system.

## Documentation

- [Installation guide](docs/installation.md)
- [Configuration reference](docs/configuration.md)
- [MCP tools reference](docs/mcp-tools.md)
- [Development guide](docs/development.md)

## Contributing

Before committing, check that `core.hooksPath` points to `.githooks`
(`git config core.hooksPath`); if not, enable it with
`git config core.hooksPath .githooks`. This activates a pre-commit hook
that lints staged PHP files with `php -l` and blocks the commit if one
fails to compile.

## License

AGPL-3.0
