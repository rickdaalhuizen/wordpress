# Flux WordPress

WordPress setup for development, running in Docker. Contains the custom plugins `flux-core`, `flux-closet`, `flux-pricing` and `flux-quote`.

## Getting started

```sh
cp .env.example .env   # fill in ACF_PRO_KEY and GITHUB_TOKEN
pnpm install
pnpm dev
```

WordPress runs on http://localhost:8000. Admin login is set in `.env` (`WORDPRESS_ADMIN_USER` / `WORDPRESS_ADMIN_PASSWORD`).

## Tools

Start Adminer and Mailpit as well:

```sh
docker compose --profile tools up
```

- Adminer: http://localhost:8080
- Mailpit: http://localhost:8025

Run `make` for the other commands (logs, WP-CLI, shell).
