# MosTicket: MACH Architecture Execution Plan

## AWS Infrastructure · MACH Architecture · Devin + Windsurf Scrum Team

---

## 1. MACH Architecture Mapping

MACH = **Microservices, API-first, Cloud-native, Headless**

MosTicket decomposes into **9 bounded-context microservices**, each independently deployable:

| Service | Epics Covered | AWS Compute | Data Store | Story Points |
|---|---|---|---|---|
| **ticket-service** | REQ-1, REQ-10 | ECS Fargate | Aurora PostgreSQL | 73 |
| **thread-service** | REQ-2 | ECS Fargate | Aurora PostgreSQL + S3 (attachments) | 37 |
| **identity-service** | REQ-5, REQ-6, REQ-21, REQ-22 | ECS Fargate | Aurora PostgreSQL + ElastiCache Redis (sessions) | 81 |
| **routing-service** | REQ-3, REQ-4, REQ-8, REQ-35 | ECS Fargate | Aurora PostgreSQL | 60 |
| **email-service** | REQ-7, REQ-23 | ECS Fargate + SES + SQS | Aurora PostgreSQL + S3 | 51 |
| **forms-service** | REQ-9, REQ-28, REQ-29 | ECS Fargate | Aurora PostgreSQL + DynamoDB (EAV answers) | 42 |
| **knowledge-service** | REQ-11, REQ-12, REQ-26 | ECS Fargate | Aurora PostgreSQL + OpenSearch | 42 |
| **portal-bff** | REQ-14, REQ-18 | ECS Fargate | Redis (cache) | 34 |
| **platform-service** | REQ-13, REQ-15, REQ-16, REQ-17, REQ-19, REQ-24, REQ-25, REQ-30, REQ-31, REQ-32, REQ-33, REQ-34 | ECS Fargate + Lambda | Aurora PostgreSQL + S3 + CloudWatch | 128 |
| **migration-service** | REQ-20 | Step Functions + Lambda | S3 (staging) + Aurora | 26 |
| **Portal UI (headless)** | REQ-14, REQ-18 | CloudFront + S3 | — | 74 |

**Total: 648 story points**

---

## 2. AWS Infrastructure Stack

```
                        ┌─────────────────┐
                        │   CloudFront    │  ← Headless UI (React/Next.js)
                        │   + S3 Static   │
                        └────────┬────────┘
                                 │
                        ┌────────▼────────┐
                        │   API Gateway   │  ← API-first entry point
                        │   (HTTP API)    │
                        └────────┬────────┘
                                 │
                    ┌────────────┼────────────┐
                    │            │            │
              ┌─────▼─────┐ ┌───▼───┐ ┌──────▼──────┐
              │ ALB + ECS  │ │  ALB  │ │   ALB + ECS │
              │ (public)   │ │(staff)│ │  (internal) │
              └─────┬─────┘ └───┬───┘ └──────┬──────┘
                    │            │            │
         ┌──────────┼────────────┼────────────┼──────────┐
         │     ECS Fargate Cluster (private subnets)     │
         │                                               │
         │  ┌──────────┐ ┌──────────┐ ┌──────────────┐  │
         │  │ ticket-  │ │ thread-  │ │  identity-   │  │
         │  │ service  │ │ service  │ │  service     │  │
         │  └──────────┘ └──────────┘ └──────────────┘  │
         │  ┌──────────┐ ┌──────────┐ ┌──────────────┐  │
         │  │ routing- │ │ email-   │ │  forms-      │  │
         │  │ service  │ │ service  │ │  service     │  │
         │  └──────────┘ └──────────┘ └──────────────┘  │
         │  ┌──────────┐ ┌──────────┐ ┌──────────────┐  │
         │  │knowledge-│ │portal-bff│ │  platform-   │  │
         │  │ service  │ │          │ │  service     │  │
         │  └──────────┘ └──────────┘ └──────────────┘  │
         └───────────────────────────────────────────────┘
                    │            │            │
         ┌──────────┼────────────┼────────────┼──────────┐
         │          Data Layer                           │
         │  ┌──────────────┐  ┌───────────┐             │
         │  │Aurora Postgres│  │ElastiCache│             │
         │  │ (Multi-AZ)   │  │  Redis    │             │
         │  └──────────────┘  └───────────┘             │
         │  ┌──────────┐  ┌───────────┐  ┌───────────┐ │
         │  │DynamoDB  │  │OpenSearch │  │    S3     │ │
         │  │(EAV data)│  │(search)   │  │(files)   │ │
         │  └──────────┘  └───────────┘  └───────────┘ │
         └───────────────────────────────────────────────┘
                    │
         ┌──────────┼───────────────────────────────────┐
         │    Async / Event Layer                       │
         │  ┌──────────┐  ┌────────────┐               │
         │  │EventBridge│  │    SQS     │               │
         │  │ (events) │  │ (email Q)  │               │
         │  └──────────┘  └────────────┘               │
         │  ┌──────────┐  ┌────────────┐               │
         │  │   SNS    │  │  Lambda    │               │
         │  │(notifs)  │  │(cron/async)│               │
         │  └──────────┘  └────────────┘               │
         └──────────────────────────────────────────────┘
```

### Key AWS Service Choices

| Concern | AWS Service | Why |
|---|---|---|
| **Compute** | ECS Fargate | Serverless containers, no cluster management, auto-scales |
| **API Gateway** | API Gateway HTTP API | API-first entry, rate limiting, auth integration |
| **Relational Data** | Aurora PostgreSQL Serverless v2 | Auto-scales, multi-AZ, compatible with osTicket's relational model |
| **EAV/Dynamic Data** | DynamoDB | Schema-free for dynamic form answers, fast key-value lookups |
| **Search** | OpenSearch Serverless | Full-text search for tickets, FAQ, queues |
| **File Storage** | S3 + CloudFront | Content-addressable with SHA1 keys, signed URLs for secure access |
| **Cache/Sessions** | ElastiCache Redis | Session store, CSRF tokens, ticket locks, caching |
| **Email Inbound** | SES + SQS | SES receives email → SQS queue → email-service processes |
| **Email Outbound** | SES | Templated sending, bounce/complaint handling |
| **Events** | EventBridge | Pub/sub signal replacement (ticket.created, ticket.closed, etc.) |
| **Notifications** | SNS | Fan-out alerts to email, SMS, webhook |
| **Scheduled Jobs** | EventBridge Scheduler + Lambda | Replaces cron: overdue check, log purge, session cleanup |
| **Migration ETL** | Step Functions + Lambda | Orchestrated multi-step data migration with checkpointing |
| **CDN / Static** | CloudFront + S3 | Headless UI distribution |
| **Auth** | Cognito + ALB OIDC | Dual user pools (staff/client), LDAP federation, 2FA |
| **Monitoring** | CloudWatch + X-Ray | Logs, metrics, distributed tracing |
| **IaC** | Terraform + CDK | Infrastructure as code, environment parity |
| **CI/CD** | GitHub Actions + ECR + ECS deploy | Same pattern as kgs-casper-app |
| **Secrets** | Secrets Manager | API keys, DB credentials, email passwords |

---

## 3. Scrum Team Structure: Devin + Windsurf + Humans

### Team Composition

| Role | Tool/Person | Responsibilities |
|---|---|---|
| **Product Owner** | Human | Backlog prioritization, acceptance, stakeholder comms |
| **Scrum Master** | Human | Sprint ceremonies, impediment removal, velocity tracking |
| **Tech Lead / Architect** | Human | Architecture decisions, PR reviews, integration design |
| **Backend Dev 1** | **Devin** | Microservice implementation, API contracts, DB schemas, tests |
| **Backend Dev 2** | **Devin** | Microservice implementation, event handlers, business logic |
| **Backend Dev 3** | **Devin** | Migration ETL, data modeling, infrastructure code |
| **Frontend Dev** | **Windsurf** | React UI components, portal, agent interface |
| **Full-Stack Dev** | **Windsurf** | BFF layer, integration glue, API client SDKs |
| **QA / Test** | **Devin** | Integration tests, load tests, accessibility audits |

### How Devin and Windsurf Work Together

```
Sprint Backlog
    │
    ├──► Devin Sessions (backend-focused)
    │    ├── Devin A: ticket-service + thread-service
    │    ├── Devin B: identity-service + routing-service
    │    ├── Devin C: email-service + platform-service
    │    └── Devin D: migration-service + IaC/Terraform
    │
    ├──► Windsurf Sessions (frontend-focused)
    │    ├── Windsurf A: Client Portal UI (React)
    │    └── Windsurf B: Agent Interface UI (React)
    │
    └──► Human Review
         ├── Tech Lead reviews all PRs
         ├── PO validates acceptance criteria
         └── Scrum Master tracks velocity
```

### Devin's Role (Backend Services)

For each story, Devin would:

1. **Read the story + acceptance criteria** from the CSV/Jira
2. **Generate the API contract** (OpenAPI spec) — API-first means contracts come before implementation
3. **Scaffold the microservice** (if new) using the team's template repo
4. **Implement the business logic** in TypeScript/Node.js or Python (FastAPI)
5. **Write database migrations** (Prisma, Alembic, or raw SQL)
6. **Write unit + integration tests** matching every acceptance criterion
7. **Create the PR** with CI passing
8. **Iterate on review feedback** from the Tech Lead

**Devin is ideal for:**
- Translating osTicket PHP source code to modern TypeScript/Python microservices
- Writing Terraform/CDK infrastructure code
- Generating OpenAPI specs from the requirement descriptions
- Building the ETL migration pipeline (REQ-20)
- Writing comprehensive test suites

### Windsurf's Role (Frontend / UI)

For each UI story, Windsurf would:

1. **Consume the OpenAPI contract** generated by Devin
2. **Build React components** matching osTicket's UI patterns, modernized
3. **Implement the BFF layer** that aggregates multiple microservice calls
4. **Handle state management** (React Query for server state)
5. **Build responsive, accessible UI** (WCAG 2.1 AA)

**Windsurf is ideal for:**
- Rapid UI prototyping with live preview
- Component-driven development with hot reload
- Accessibility testing in real browser context
- CSS/Tailwind styling iterations

### Handoff Protocol

```
Devin creates API contract (OpenAPI YAML)
         │
         ▼
Devin implements + deploys service to dev environment
         │
         ▼
Windsurf consumes API contract to generate client SDK
         │
         ▼
Windsurf builds UI against running dev service
         │
         ▼
Both PRs reviewed by Tech Lead → merged → deployed to staging
```

---

## 4. Sprint Plan (2-week sprints, ~60 SP capacity)

### Velocity Assumptions
- **Devin agents (3):** ~15 SP/sprint each = 45 SP backend
- **Windsurf agents (2):** ~10 SP/sprint each = 20 SP frontend
- **Overhead/review:** ~5 SP deducted
- **Net capacity:** ~60 SP/sprint
- **Total:** 648 SP ÷ 60 SP/sprint ≈ **11 sprints (~22 weeks)**

### Sprint Sequence

| Sprint | Focus | Epics | SP |
|---|---|---|---|
| **S1** | Foundation | REQ-33 (Salesforce→AWS env setup), REQ-30 (security infra), REQ-31 (events) | 44 |
| **S2** | Core Data Model | REQ-1 (ticket), REQ-35 (priority) | 55 |
| **S3** | Communication | REQ-2 (thread), REQ-29 (sequences) | 43 |
| **S4** | Routing & SLA | REQ-3 (departments), REQ-4 (SLA) | 57 |
| **S5** | Users & Auth | REQ-5 (users/orgs), REQ-6 (auth) | 56 |
| **S6** | Email Pipeline | REQ-7 (email system), REQ-23 (email admin) | 62 |
| **S7** | Automation & Forms | REQ-8 (filters), REQ-9 (dynamic forms) | 50 |
| **S8** | Agent Interface | REQ-18 (agent UI), REQ-21 (staff admin), REQ-22 (roles) | 49 |
| **S9** | Client Portal & KB | REQ-14 (portal), REQ-11 (KB), REQ-28 (custom lists) | 52 |
| **S10** | Platform Features | REQ-10 (tasks), REQ-12 (queues/search), REQ-13 (files), REQ-16 (API), REQ-17 (plugins) | 59 |
| **S11** | Config, Reports, Migration | REQ-15 (dashboard), REQ-19 (config), REQ-24 (cron), REQ-25 (logging), REQ-26 (pages), REQ-27 (i18n), REQ-32 (setup), REQ-34 (NFRs), REQ-20 (migration) | 69 |

### Sprint 1 Detailed Example (Foundation Sprint)

**Goal:** Stand up the AWS infrastructure, event bus, and security primitives so all subsequent sprints can deploy services.

| Story | Assignee | SP |
|---|---|---|
| REQ-33.1: Org Config & Connected Apps | Devin C (IaC) | 5 |
| REQ-33.2: Custom Object Deployment → Terraform modules | Devin C (IaC) | 8 |
| REQ-33.3: Permission Sets & Profiles → Cognito user pools | Devin B | 5 |
| REQ-33.4: CI/CD Pipeline | Devin C (IaC) | 8 |
| REQ-33.5: Environment Strategy | Devin C (IaC) | 5 |
| REQ-30.1: Session Management (Redis) | Devin A | 5 |
| REQ-30.2: CSRF Protection | Devin A | 3 |
| REQ-30.3: Crypto Utilities | Devin A | 3 |
| REQ-31.1: Signal Framework (EventBridge) | Devin B | 5 |
| **Sprint Total** | | **47** |

**Devin Task Execution for REQ-33.4 (CI/CD Pipeline):**
```
Devin Session:
1. Read acceptance criteria from Jira
2. Examine existing kgs-casper-app GitHub Actions workflows as reference
3. Create Terraform modules for:
   - ECR repositories (one per microservice)
   - ECS cluster + service definitions
   - GitHub Actions OIDC role for deployments
4. Create GitHub Actions workflow:
   - On PR: build, test, validate Terraform plan
   - On merge to develop: deploy to dev ECS
   - On tag: deploy to staging with approval gate
5. Write integration test verifying end-to-end deploy
6. Create PR, wait for CI, iterate on review
```

---

## 5. Inter-Service Communication

### Synchronous (API-first)
- **API Gateway → services:** REST/JSON over HTTPS
- **Service-to-service:** Internal ALB with service discovery (Cloud Map)
- **Contract:** OpenAPI 3.1 specs checked into repo, validated in CI

### Asynchronous (Event-driven)
- **EventBridge** replaces osTicket's Signal::send/connect pattern:

```json
{
  "source": "mosticket.ticket-service",
  "detail-type": "ticket.created",
  "detail": {
    "ticket_id": "T-00001",
    "source": "email",
    "dept_id": "D-003",
    "user_id": "U-12345"
  }
}
```

| osTicket Signal | EventBridge Event | Consumers |
|---|---|---|
| ticket.created | mosticket.ticket.created | email-service (auto-resp), routing-service (filters) |
| ticket.closed | mosticket.ticket.closed | platform-service (metrics), email-service (notification) |
| ticket.assigned | mosticket.ticket.assigned | email-service (alert), thread-service (event log) |
| ticket.overdue | mosticket.ticket.overdue | email-service (alert), ticket-service (escalate) |
| cron | mosticket.cron.tick | email-service (fetch), platform-service (purge) |

### Data Ownership (each service owns its data)

| Service | Owns Tables | Exposes via API |
|---|---|---|
| ticket-service | tickets, ticket_status, ticket_cdata | GET/POST/PUT /tickets |
| thread-service | threads, thread_entries, thread_events, collaborators, referrals | GET/POST /threads/{id}/entries |
| identity-service | users, user_emails, organizations, staff, roles, dept_access | GET/POST /users, /staff, /auth |
| routing-service | departments, teams, help_topics, sla_plans, schedules, filters | GET/POST /departments, /sla |
| email-service | email_accounts, email_templates, banlist | POST /email/send, /email/fetch |
| forms-service | forms, form_fields, form_entries, form_answers, lists, sequences | GET/POST /forms, /lists |
| knowledge-service | faq, faq_categories, canned_responses, pages, queues, saved_searches | GET/POST /faq, /search |
| platform-service | config, logs, audit_trail, api_keys, plugins, files, attachments | GET/POST /config, /files |

---

## 6. Migration Strategy (REQ-20)

```
osTicket MySQL ──► Step Functions Pipeline ──► Aurora PostgreSQL
                        │
                        ├── Step 1: Extract (Lambda reads MySQL, writes to S3 as Parquet)
                        ├── Step 2: Transform (Lambda maps schemas, generates new IDs)
                        ├── Step 3: Crosswalk (DynamoDB stores old_id → new_id mapping)
                        ├── Step 4: Load (Lambda bulk inserts into Aurora)
                        ├── Step 5: Files (Lambda streams ost_file_chunk → S3)
                        └── Step 6: Validate (Lambda compares counts + checksums)
```

**Devin would build this entire pipeline** as a series of Lambda functions orchestrated by Step Functions, with the crosswalk table in DynamoDB for O(1) ID lookups.

---

## 7. Cost Estimate (Monthly, Production)

| Service | Spec | Est. Monthly |
|---|---|---|
| ECS Fargate (9 services) | 0.5 vCPU / 1GB each, avg 2 tasks | ~$300 |
| Aurora PostgreSQL Serverless v2 | 2-8 ACU, Multi-AZ | ~$400 |
| ElastiCache Redis | cache.t4g.medium, 1 node | ~$70 |
| DynamoDB | On-demand, ~10M reads/month | ~$30 |
| OpenSearch Serverless | 2 OCU search, 2 OCU index | ~$350 |
| S3 | 100 GB storage + requests | ~$10 |
| CloudFront | 100 GB transfer | ~$15 |
| SES | 50K emails/month | ~$5 |
| SQS/EventBridge/SNS | Standard usage | ~$10 |
| API Gateway | 5M requests/month | ~$20 |
| CloudWatch/X-Ray | Standard logging + traces | ~$50 |
| Secrets Manager | 20 secrets | ~$10 |
| **Total** | | **~$1,270/mo** |

---

## 8. Key Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Language | TypeScript (Node.js 20) | Team familiarity, Devin/Windsurf excel at TS, shared types frontend↔backend |
| Framework | Fastify (services), Next.js (UI) | Performance, API-first plugins, React SSR |
| ORM | Prisma | Type-safe, migration generation, maps well from osTicket's VerySimpleModel |
| Testing | Vitest + Playwright | Fast unit tests, E2E browser testing |
| IaC | Terraform (infra) + CDK (app constructs) | Terraform for foundational infra, CDK for service-level resources |
| Monorepo | Turborepo | Shared types/utils, coordinated builds, each service is a package |
| API Contract | OpenAPI 3.1 + code generation | Contract-first development, auto-generated clients for Windsurf |

---

## Summary

- **648 story points** across **35 epics / 101 stories**
- **~22 weeks** (11 two-week sprints) with 3 Devin + 2 Windsurf agents
- **9 microservices** on ECS Fargate, event-driven via EventBridge
- **~$1,270/month** AWS cost at production scale
- **Devin** handles backend services, IaC, migration, and testing
- **Windsurf** handles headless UI (client portal + agent interface)
- **Humans** handle architecture, review, and product decisions
