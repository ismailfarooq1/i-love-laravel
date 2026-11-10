<p align="center">
  <img src="resources/tracework-banner.svg" alt="Tracework — Your judgment. AI’s legwork. A visual path from goals to tasks." width="100%" />
</p>

<p align="center">
  <strong>Turn big ideas into clear, traceable work. Keep people in control.</strong>
</p>

<p align="center">
  <a href="#the-idea">The idea</a> ·
  <a href="#what-you-can-do-today">Features</a> ·
  <a href="#run-it-locally">Quick start</a> ·
  <a href="#take-a-tour">Walkthrough</a> ·
  <a href="#whats-next">Roadmap</a>
</p>

---

## The idea

Tracework is a visual planning tool for turning documents and goals into an implementation backlog. It is built around a simple principle: **AI prepares the work; humans make the decisions.**

Create multiple top-level goals, break them into smaller outcomes, and refine those outcomes into actionable tasks. Explore the hierarchy in a Three.js goal map. Open any node in an overlay to inspect its proposal, sources, assumptions, decisions, dependencies, and history without leaving the map.

The intended destination is a reviewed GitHub backlog, with enough context for a human teammate—and eventually an agent—to pick up a bounded piece of work.

> [!IMPORTANT]
> **This is a working local prototype.** Planning, reviews, document storage, completion tracking, and publication previews are persisted in Laravel. Real AI document analysis uses the Laravel AI SDK with OpenAI, Claude, or Gemini and a team-supplied API key. Document text is sent only after explicit selection and consent. Prepared demo scenarios remain available separately. Connecting GitHub enables **real issue publishing and completion synchronization**, subject to explicit human review. Historical simulated batches remain labeled as simulations.

## What you can do today

| Capability | How it works |
| :--- | :--- |
| **Team access and isolation** | Register, verify your email, switch team workspaces, and invite people with explicit roles. |
| **Projects and repositories** | Create multiple team projects, each connected to one repository or kept offline. |
| **Multiple independent goals** | Add objectives with their own branches, decisions, approvals, and tasks. |
| **A visual goal map** | Pan, zoom, expand branches, focus on a node, and fit the hierarchy to the view. A keyboard-accessible outline provides another way to navigate. |
| **Node overlays** | Review and edit a goal or task, inspect evidence, and take available actions while the map stays behind the overlay. |
| **Versioned source documents** | Paste text or upload UTF-8 `.txt` and `.md` files up to 100 KB. Earlier source versions remain available. |
| **Inspectable proposals** | Read source passages with line references, see explicit assumptions, and compare recorded revisions. |
| **Human decision checkpoints** | Review proposed breakdowns and resolve ambiguous requirements before affected work proceeds. |
| **Approval and completion gates** | Approval flows down the hierarchy; completion flows back up. Task prerequisites are enforced separately. |
| **Publication previews** | Select approved nodes, review a batch that includes their goal ancestors, and explicitly approve real issue creation or updates, preserving native sub-issues and task dependencies. |
| **Persistent review history** | Save decisions, revision snapshots, completion events, and publication records in the database. |

## How the workflow fits together

```mermaid
flowchart LR
    A[Documents and goals] --> B[Proposed breakdown]
    B --> C[Human review]
    C --> D[Approved goals and tasks]
    C --> Q[Clarification needed]
    Q --> C
    D --> E[Publication preview]
    E --> F[Human approval]
    F --> G[GitHub issues and sub-issues]
```

A goal describes an **outcome**. A task describes a **bounded piece of implementation work**. Branches can have different depths, and each branch moves forward through its own review checkpoints.

### Approval goes down. Completion comes up.

| Rule | What it means |
| :--- | :--- |
| **Approve the parent first** | A child cannot be approved until its parent and ancestor approvals are current. Required breakdown reviews must also be complete. |
| **Finish the children first** | A parent with four active children stays incomplete until all four children are complete. Rejected proposals are excluded. |
| **Finish prerequisites first** | A task cannot be completed while a prerequisite task is unfinished. |
| **Approval is not completion** | Approving a proposal agrees the plan. Marking it complete confirms the work is finished. |
| **Parents never finish automatically** | Once its children are complete, a person still explicitly marks the parent complete. |

The map uses **solid links for parent–child relationships** and **dashed arrows for prerequisite → dependent task relationships**. Enable **Dependencies** to show prerequisite links. Node cards display progress such as `2/4 children complete`.

### Changes stay visible

- Editing a proposal invalidates its approval and marks descendants for review; unrelated branches retain their approvals.
- Revising a referenced document preserves the original source and flags affected work for review.
- Reopening or changing completed work also reopens completed parents and dependent tasks.
- Approval history records the reviewed revision, actor label, timestamp, and snapshot.
- Stale browser tabs cannot overwrite a newer workspace version, and changed publication previews require a fresh review.

## Run it locally

### Requirements

- PHP **8.3**, Composer, and the PHP extensions needed by Laravel and your database driver.
- Node.js **20.19+ or 22.12+**, with npm.
- PostgreSQL, as configured in `.env.example`, or SQLite for a lightweight local setup.
- A browser with WebGL support for the Three.js map. The outline remains available if the 3D view cannot initialize.

### 1. Install dependencies

From the project directory:

```bash
composer install
cp .env.example .env
php artisan key:generate --no-interaction
npm ci
```

If you already have a configured `.env`, keep it instead of copying the example over it.

### 2. Configure a database

For **PostgreSQL**, create a local database and update the `DB_*` values in `.env` to match your connection.

For **SQLite**, create a database file:

```bash
touch database/database.sqlite
```

Then set these values in `.env`, using the actual absolute path to your checkout:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/absolute/path/to/i-love-laravel/database/database.sqlite
```

### 3. Initialize the workspace

```bash
php artisan migrate --no-interaction
php artisan db:seed --class=BacklogProjectSeeder --no-interaction
```

Register an account, verify your email, and initialize your team workspace from the application. The seeder initializes examples for existing teams that do not yet have a workspace; it does not create accounts. With the default local mail logger, verification and password reset links are written to `storage/logs/laravel.log`. Configure a mail transport to deliver them to an inbox.

### 4. Start development

```bash
composer run dev
```

Open the address printed by the Laravel development server. The development command starts Laravel, Vite, the queue listener, and the log viewer.

**Manual planning needs no API key.** Real document analysis requires a team AI connection; GitHub is optional and configured separately.

## Set up real AI planning

An Owner or Admin opens a project’s **AI planning → AI connections**, chooses OpenAI, Claude (Anthropic), or Gemini, and saves the team’s provider API key and model. The suggested model comes from the installed SDK’s lower-cost model setting; administrators may choose another model supported by their provider account. Chat subscriptions and API access are separate. The provider bills the team directly. Keys are encrypted using `APP_KEY`, hidden from responses, and never included in queued job payloads. Keep `APP_KEY` stable. Removing a key disables new analyses across the team; it preserves past results.

1. Add your own brief or notes in **Documents**. Saving a document does not call AI.
2. In **AI planning**, select a connection and up to ten document versions (60 KB combined UTF-8 text).
3. Choose a new main goal, or smaller steps for a goal whose current version and ancestors are approved. Goals with existing smaller steps or unanswered questions cannot be expanded again.
4. Review what will be sent and give explicit consent. The request includes the project name/description, selected documents, and the chosen goal’s details when expanding a goal. The assistant has no tools or GitHub access.
5. A background job requests one main goal or up to eight direct smaller steps. Each stage waits for human review before further decomposition.
6. Review the saved summary, rationale, assumptions, checklist, dependency links, and questions. Evidence document IDs, line ranges, and verbatim quotes are checked server-side. Invalid references, malformed results, and dependency cycles are rejected. Quote matching does not prove an inference is correct.
7. Choose **Add suggestions as drafts**, then review, edit, answer questions, and approve using the normal workflow. Draft import never approves or publishes work. Project changes make outstanding results stale; run analysis again against the current version.

The installed `laravel/ai` SDK handles all three providers through one structured planning agent. Analyses preserve the initiating user, provider/model, instructions, selected document snapshots, response, token usage, and timestamps. Only one queued or running analysis per project is allowed. Jobs have a single queue attempt, a 60-second worker timeout, a 45-second SDK request timeout, and an 8,000-token output limit. Provider-specific rate-limit handling may retry a rejected request. There is no automatic provider fallback. Costs are not estimated from token counts; consult your provider’s billing.

Run `php artisan migrate --no-interaction`, then keep the database queue worker running (`php artisan queue:work --tries=1 --timeout=60`; retry interval must exceed 60 seconds). Restart workers after code changes. No conversation-storage migration is needed: this assistant is stateless and Tracework persists its own analysis records. API failures and interrupted jobs leave the project unchanged; start a new analysis explicitly. Automated tests fake the SDK and outbound HTTP; live model verification requires your own configured key.

## Connect GitHub

Each project targets one repository and supports multiple independent goals. During onboarding choose **GitHub App**, **personal OAuth**, or **connect later**. A team administrator selects the repository and explicitly accepts completion synchronization. Once issue mappings exist, use a new project to target a different repository.

### Register the applications

Use a public HTTPS address that routes to this local application, for example through your preferred development tunnel. Set `APP_URL` to that address and open Tracework through it so authorization state remains in the same browser session. Replace `https://YOUR-HOST` below with that address.

1. **GitHub App:** create an app in GitHub Developer settings. Set its callback URL to `https://YOUR-HOST/github/callback`, setup URL to `https://YOUR-HOST/github/installed`, and active webhook URL to `https://YOUR-HOST/github/webhook`. Set a strong webhook secret. Grant repository **Issues: read and write** and **Metadata: read**; subscribe to **Issues** events. Installation lifecycle events are also processed. Allow installation only on selected repositories. Keep installation and user authorization separate: authorize inside Tracework first, then use its installation button. Generate a client secret and a PEM private key. Record the App ID, slug, client ID, client secret, and private key.
2. **OAuth App:** create a separate OAuth application with callback URL `https://YOUR-HOST/github/callback`. Record its client ID and secret. Personal authorization requests repository, repository-webhook administration, identity, and offline access scopes. The configuring account must administer the selected repository; each personal-mode publisher authorizes their own account.
3. Add the values to your local `.env`; never commit secrets:

```dotenv
GITHUB_APP_ID=YOUR_APP_ID
GITHUB_APP_SLUG=YOUR_APP_SLUG
GITHUB_APP_CLIENT_ID=YOUR_APP_CLIENT_ID
GITHUB_APP_CLIENT_SECRET=YOUR_APP_CLIENT_SECRET
GITHUB_APP_PRIVATE_KEY="-----BEGIN RSA PRIVATE KEY-----\nYOUR_PEM_CONTENT\n-----END RSA PRIVATE KEY-----"
GITHUB_OAUTH_CLIENT_ID=YOUR_OAUTH_CLIENT_ID
GITHUB_OAUTH_CLIENT_SECRET=YOUR_OAUTH_CLIENT_SECRET
GITHUB_WEBHOOK_SECRET=YOUR_STRONG_RANDOM_SECRET
```

Use the same webhook secret in the GitHub App settings. Personal OAuth mode creates or updates a repository Issues webhook using this secret. Preserve `APP_KEY`: stored tokens and cached installation tokens are encrypted with it. Do not share private keys or client secrets in chat.

```bash
php artisan config:clear
php artisan migrate --no-interaction
```

Run a database-backed queue worker and scheduler alongside the web application. `composer run dev` already starts a queue listener; run the scheduler separately:

```bash
php artisan queue:work --tries=5 --timeout=60
php artisan schedule:work
```

Use either the queue listener or the worker. Webhooks must reach the application, and the scheduler queues reconciliation every five minutes. Configure your queue connection's retry interval above the worker timeout (the default database interval is 90 seconds).

### Publish and synchronize

Open **Projects & GitHub**, authorize the chosen identity, install the App if applicable, select a repository, and confirm the synchronization rules. App mode publishes as the installation; personal mode uses the approving user's GitHub identity. Existing email/password sign-in remains unchanged.

Select approved nodes in **GitHub publishing**. The preview includes required ancestors and prerequisites, exact proposed issue bodies, and existing content that would be replaced. Only the person who prepared the preview may approve it. Changed revisions or remote content require a fresh review. Native hierarchy supports up to eight nesting levels and 100 children per parent; oversized hierarchies are rejected before publishing.

Completion synchronizes in both directions. A premature GitHub close is automatically reopened when approvals, children, or prerequisites are incomplete. Reopening required work also reopens completed parents and dependent tasks. Parents never complete automatically. Signed webhook deliveries are deduplicated; periodic reconciliation handles missed events.

Issue mappings survive partial failures. Retry a failed batch to reconcile existing issues and finish missing links. If a creation response was lost, Tracework searches for its stable marker before creating anything else. An unresolved result requires an administrator to check GitHub and explicitly confirm that no issue exists before enabling another creation attempt. Disconnecting pauses publishing and synchronization; it preserves issue mappings and does not remove the GitHub installation or repository webhook. Revoke those separately in GitHub if desired.

Automated integration tests fake GitHub HTTP traffic. A live acceptance check requires registered applications: connect a disposable repository, approve a small hierarchy, publish it, verify sub-issues and dependencies, then test valid completion, premature closure, reopening, and repeated webhook delivery.

## Take a tour

1. **Sign in and explore the example.** The support portal scenario includes approved goals, draft tasks, an unresolved attachment policy, and an independent staff workflow.
2. **Select a node.** Its overlay shows the proposal, evidence, available actions, completion blockers, and history. Use **Close** or **Escape** to return to the map.
3. **Resolve a question.** Review the suggested attachment policy, accept it or enter your own answer, then approve the affected breakdown.
4. **Add another goal.** Choose **+ Add goal**, enter an objective, approve it, and open **AI planning** to request smaller steps from selected documents. Read the evidence, add drafts, and approve the plan when ready.
5. **Follow the gates.** Approve parents before children. Complete prerequisite tasks and child nodes before marking their dependents and parents complete.
6. **Preview publication.** In **GitHub publishing**, select eligible nodes, prepare a batch, inspect its contents, and approve real publication. GitHub must be connected first; otherwise planning remains available.

> [!NOTE]
> The support portal example uses fixed source references. AI planning analyzes only the document versions you select. Quotes are checked against their saved line ranges, but humans must still check the interpretation and assumptions.

**Reset workspace** offers a fresh workspace or the example scenario. Resetting requires confirmation and replaces saved documents, edits, decisions, approvals, and simulated publication batches. Projects with published issues or an uncertain creation attempt cannot be reset; create another project instead.

## Under the hood

| Layer | Technology / responsibility |
| :--- | :--- |
| Backend | Laravel 12 on PHP 8.3; validation, workflow transitions, persistence, and concurrency checks. |
| Interface | React 19 and MUI 7; review overlays, forms, navigation, and accessible controls. |
| Visualization | Three.js; layered goal trees, relationship links, camera controls, and node labels. |
| Assets | Vite 7. |
| Admin foundation | Filament 5 is installed; the planning experience is the custom React application. |
| Verification | PHPUnit 11 and Laravel Pint. |

The prototype stores multiple projects per team, each as a **versioned JSON snapshot** in the `backlog_projects` table. Source versions, goal/task nodes, decisions, history, and publication batches are saved together. Mutations run in database transactions and check the submitted workspace version.

Useful starting points:

- [`BacklogWorkflow`](app/Services/BacklogWorkflow.php): server-enforced approvals, completion rules, invalidation, and publication simulation.
- [`DemoScenario`](app/Services/DemoScenario.php): deterministic example proposals and generic goal breakdowns.
- [`GoalTree`](resources/js/GoalTree.jsx) and [`NodeWorkspace`](resources/js/NodeWorkspace.jsx): visualization and node overlays.
- [`BacklogWorkflowTest`](tests/Feature/BacklogWorkflowTest.php): workflow behavior and failure cases.

### Development checks

```bash
# Workflow feature tests
php artisan test --compact tests/Feature/BacklogWorkflowTest.php

# Full test suite
php artisan test --compact

# Format modified PHP files
vendor/bin/pint --dirty --format agent

# Compile frontend assets
npm run build
```

Tests cover approval gates, completion prerequisites, reopening propagation, dependency cycles, source versioning, upload validation, stale writes, branch isolation, and repeat-safe simulated publication.

## Team access

Authentication includes registration, session-based login/logout, email verification, and password recovery. A new account owns a private team; users can create more teams and switch between memberships.

| Role | Read | Edit / generate / complete | Approve / decide / publish | Manage members / reset |
| :--- | :---: | :---: | :---: | :---: |
| Owner | ✓ | ✓ | ✓ | ✓ |
| Admin | ✓ | ✓ | ✓ | ✓ |
| Reviewer | ✓ | ✓ | ✓ | — |
| Editor | ✓ | ✓ | — | — |
| Viewer | ✓ | — | — | — |

Owners and admins create single-use invitations valid for seven days. Share the generated link with the invited person; acceptance requires a verified account matching the invitation email. Invitations can be revoked, and role changes and removals take effect on subsequent requests. Owners cannot be demoted or removed through membership management.

Workspace reads and writes are scoped to authenticated team membership, including source documents, node IDs, decisions, history, and publication batches. Each browser tab sends its selected team explicitly, so switching teams elsewhere cannot redirect a pending edit into another tenant. New workflow audit events identify the authenticated actor; historical example events retain their original labels. The global Filament panel is not available to ordinary team accounts.

### Preserve an existing demo

The migration preserves the old shared demo as **unassigned**. It is never automatically exposed to a new account. Before initializing a destination team's workspace, an operator can explicitly assign the legacy demo:

```bash
php artisan tracework:adopt-demo TEAM_ID --no-interaction
```

Find the numeric team ID in **Team & access**. The command refuses to overwrite an existing team workspace and preserves the legacy snapshot and history. Team invitations are shared manually; no invitation email is automatically sent.

## Prototype boundaries

Prepared examples remain simulated; the AI planning screen uses real provider APIs after team configuration. GitHub publishing uses real API calls after configuration and approval. PDF and Word ingestion, real repository inspection, human task assignment, and agent execution are not implemented. Production deployment still requires environment, mail delivery, operational monitoring, and performance work.

## What’s next

- [x] Real AI document analysis with structured, source-linked proposals.
- [x] GitHub App and personal OAuth authorization, real issue publishing, native hierarchy, and completion synchronization.
- [x] User accounts, team permissions, and tenant isolation.
- [ ] Human task assignment.
- [ ] Richer document ingestion, including PDF and Word.
- [ ] Repository-aware planning and proposed issue updates when requirements change.
- [ ] Production observability, performance, and deployment work.
- [ ] Eventually, controlled assignment of approved tasks to agents or swarms.

---

<p align="center">
  <strong>Your judgment. AI’s legwork.</strong><br />
  <sub>Evidence you can inspect. Decisions you control.</sub>
</p>
