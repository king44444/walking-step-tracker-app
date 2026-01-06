# Prompts Tracking

This document tracks all active prompts for a walking tracking project. Each prompt represents a discrete unit of work with clear objectives, success criteria, and implementation tasks.

## How to Use This Document

1. **Creating a New Prompt**: Copy the template below and fill in all sections
2. **During Execution**: Update Status to "🔄 In Progress" and check off tasks as completed
3. **Testing**: Run stage-specific tests after each significant change: `pytest -q -k <stage>`
4. **Completion**: Verify all Success Criteria are met, then update Status to "✅ Completed"
5. **Commit**: Update PROMPTS.md status and commit with a descriptive message referencing the prompt

## Overall Status

- **Completed Prompts**: 0 / 0
- **In Progress**: 0
- **Total Active**: 0
- **Phase**: Initial Setup

---

## Prompt Template

Copy this template when creating new prompts:

```markdown
### Prompt [Number]: [Title]

**Status**: 🔄 In Progress | ✅ Completed | ⏸️ Paused

**Problem**:
Brief description of the issue or feature need. What is currently broken or missing?

**Impact**:
- **Current**: What happens now (negative impact)
- **After Fix**: What will happen after implementation (positive outcome)
- **Business Value**: Why this matters to users/operators/developers

**Reference**:
- Link to relevant audit documents, issues, or design docs
- Error messages, logs, or specific examples
- Related code files or modules

**Dependencies**:
List any other prompts that must be completed first, or mark as "None"

**Tasks**:
- [ ] Task 1: Specific, actionable step
- [ ] Task 2: Another specific step
- [ ] Task 3: Implementation task
- [ ] Task 4: Testing task
- [ ] Task 5: Documentation/commit task

**Success Criteria**:
- [ ] Criterion 1: Measurable outcome
- [ ] Criterion 2: Verification step
- [ ] Criterion 3: Test passing requirement
- [ ] All tests pass: `pytest -q -k <relevant_test_pattern>`

**Files to Modify**:
- `path/to/file1.py` (brief description of changes)
- `path/to/file2.py` (brief description of changes)
- `tests/test_new_feature.py` (new test file)

**Notes**:
Additional context, implementation details, code examples, or gotchas.
```

---

## Active Prompts

*(No active prompts yet - add new prompts above this line)*

---

## Quick Reference: Testing Commands

---

## Notes for Next Agent

**Quick Start**:
1. Read `README.md` for comprehensive architecture overview
2. Read `AGENTS.md` for coding guidelines and patterns
3. Check recent commits: `git log --oneline -10`

**Key Resources**:
- **Architecture**: `README.md` - Complete system overview
- **Guidelines**: `AGENTS.md` - Development patterns and best practices


**Common Patterns**:
- Use conventional commits: `feat:`, `fix:`, `refactor:`, `docs:`
- Update task progress: Check off completed tasks in prompts
- Commit frequently with descriptive messages

Ready for new prompts! 🚀
