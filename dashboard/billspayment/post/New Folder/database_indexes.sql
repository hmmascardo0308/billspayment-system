-- Recommended Database Indexes for billspay-post-transaction.php Performance Optimization
-- These indexes will significantly improve query performance for large datasets (100K+ records)

-- Index on post_transaction status for filtering
CREATE INDEX idx_post_transaction 
ON mldb.billspayment_transaction(post_transaction);

-- Composite index for date range queries
CREATE INDEX idx_datetime_post 
ON mldb.billspayment_transaction(datetime, post_transaction);

CREATE INDEX idx_cancellation_date_post 
ON mldb.billspayment_transaction(cancellation_date, post_transaction);

-- Index for search operations
CREATE INDEX idx_branch_id 
ON mldb.billspayment_transaction(branch_id);

CREATE INDEX idx_outlet 
ON mldb.billspayment_transaction(outlet);

CREATE INDEX idx_region 
ON mldb.billspayment_transaction(region);

CREATE INDEX idx_reference_no 
ON mldb.billspayment_transaction(reference_no);

-- Covering index for common queries (optional - improves SELECT performance)
CREATE INDEX idx_covering_unposted 
ON mldb.billspayment_transaction(
    post_transaction, 
    datetime, 
    cancellation_date, 
    branch_id, 
    outlet, 
    region, 
    reference_no, 
    amount_paid, 
    charge_to_partner, 
    charge_to_customer
);

-- To check existing indexes:
-- SHOW INDEX FROM mldb.billspayment_transaction;

-- To analyze query performance before/after:
-- EXPLAIN SELECT * FROM mldb.billspayment_transaction WHERE post_transaction = 'unposted' AND datetime BETWEEN '2025-01-01' AND '2025-01-31';
