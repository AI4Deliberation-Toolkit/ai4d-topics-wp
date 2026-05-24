# AI4D Topics — WordPress Plugin

WordPress plugin that calls the AI4D topic-extraction backend on article publish, stores topics in post meta, and renders them on the public article page.

See `docs/2026-05-24-wp-plugin-topic-extraction-design.md` in the `ai4deliberation-backend` repo for the design spec.

## Development setup

This project runs entirely inside Docker — no need to install PHP, Composer, or MySQL on the host machine.

**Prerequisites:** Docker + Docker Compose.

```bash
make install            # Build PHP container + install Composer deps (one-time)
make install-wp-tests   # Set up WP test framework (one-time)
make test               # Run the test suite
make shell              # Open a bash shell inside the PHP container
make down               # Stop containers
make clean              # Nuke everything (containers, volumes, vendor/)
```

Status: in active development. See implementation plan for current task progress.
