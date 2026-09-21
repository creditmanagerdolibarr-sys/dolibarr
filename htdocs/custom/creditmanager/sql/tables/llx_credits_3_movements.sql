-- ============================================================================
-- Credit Manager - Table of credit/debit movements history
-- ============================================================================

CREATE TABLE llx_credits_movements (
  rowid               integer AUTO_INCREMENT PRIMARY KEY,
  entity              integer DEFAULT 1 NOT NULL,
  fk_soc              integer NOT NULL,
  fk_credit_type      integer NOT NULL,
  date_movement       datetime NOT NULL,
  amount              double(24,8) NOT NULL,
  balance_after       double(24,8) NULL,
  type_movement       varchar(32) NOT NULL,
  description         varchar(255) NULL,
  fk_timesheet        integer NULL,
  fk_element_time     integer NULL,
  timesheet_elementtype varchar(32) NULL,
  fk_invoice          integer NULL,
  fk_attribution      integer NULL,
  fk_parent_movement  integer NULL,
  fk_user_creat       integer NULL,
  tms                 timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_credits_movements_soc (fk_soc),
  KEY idx_credits_movements_type (fk_credit_type),
  KEY idx_credits_movements_date (date_movement),
  KEY idx_credits_movements_timesheet (fk_timesheet),
  KEY idx_credits_movements_element_time (fk_element_time),
  KEY idx_credits_movements_elementtype (timesheet_elementtype),
  KEY idx_credits_movements_invoice (fk_invoice),
  KEY idx_credits_movements_parent (fk_parent_movement),
  CONSTRAINT fk_credits_movements_soc FOREIGN KEY (fk_soc) REFERENCES llx_societe (rowid),
  CONSTRAINT fk_credits_movements_type FOREIGN KEY (fk_credit_type) REFERENCES llx_credits_types (rowid)
) ENGINE=innodb;
