<p align="center">
  <img src="assets/brand/saber-logo.svg" alt="Saber Logo" width="80" height="80" />
</p>

<h1 align="center">Saber eProc</h1>

<p align="center">
  <strong>Next-Generation Enterprise Digital Procurement & Vendor Management System</strong>
</p>

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.1%2B-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP" />
  <img src="https://img.shields.io/badge/MySQL-8.0-4479A1?style=flat-square&logo=mysql&logoColor=white" alt="MySQL" />
  <img src="https://img.shields.io/badge/Design_System-Saber_Enterprise-0F1E36?style=flat-square" alt="Saber Design" />
  <img src="https://img.shields.io/badge/Docker-Ready-2496ED?style=flat-square&logo=docker&logoColor=white" alt="Docker" />
</p>

---

## 🌟 Overview

**Saber eProc** is an end-to-end enterprise digital procurement platform redesigned with the **Saber Design System**. It streamlines vendor onboarding, qualification evaluation, RFQ (Request for Quotation) lifecycle management, canvassing comparisons, committee approval workflows, and supplier performance tracking into a cohesive, secure command center.

### 🎨 Saber Design System Overhaul
- **Brand Identity**: Integrated official Saber emblem, favicon, and dedicated vector assets (`saber-eproc-logo.svg`, `saber-eproc-horizontal.svg`).
- **Color Palette**: 
  - Primary Brand Navy: `#0F1E36`
  - Core Saber Blue: `#1E40AF` / `#2563EB`
  - Vivid Accent Cyan: `#0090FF` / `#00D8FF`
  - Warning / Alert Orange: `#EA580C`
  - Canvas & Neutral Surfaces: `#F8FAFC` / `#FFFFFF` / `#0F172A`
- **Typography**: Complete typography migration to **Plus Jakarta Sans** (Weights: 400, 500, 600, 700, 800).
- **Component Styling**:
  - Precision rounded pill/card geometry (`border-radius: 8px` & `12px`).
  - Elevated subtle shadows with focus glow rings.
  - Re-themed vendor dashboard, management workspace, authentication portals, and email templates.

---

## 🚀 Key Modules

### 1. 🏢 Vendor Portal & Onboarding
- **Self-Service Registration**: Multi-tier company profile registration, tax (NPWP/NIB) verification, and banking information.
- **Document Management**: Legal document uploads with expiry alerts and compliance checklists.
- **Evaluation & Qualification**: Real-time status tracker from submission to committee verification.

### 2. 📋 Sourcing & RFQ Engine
- **RFQ Creation & Distribution**: Specification building, line-item pricing sheets, and automated vendor invitations.
- **Quotation Submission**: Vendor bidding room with attachment submissions and deadline enforcement.
- **Comparison & Canvassing Matrix**: Side-by-side commercial and technical offer evaluations with automated variance calculations.

### 3. ⚖️ Committee Approvals & Governance
- **Multi-Level Approval Chains**: Dynamic routing to Procurement Officers, Managers, and Committee Board members.
- **Audit Logging**: Immutable tracking of actions, price negotiations, and decision rationale.
- **Contract & PO Issuance**: Transition from awarded bids into formal procurement documentation.

### 4. 📢 Announcement & Notification Center
- **Automated Alerts**: Email notifications and WhatsApp integration via Fonnte for deadline reminders and RFQ announcements.
- **Broadcasting**: Public and targeted procurement announcements for qualified vendors.

---

## 🛠️ Tech Stack & Architecture

- **Backend**: PHP 8.1+ (Procedural + Object-Oriented Services, PDO Prepared Statements)
- **Database**: MySQL 8.0 with automated migrations (`db_migrations/`)
- **Frontend**: 
  - Vanilla CSS & Saber Enterprise Tokens (`assets/css/style.css`, `workspace/assets/css/custom.css`)
  - Tailwind CSS Utility Layer
  - Bootstrap 5 Component Bridge
  - DataTables with Saber pagination styling
  - Chart.js & ApexCharts for executive analytics
- **DevOps**: Docker, Docker Compose, Nginx, PHP-FPM

---

## ⚡ Quick Start with Docker

### Prerequisites
- [Docker Engine](https://docs.docker.com/engine/install/) (v24+)
- [Docker Compose](https://docs.docker.com/compose/) (v2+)

### 1. Clone & Configure
```bash
git clone https://github.com/adonsps/saber-eproc.git
cd saber-eproc

# Create your local environment configuration
cp .env.example .env
```

### 2. Start the Development Stack
```bash
docker compose up -d
```

The stack initializes:
- **Web Application / Nginx**: `http://localhost:8080`
- **MySQL Database**: `localhost:3306`
- **Database Adminer / phpMyAdmin**: `http://localhost:8081` (if enabled in compose)

### 3. Accessing the Portals
- **Vendor Portal**: `http://localhost:8080/`
- **Management Workspace**: `http://localhost:8080/workspace/pages/login.php`

---

## 📁 Directory Structure

```plaintext
saber-eproc/
├── assets/                  # Public brand logos, CSS tokens, JS libraries, vendor scripts
│   ├── brand/               # Official Saber brand SVG and PNG marks
│   ├── css/                 # Saber Enterprise stylesheets and responsive styles
│   └── img/                 # Vector badges, logos, and UI graphics
├── config.php               # System-wide configuration & environment loader
├── db_migrations/           # Versioned MySQL database migration scripts
├── includes/                # Shared layout headers, branding helpers, mailers
├── modules/                 # Vendor-facing modules (profile, bids, documents)
├── workspace/               # Internal management workspace
│   ├── assets/              # Workspace-specific scripts and styles
│   ├── config/              # Workspace database connections
│   ├── includes/            # Workspace navigation, sidebars, and headers
│   └── pages/               # Sourcing, RFQ, Canvassing, and Committee portals
├── docker-compose.yml       # Local development container orchestration
└── docker-compose.prod.yml  # Production deployment configuration
```

---

## 🔒 Security & Best Practices
- Strict input sanitization and parameter binding on all database queries.
- CSRF tokens on state-modifying POST endpoints.
- Role-based Access Control (RBAC) separating internal committee members and external suppliers.
- Secure session management with encrypted cookies and automated timeout policies.

---

## 📄 License & Credits
Developed for Saber Enterprise Ecosystem. All rights reserved.
