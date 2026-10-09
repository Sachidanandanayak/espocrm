# Week 6 — Day 5: 3-Minute Technical Demo Video Script

**Program:** Cynaris Solutions Full Stack Development Internship  
**Project:** EspoCRM Production Deployment & Custom Platform Extensions  
**Author:** Sachidananda Nayak  
**Branch:** `feat/w6d5-3m-sachidananda`  
**Target Duration:** Exactly 3 Minutes (180 Seconds)  
**Status:** Ready for Recording (Teleprompter Script & Presentation Guide)  

> [!NOTE]
> **Recording Notice:** This document provides the complete, timing-calibrated script and visual staging guide for recording the final 3-minute demonstration video. No video is claimed as pre-recorded until the actual recording is conducted and the link populated in `W6D5_FINAL_SUBMISSION.md`.

---

## ⏱️ Video Timeline Breakdown (180s Total)

```
[0:00 - 0:30] Introduction & Architectural Overview (30s)
[0:30 - 1:00] Production Docker Multi-Service Topology & Zero-Cost Gateway (30s)
[1:00 - 1:45] Live CRM Sales Funnel Lifecycle Walkthrough (45s)
[1:45 - 2:25] REST API v1 Architecture, Endpoints & Telemetry (40s)
[2:25 - 2:50] Automated Test Suite Execution & Verification Results (25s)
[2:50 - 3:00] Git Branching, Pull Request & Conclusion (10s)
```

---

## 🎬 Detailed Script & Visual Walkthrough

### Part 1: Introduction & Architectural Overview (0:00 – 0:30 | 30 Seconds)

* **Visual Display:** Web browser displaying the EspoCRM login and home dashboard at `http://localhost:8080`, transitioning to the GitHub repository README.
* **Operator Action:** Hover over the EspoCRM header logo and user profile menu (`Admin`).

> **Speaker Narration:**
> "Hello everyone. My name is Sachidananda Nayak, and in this 3-minute technical demo, I am presenting the final capstone deliverable for Week 6 Day 5 of the Cynaris Solutions Full Stack Development Internship.
> Over the past weeks, we developed and deployed an enterprise-grade EspoCRM fork featuring an isolated multi-service container architecture, a zero-cost public HTTPS gateway, complete CRM sales funnel automation, authenticated REST API v1 telemetry, and an automated verification test suite.
> Let's dive right into our deployment architecture."

---

### Part 2: Production Container Architecture & Gateway (0:30 – 1:00 | 30 Seconds)

* **Visual Display:** Split screen showing `docker-compose.production.yml` in VS Code and terminal showing container statuses via `docker compose ps` or architecture diagrams.
* **Operator Action:** Scroll through lines 1–60 of `docker-compose.production.yml`, highlighting the 4 services.

> **Speaker Narration:**
> "Our production environment is defined in `docker-compose.production.yml`. It orchestrates four decoupled microservices:
> First, `espocrm-prod-app` running PHP 8.3 and Apache on port 8080.
> Second, `espocrm-prod-db` running MariaDB 11.4 LTS with InnoDB memory tuning and healthchecks.
> Third, `espocrm-prod-daemon` handling asynchronous background jobs and cron queues.
> And fourth, `espocrm-websocket` on port 8081 for real-time notifications.
> All services use isolated networks, persistent named volumes, and high-availability restart policies.
> To provide zero-cost public HTTPS access without paid hosting, we integrated a secure Localtunnel reverse proxy gateway at `bright-nails-carry.loca.lt`."

---

### Part 3: Live CRM Sales Funnel Lifecycle (1:00 – 1:45 | 45 Seconds)

* **Visual Display:** EspoCRM Web UI navigating across:
  1. Leads List (`Elena Rostova`)
  2. Accounts List (`Apex Enterprise Global`)
  3. Opportunities Pipeline (`Apex Enterprise Cloud CRM Deployment & Migration` - $75,000)
  4. Calendar / Activities (`Meeting: Production Architecture & Security Sign-Off`)
* **Operator Action:** Click through each entity link showing relational foreign keys and stage progression.

> **Speaker Narration:**
> "Next, let's observe our complete CRM sales lifecycle in action.
> We start with our Lead: Elena Rostova from Apex Enterprise Global, representing a $75,000 enterprise cloud migration inquiry.
> This Lead is converted into an Account entity: Apex Enterprise Global, categorized under the Telecommunications industry.
> Linked to this Account is our active Opportunity: Apex Enterprise Cloud CRM Deployment, sitting at the Prospecting stage with a 50% probability and an expected close date in late 2026.
> Finally, we have an Activity scheduled: an executive Meeting for Production Architecture and Security Sign-Off, assigned to Admin.
> Every entity relationship is validated, maintaining referential integrity across the entire customer lifecycle."

---

### Part 4: REST API v1 Architecture & Telemetry (1:45 – 2:25 | 40 Seconds)

* **Visual Display:** Postman, curl terminal, or Insomnia executing REST API calls against `http://localhost:8080/api/v1/`.
* **Operator Action:** Trigger `GET /api/v1/App/user`, highlight the HTTP 200 response; then show `POST /api/v1/Lead` and `GET /api/v1/Opportunity`.

> **Speaker Narration:**
> "Now let's examine the backend REST API.
> First, our authenticated telemetry endpoint `GET /api/v1/App/user`. Using HTTP Basic authentication, the server returns status 200 OK along with authenticated admin credentials, current timezone UTC, version 10.0.8, and active WebSocket connection parameters.
> Second, our programmatic creation endpoint `POST /api/v1/Lead` receives JSON data for contact info, lead source, and deal value, instantly generating a unique hex ID.
> Third, our pipeline query `GET /api/v1/Opportunity` returns serialized deal pipelines, weighted revenue totals, and account associations.
> The API adheres strictly to REST principles and enforces strict role-based access control."

---

### Part 5: Automated Verification Test Suite (2:25 – 2:50 | 25 Seconds)

* **Visual Display:** Terminal executing `php -l tests/unit/verify_production_deployment.php` and `php tests/unit/verify_production_deployment.php`.
* **Operator Action:** Highlight test output banners and assertions.

> **Speaker Narration:**
> "Quality assurance and verification are fully automated.
> In the terminal, running PHP lint confirms zero syntax errors.
> Executing our verification script `verify_production_deployment.php` performs comprehensive assertions across Docker compose architecture, healthcheck definitions, volume persistence, live HTTP response headers, API payload integrity, and CRM relational models.
> The suite provides deterministic validation for our continuous integration pipeline."

---

### Part 6: Git Branching, Pull Request & Conclusion (2:50 – 3:00 | 10 Seconds)

* **Visual Display:** GitHub PR comparison page comparing `feat/w6d5-3m-sachidananda` with `upstream/master`.
* **Operator Action:** Highlight clean git commit history rebased cleanly against upstream.

> **Speaker Narration:**
> "In conclusion, all W6D5 deliverables are finalized on branch `feat/w6d5-3m-sachidananda`, cleanly rebased on `upstream/master`, preserving all previous features without regressions.
> Thank you for watching!"

---

## 📋 Recording Checklist & Recommendations

| Item | Recommendation | Checked |
| :--- | :--- | :---: |
| **Screen Resolution** | 1080p (1920x1080) at 100% DPI scaling | [ ] |
| **Audio Quality** | Crisp microphone audio with background noise suppression | [ ] |
| **Browser Tabs Prepared** | EspoCRM Dashboard (`localhost:8080`), Lead View, Opportunity View | [ ] |
| **Terminal Ready** | Clear terminal with pre-typed `php tests/unit/verify_production_deployment.php` | [ ] |
| **Recording Software** | Loom / OBS Studio / Windows Game Bar (Win + Alt + R) | [ ] |
| **Pacing** | Maintain ~130–140 words per minute to fit exactly in 180 seconds | [ ] |
