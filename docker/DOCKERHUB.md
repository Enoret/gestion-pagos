# Gestion de Pagos

A lightweight web application for managing household service payments. Track work hours, payments, and balances for services like cleaning, gardening, and more.

## Features

- Register work entries (hours, rates, notes) and payments
- Real-time balance dashboard with alerts
- Interactive balance evolution chart (Chart.js)
- Full movement history with filters by service and type
- CSV export
- Admin panel: service management, backups, theme customization, maintenance
- Light/dark theme with automatic system detection
- Multi-language (Spanish/English) with automatic browser detection
- MDI icons via Iconify
- Customizable color scheme from the admin panel
- SQLite database (no external dependencies)
- Responsive design (mobile, tablet, desktop)

## Quick Start

```bash
docker run -d \
  -p 8080:80 \
  -v gestion-pagos-data:/var/www/html/data \
  --name gestion-pagos \
  --restart unless-stopped \
  mbraut/gestion-pagos:latest
```

Then open `http://localhost:8080` in your browser.

## Docker Compose

```yaml
services:
  gestion-pagos:
    image: mbraut/gestion-pagos:latest
    container_name: gestion-pagos
    ports:
      - "8080:80"
    volumes:
      - gestion-pagos-data:/var/www/html/data
    restart: unless-stopped

volumes:
  gestion-pagos-data:
```

```bash
docker compose up -d
```

## Portainer Stack

Copy the Docker Compose YAML above into **Stacks > Add stack** in Portainer.

## Data Persistence

All application data is stored in `/var/www/html/data` inside the container:

| File | Description |
|------|-------------|
| `pagos.db` | SQLite database (records, balances) |
| `services.json` | Service configuration |
| `theme.json` | Custom theme colors |
| `backups/` | Automatic database backups |

Mount a volume to `/var/www/html/data` to persist data across container restarts and updates.

## Ports

| Port | Description |
|------|-------------|
| `80` | HTTP (Apache) |

## Environment

| Component | Version |
|-----------|---------|
| PHP | 8.2 |
| Apache | 2.4 |
| SQLite | 3.x |
| Base Image | `php:8.2-apache` (Debian) |

## Screenshots

The application includes a clean, modern UI with:

- Dashboard with balance cards and payment alerts
- Work and payment registration forms
- Balance evolution chart
- Movement history with service filters
- Admin panel for full system management

## Source

Built with PHP 8.2, vanilla JavaScript, Chart.js, and Iconify MDI icons.
