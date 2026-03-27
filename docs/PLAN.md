# Plan: Security Audit of New Skill

## Goal
Perform a comprehensive security audit of the `code-yeongyu-oh-my-opencode-frontend-ui-ux` skill folder and its potential impact on the system.

## Proposed Agents
- 🤖 **project-planner**: Initial coordination and task breakdown.
- 🤖 **explorer-agent**: Deep dive into the skill's files and references.
- 🤖 **security-auditor**: Primary role for vulnerability assessment and compliance check.
- 🤖 **penetration-tester**: Verification of exploitability for identified risks.
- 🤖 **documentation-writer**: Synthesis of the Final Audit Report.

## Phase 1: Planning & Discovery
- [x] Scan the `code-yeongyu-oh-my-opencode-frontend-ui-ux` folder recursively for hidden scripts or assets.
- [ ] Analyze `SKILL.md` for insecure instructions or prompts that could lead to Prompt Injection or Insecure Code Generation.
- [ ] Check for hardcoded secrets or sensitive metadata in the markdown.

## Phase 2: Security Assessment (Implementation)
- [ ] **Static Analysis**: Audit any scripts (if found) against OWASP 2025 Top 10.
- [ ] **Prompt Injection Check**: Evaluate if the persona instructions can be bypassed or manipulated.
- [ ] **Supply Chain Check**: Verify if the skill references external unvetted resources (CDN, fonts, etc.).

## Phase 3: Verification & Reporting
- [ ] Execute `security_scan.py` on the skill directory.
- [ ] Generate a detailed Orchestration Report with findings and remediation.

## Verification Plan
- Run `python .agent/scripts/checklist.py .` focused on the security layer.
- Manual review by `security-auditor`.
