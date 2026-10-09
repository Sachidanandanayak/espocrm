# EspoCRM — Enterprise CRM Platform & Custom Extensions

[![PHPStan level 8](https://img.shields.io/badge/PHPStan-level%208-brightgreen)](#espocrm)
[![PHP](https://img.shields.io/badge/PHP-8.3%20%7C%208.4%20%7C%208.5-blue.svg)](https://www.php.net/)
[![Database](https://img.shields.io/badge/Database-MariaDB%2011.4%20%7C%20MySQL%208.0-orange.svg)](https://mariadb.org/)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL%20v3-blue.svg)](https://raw.githubusercontent.com/espocrm/espocrm/master/LICENSE.txt)

> **Cynaris Solutions Full Stack Development Internship**  
> **Author:** Sachidananda Nayak  
> **Repository:** [https://github.com/Sachidanandanayak/espocrm](https://github.com/Sachidanandanayak/espocrm)  
> **Upstream:** [https://github.com/espocrm/espocrm](https://github.com/espocrm/espocrm) (`master`)  
> **Feature Branch:** `feat/w6d5-3m-sachidananda`

---

## 📌 Project Overview

[EspoCRM](https://www.espocrm.com) is an open-source Customer Relationship Management (CRM) platform designed to manage leads, contacts, business accounts, opportunities, activities, and customer communication via an intuitive single-page application (SPA) and extensible REST API backend.

This repository represents the extended fork developed during the **Cynaris Solutions Software Engineering Internship**, featuring:
* A containerized multi-service production architecture (`docker-compose.production.yml`).
* A zero-cost public HTTPS gateway integration (`loca.lt`).
* End-to-end sales funnel automation (`Lead` → `Account` → `Opportunity` → `Activity`).
* Authenticated REST API v1 endpoints and telemetry.
* AI-driven conversation summarization (Groq Llama 3.3 70B integration) and smart reminder workflows.
* Automated deployment verification test suites with zero regressions.

---

## 🚀 Quickstart & Project Setup

### 1. Prerequisites
* **Docker Engine** (v24.0+) & **Docker Compose** (v2.20+)
* **PHP** (v8.3 to v8.5 with `curl`, `json`, `mbstring`, `pdo_mysql` extensions)
* **Git** (v2.40+)

### 2. Clone the Fork
```bash
git clone https://github.com/Sachidanandanayak/espocrm.git
cd espocrm
git checkout feat/w6d5-3m-sachidananda
```

### 3. Launch Production Container Stack
The self-contained production environment orchestrates all required microservices without touching local development artifacts:

```bash
# Start all production services in the background
docker compose -f docker-compose.production.yml up -d

# Verify container health status
docker compose -f docker-compose.production.yml ps
```

### 4. Service Access & Default Credentials
Once running, the services are accessible at:
* **Web Application & REST API:** [http://localhost:8080](http://localhost:8080)
* **Real-Time WebSocket Server:** `ws://localhost:8081`
* **Default Admin Username:** `admin`
* **Default Admin Password:** `EspoCRM_Admin_2026!`

### 5. Start Zero-Cost Public HTTPS Gateway (Optional)
To expose the local production stack to the internet without credit card registration or paid hosting:
```bash
# Launch public reverse proxy gateway
npx -y localtunnel --port 8080 --subdomain bright-nails-carry
```
The public URL will be accessible at: `https://bright-nails-carry.loca.lt` (Bypass header: `Bypass-Tunnel-Reminder: true`).

---

## 💻 Technology Stack

| Layer | Technology | Version / Specification | Role |
| :--- | :--- | :--- | :--- |
| **Backend Core** | PHP | 8.3 / 8.5 (CLI & Apache) | Business logic, ORM, REST API controllers, DI |
| **Web Server** | Apache HTTP Server | 2.4 (pre-configured in base) | HTTP server, URL rewriting, headers |
| **Database** | MariaDB | 11.4 LTS | Relational storage, UTF-8 (`utf8mb4_unicode_ci`) |
| **Real-Time Engine** | EspoCRM WebSocket Daemon | ZeroMQ / Ratchet | Instant push notifications, entity stream updates |
| **Task Daemon** | EspoCRM Scheduled Jobs | PHP CLI daemon | Asynchronous queues, cron automation |
| **Frontend SPA** | JavaScript (ES6+) / Backbone | Custom Espo SPA framework | Single-page application, metadata views |
| **Data Visualization**| Flotr2 | Native EspoCRM bundle | Activity summary charts and dashlets |
| **AI Engine** | Groq API | Llama 3.3 70B Versatile | Conversation summarization & optimal follow-up timing |
| **Orchestration** | Docker & Docker Compose | Compose Specification v3.8 | Multi-service lifecycle, healthchecks, volumes |
| **Public Gateway** | Localtunnel Reverse Proxy | Edge TLS termination | Zero-cost public HTTPS access |

---

## ✨ Implemented Features

### 1. Production Container Architecture (`docker-compose.production.yml`)
* **Multi-Service Topology:** Decoupled into `espocrm-prod-app`, `espocrm-prod-db`, `espocrm-prod-daemon`, and `espocrm-websocket`.
* **Database Tuning:** MariaDB tuned with `innodb_buffer_pool_size=512M` and `max_allowed_packet=64M`.
* **Orchestration Reliability:** Automated MariaDB healthcheck (`mariadb-admin ping`) with `condition: service_healthy` on dependent services.
* **Persistent Volumes:** Isolated named volumes for database records (`espocrm_prod_db_data`), uploads (`espocrm_prod_data`), and extensions (`espocrm_prod_custom`, `espocrm_prod_client_custom`).
* **High Availability:** `restart: unless-stopped` specified across all services.

### 2. CRM Sales Funnel Lifecycle
Verified programmatic and UI workflows covering the end-to-end sales cycle:
$$\text{Lead} \xrightarrow{\quad\text{Conversion}\quad} \text{Account} \xrightarrow{\quad\text{Pipeline}\quad} \text{Opportunity} \xrightarrow{\quad\text{Execution}\quad} \text{Activity (Meeting)}$$
* **Lead:** Elena Rostova (`6ac7d18d45ce82dbc`) — `$75,000` inquiry.
* **Account:** Apex Enterprise Global (`6ac7d1927f57f425a`).
* **Opportunity:** Apex Enterprise Cloud CRM Deployment & Migration (`6ac7d1b3cd00e7fde`, `$75,000`, 50% probability).
* **Activity:** Meeting — Production Architecture & Security Sign-Off (`6ac7d1bfb6371c942`, Planned).

### 3. REST API v1 Telemetry & Endpoints
* **Authenticated Telemetry:** `GET /api/v1/App/user` returning user profile, permissions, and runtime metadata.
* **Entity Operations:** `POST /api/v1/Lead`, `GET /api/v1/Opportunity`, `GET /api/v1/Account`.
* **Security:** Support for HTTP Basic Auth and bearer session tokens (`Espo-Authorization`).

### 4. AI & Workflow Automation
* **AI Conversation Summarizer:** Summarizes meetings, call notes, and deal discussions into executive points, sentiment, and temperature via Groq Llama 3.3 70B.
* **Smart Reminders:** Automatically identifies uncontacted leads (3+ days) and schedules optimal follow-ups with anti-spam rate limiting.
* **Activity Summary Dashboard:** Visualizes past 30 days of activities grouped by Account on the home dashboard.

---

## 🌐 Actual Deployment Status & URLs

| Environment | Access URL | Protocol / Port | Verification Status |
| :--- | :--- | :--- | :--- |
| **Local Production Stack** | [http://localhost:8080](http://localhost:8080) | HTTP / Port 8080 | Verified & Functional via `docker-compose.production.yml` |
| **Local WebSocket Server** | `ws://localhost:8081` | WS / Port 8081 | Real-time stream updates enabled |
| **Public Production Gateway** | [https://bright-nails-carry.loca.lt](https://bright-nails-carry.loca.lt) | HTTPS / Port 443 (Edge TLS) | Reverse proxy endpoint established |

> **Deployment Note:** The public gateway requires the local development host and tunnel process to be running. If the local host is stopped, the gateway serves a standard 503 gateway standby page until restarted.

---

## ⚠️ Known Limitations

1. **Localtunnel Gateway Session Expiry:** Localtunnel public subdomains are ephemeral if the localtunnel process restarts without the reserved subdomain flag.
2. **Groq API Key Requirement:** Groq AI summarization requires an active `GROQ_API_KEY` environment variable. When absent, the system falls back to a deterministic rule-based simulation.
3. **Non-Standard WebSocket Port (8081):** WebSocket communication requires client networks to allow outbound traffic on port 8081. Environments with restrictive corporate firewalls may require reverse proxying WebSocket traffic through port 80/443.
4. **Volume Mount Persistence:** Custom code changes must be placed in the designated volume mounts (`espocrm_prod_custom` or `./custom/`) to survive container redeployment.

---

## 🧪 Verification & Testing

Run the automated verification suite to validate container configuration and API functionality:

```bash
# Check PHP syntax of verification scripts
php -l tests/unit/verify_production_deployment.php

# Execute automated deployment assertions
php tests/unit/verify_production_deployment.php
```

---

## 📂 Repository Branching Structure

* `upstream/master` — Official EspoCRM repository develop branch.
* `origin/feat/w6d4-3m-sachidananda` — W6D4 Production deployment configuration & test suite.
* `origin/feat/w6d5-3m-sachidananda` — Current branch: Technical documentation, demo script, and final PR submission.

---

## 📄 License & Contributing

EspoCRM is open-source software licensed under the [GNU AGPLv3](https://raw.githubusercontent.com/espocrm/espocrm/master/LICENSE.txt). Contributions adhere to the upstream [EspoCRM Contributing Guidelines](https://github.com/espocrm/espocrm/blob/master/.github/CONTRIBUTING.md).
