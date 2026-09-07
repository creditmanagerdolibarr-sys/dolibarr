-- ============================================================================
-- Credit Manager - Initial credit types (default data)
-- ============================================================================

INSERT INTO llx_credits_types (entity, code, label, unit, auto_debit, debit_delay_days, active, precision_unit) VALUES
(1, 'SUPPORT_HOURS', 'Heures Support', 'hour', 0, 30, 1, 'hour'),
(1, 'PROJECT_HOURS', 'Heures Projet', 'hour', 1, NULL, 1, 'hour'),
(1, 'TRAINING_HOURS', 'Crédits Formation', 'hour', 0, NULL, 1, 'hour');
