-- ============================================================================
-- Credit Manager - Table of credit status 
-- ============================================================================

CREATE TABLE llx_credits_status (
  rowid              integer AUTO_INCREMENT PRIMARY KEY,
  status_name        varchar(80) NOT NULL UNIQUE,
  tms                timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  date_creation      datetime NULL,
  fk_user_creat      integer NULL,
  fk_user_modif      integer NULL
) ENGINE=innodb;
