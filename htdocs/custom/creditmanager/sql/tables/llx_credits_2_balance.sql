-- ============================================================================
-- Credit Manager - Table of client balances per credit type
-- ============================================================================

CREATE TABLE llx_credits_balance (
  rowid           integer AUTO_INCREMENT PRIMARY KEY,
  entity          integer DEFAULT 1 NOT NULL,
  fk_soc          integer NOT NULL,
  fk_credit_type  integer NOT NULL,
  balance         double(24,8) DEFAULT 0 NOT NULL,
  status_balance  varchar(20) DEFAULT 'APPROVED',
  fk_facturedet   integer NULL,
  tms             timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_credits_balance_soc_type (fk_soc, fk_credit_type, entity),
  KEY idx_credits_balance_fk_soc (fk_soc),
  KEY idx_credits_balance_fk_type (fk_credit_type),
  CONSTRAINT fk_credits_balance_soc FOREIGN KEY (fk_soc) REFERENCES llx_societe (rowid),
  CONSTRAINT fk_credits_balance_type FOREIGN KEY (fk_credit_type) REFERENCES llx_credits_types (rowid)
) ENGINE=innodb;
