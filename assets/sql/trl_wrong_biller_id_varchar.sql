-- Allow TRL imports to retain alphanumeric partner masterfile identifiers
-- such as CADTEMP3 while preserving existing numeric biller IDs.
ALTER TABLE mldb.trl
    MODIFY wrong_biller_id VARCHAR(150) NULL;
