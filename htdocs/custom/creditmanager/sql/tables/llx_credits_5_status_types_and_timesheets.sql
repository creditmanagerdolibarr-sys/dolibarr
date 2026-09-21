-- ============================================================================
-- Credit Manager - Table of credit status types and timesheets
-- ============================================================================

CREATE TABLE llx_credits_status_types_and_timesheets (
  rowid              integer AUTO_INCREMENT PRIMARY KEY,
  fk_credits_types   integer NULL,
  fk_credits_status  integer NULL,
  fk_element_time    integer NOT NULL,
  tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  date_creation      datetime NULL,
  fk_user_creat      integer NULL,
  fk_user_modif      integer NULL
) ENGINE=innodb;
