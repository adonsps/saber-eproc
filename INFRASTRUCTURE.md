# Infrastructure Documentation (VMS & Workspace)

This document outlines the standard Docker infrastructure and CI/CD workflow for the VMS and Workspace applications. This replaces the previous Coolify setup.

## Target Audience
- Human Developers
- AI Coding Assistants (Claude, Gemini, Cursor)

## Architecture Overview
The application is deployed on a raw Ubuntu Linux VPS using standard `docker` and `docker compose`.

### Server Details
- **Current IP Address:** `187.77.112.238`
- **SSH Access:** `root@187.77.112.238` (Key is stored in GitHub Secrets as `VPS_SSH_KEY`)
- **Primary Domain:** `vssportal.online` (Legacy: `vmsportal.online`)
- **Database Management:** `db.vssportal.online`

### Security Posture
- **Firewall (UFW):** Active. Only ports `22`, `80`, and `443` are allowed.
- **Intrusion Prevention:** `fail2ban` is actively running and monitoring logs to instantly ban brute-force IPs.
- **SSH Lockdown:** **Password Authentication is completely DISABLED.** You must authenticate using the authorized `id_ed25519` SSH Key (the `VPS_SSH_KEY`). Passwords will be flat-out rejected.

We use **Nginx Proxy Manager (NPM)** as a separate Docker container on the host to manage domains, SSL certificates, and routing. 
The application containers connect to NPM via a shared external Docker network named `npm_network`.

### Component Details
1. **App Container (`app`)**: Built from the local `Dockerfile`. Runs the PHP/Apache server. Connected to `npm_network` so Nginx Proxy Manager can route port 80 traffic to it.
2. **Database Container (`db`)**: Runs `mysql:8.0`. Isolated from the NPM network for security. Data is persisted in a named Docker volume (`workspace_db_data_prod`).
   - **Important:** The system uses two distinct MySQL users for the databases:
     - `u894129685_adonisvm` (For `u894129685_vmsportal` and `u894129685_workspace` via dynamic connections)
     - `u894129685_workspace007` (Hardcoded in Workspace `config.php` for `u894129685_workspace`)
3. **Database Admin (`phpmyadmin`)**: Runs phpMyAdmin for visual database management. Connects to the `db` container and is exposed via `npm_network` so it can be assigned a secure subdomain (e.g., `db.yourdomain.com`).

## Deployment Workflow (CI/CD)
Deployments are fully automated via **GitHub Actions**.

1. **Trigger:** Developers push code to the `production` branch.
2. **Action:** The GitHub Action (`.github/workflows/deploy.yml`) securely SSHs into the VPS as `root`.
3. **Execution:** It navigates to `/var/www/vms`, runs `git pull origin production`, and triggers `docker compose -f docker-compose.prod.yml up -d --build` to apply the changes without downtime.

## Guidelines for AI Assistants (Vibe Coding)
When making architectural or deployment changes, please follow these rules:
- **Production Compose File:** Always edit `docker-compose.prod.yml` for production-specific infrastructure changes, not the local `docker-compose.yml`.
- **Database Migrations:** If adding new tables or columns, write standard SQL migration scripts. Since the database is plain MySQL running in Docker, raw SQL runs via `docker exec -it <db_container_name> mysql -u root -p < file.sql`.
- **File Uploads:** Remember that Docker containers are ephemeral. The app uses the `uploads/` directory which is mapped to the `workspace_uploads_prod` volume. Do not change this mapping, or user files will be lost on the next deployment.
- **Port Mapping:** Do NOT expose container ports directly to the host (e.g., avoid `ports: - "8080:80"`) in the production compose file. Rely on Nginx Proxy Manager and the `npm_network` for all external routing.
