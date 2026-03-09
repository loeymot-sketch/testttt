# Task Routing Guidelines

This document defines when a task should be assigned to Claude versus Kimi in the QA Loop.

## Route to CLAUDE
Claude is responsible for deep reasoning, structural changes, and complex system integrations. Send to Claude if the task involves:
- Architecture design or refactoring
- Root-cause analysis of complex cascading bugs
- Synchronization logic (WebSockets, Pusher, Queue Jobs)
- Authentication flows, Authorization, and Permissions
- Risky refactors impacting multiple files or domains
- Reading Anti-Gravity test reports to generate a comprehensive execution plan

## Route to KIMI
Kimi acts as the fast, precise implementer for localized tasks. Send to Kimi if the task involves:
- Localized implementation (changes isolated to 1-2 files)
- UI/UX tweaks, styling, HTML/CSS/Flutter layout adjustments
- Simple CRUD (Create, Read, Update, Delete) operations
- Simple component wiring or API route linking
- Small patches and straightforward bug fixes identified by Anti-Gravity
