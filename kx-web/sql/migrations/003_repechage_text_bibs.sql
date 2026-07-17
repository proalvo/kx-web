-- Compatibility with kx-server's real data model:
--  * RQ (repechage) phase exists between qualification and quarter-final
--  * bibs are text (colour bibs, leading zeros)
ALTER TABLE phase MODIFY phase
  ENUM('TIME_TRIAL','QUALIFICATION','REPECHAGE','QUARTER_FINAL',
       'SEMI_FINAL','FINAL','OFFICIAL_RESULT') NOT NULL;
ALTER TABLE phase_entry MODIFY bib VARCHAR(20) NULL;
INSERT IGNORE INTO schema_migration (version) VALUES (3);
