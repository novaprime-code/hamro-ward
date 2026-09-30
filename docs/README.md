# Hamro Ward — Documentation Index

| # | Document | Status | Purpose |
|---|---|---|---|
| 00 | `00_PROJECT_CONTEXT.md` | Existing | Product vision and principles |
| — | `Product_Requirements_document` | Existing | PRD and acceptance criteria |
| 01 | `01_CONSTRAINTS_AND_TEAM.md` | Draft v0.6 | Team, budget, launch gates, governance, volunteers |
| 02 | `02_NEPAL_CIVIC_DOMAIN.md` | Draft v0.1 | Nepal administrative and election domain, rules R1–R16 |
| 03 | `03_SRS.md` | Draft v0.4 | Requirements (FR/NFR) with IDs and versions |
| 04 | `04_EDITORIAL_AND_LEGAL.md` | Planned (v0.2) | Editorial policy, moderation guidelines, legal notes |
| 05 | `05_DOMAIN_DATA_MODEL.md` | Draft v0.2 | Database design and import format |
| 06 | `06_TECHNICAL_ARCHITECTURE.md` | Draft v0.2 | System design, infrastructure, security, deployment |
| 07 | `07_DATA_SOURCES.md` | Planned (v0.3) | Source inventory, licences, acquisition |
| 08 | `08_ROADMAP_AND_DELIVERY_PLAN.md` | Draft v0.4 | Versions, weeks, days, process, tracker setup |
| 09 | `09_UX_UI_SPEC.md` | **Out of date** | Design system, screens, components, copy. Still describes the rejected palette and about a third of the approved screens |
| 10 | `10_AI_EVALUATION.md` | Planned (v1.2) | AI evaluation and red-team |
| 11 | `11_THREAT_MODEL.md` | Planned (v0.3) | Full threat model (baseline in 06 §19, 12 §16) |
| 12 | `12_TENANCY_AND_IDENTITY.md` | Draft v0.1 | Tenant per local level (separate databases), citizen and staff accounts, auth |
| 13 | `13_DEMO_DATASET.md` | Living | The four invented municipalities, what each ward demonstrates, how to retire it |
| 14 | `14_CURRENT_STATE.md` | **Living — read first** | What is actually deployed today, what is not built, what to do next |

## Working documents

| Document | Purpose |
|---|---|
| `DEVELOPMENT.md` | Running the project locally, code layout, conventions, and the traps this codebase has already sprung |
| `SETUP.md` | Standing up a server environment, first boot, seeding, troubleshooting |
| `REPO_README.md` | The repository root README — orientation for someone opening the repo |
| `BACKLOG.md` | Generated. All epics, features, tasks; day-by-day plan |
| `DECISIONS.md` | Living. Decision log D-001 … D-017 |
| `CHANGELOG.md` | Living. Documentation and architecture changes |

**Starting a new conversation about this project?** Read `14_CURRENT_STATE.md`
first — it is written for exactly that, and it says what is running, what is
missing and what is worth doing next.

Work items come from `tools/backlog/build_backlog.py`. Regenerate with
`python3 tools/backlog/build_backlog.py`.
