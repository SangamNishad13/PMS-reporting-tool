# Safe Cleanup Report (project_management)

- Generated: 2026-02-20 00:00:19
- Rules: `safe_drop_now = rows=0 AND incoming_fk=0 AND outgoing_fk=0 AND code_refs=0`
- Source files: `database/cleanup_report_project_management.csv`

## Summary
- Total tables: 72
- Safe to drop now: 0
- Empty but still used/linked: 38
- Non-empty tables: 34

## Safe To Drop Now
- None

## Empty But Used/Linked (Do Not Drop Blindly)
- admin_credentials (incoming_fk=0, outgoing_fk=1, code_refs=1)
- admin_meetings (incoming_fk=0, outgoing_fk=1, code_refs=1)
- admin_notes (incoming_fk=0, outgoing_fk=1, code_refs=1)
- admin_todos (incoming_fk=0, outgoing_fk=1, code_refs=1)
- assignments (incoming_fk=0, outgoing_fk=4, code_refs=51)
- chat_message_history (incoming_fk=0, outgoing_fk=0, code_refs=2)
- chat_messages (incoming_fk=0, outgoing_fk=3, code_refs=8)
- common_issues (incoming_fk=0, outgoing_fk=2, code_refs=1)
- device_assignments (incoming_fk=0, outgoing_fk=3, code_refs=1)
- device_rotation_history (incoming_fk=0, outgoing_fk=4, code_refs=3)
- device_switch_requests (incoming_fk=0, outgoing_fk=4, code_refs=1)
- feedback_recipients (incoming_fk=0, outgoing_fk=2, code_refs=5)
- feedbacks (incoming_fk=1, outgoing_fk=3, code_refs=6)
- grouped_urls (incoming_fk=0, outgoing_fk=2, code_refs=16)
- issue_active_editors (incoming_fk=0, outgoing_fk=0, code_refs=1)
- issue_comment_history (incoming_fk=0, outgoing_fk=0, code_refs=1)
- issue_comments (incoming_fk=1, outgoing_fk=5, code_refs=6)
- issue_drafts (incoming_fk=0, outgoing_fk=2, code_refs=2)
- issue_history (incoming_fk=0, outgoing_fk=1, code_refs=4)
- issue_metadata (incoming_fk=0, outgoing_fk=1, code_refs=10)
- issue_pages (incoming_fk=0, outgoing_fk=2, code_refs=4)
- issue_presence_sessions (incoming_fk=0, outgoing_fk=0, code_refs=1)
- issues (incoming_fk=5, outgoing_fk=8, code_refs=52)
- notifications (incoming_fk=0, outgoing_fk=1, code_refs=15)
- page_environments (incoming_fk=0, outgoing_fk=6, code_refs=28)
- project_assets (incoming_fk=0, outgoing_fk=2, code_refs=6)
- project_permissions (incoming_fk=0, outgoing_fk=3, code_refs=21)
- project_permissions_types (incoming_fk=0, outgoing_fk=0, code_refs=3)
- project_time_log_history (incoming_fk=0, outgoing_fk=0, code_refs=3)
- qa_results (incoming_fk=0, outgoing_fk=2, code_refs=13)
- qa_status_permissions (incoming_fk=0, outgoing_fk=0, code_refs=3)
- regression_tasks (incoming_fk=0, outgoing_fk=0, code_refs=1)
- status_options (incoming_fk=0, outgoing_fk=0, code_refs=2)
- testing_results (incoming_fk=0, outgoing_fk=3, code_refs=20)
- user_assignments (incoming_fk=0, outgoing_fk=4, code_refs=38)
- user_calendar_notes (incoming_fk=0, outgoing_fk=1, code_refs=5)
- user_generic_tasks (incoming_fk=0, outgoing_fk=2, code_refs=6)
- user_qa_performance (incoming_fk=0, outgoing_fk=3, code_refs=2)

## Top Non-Empty Tables
- issue_presets (rows=158)
- user_sessions (rows=110)
- qa_status_master (rows=21)
- phase_master (rows=12)
- issue_status_master (rows=8)
- issue_metadata_fields (rows=8)
- project_statuses (rows=7)
- users (rows=7)
- issue_statuses (rows=6)
- wcag_sc (rows=6)
- issue_types (rows=6)
- page_testing_status_master (rows=6)
- env_status_master (rows=6)
- page_qa_status_master (rows=5)
- user_edit_requests (rows=5)
