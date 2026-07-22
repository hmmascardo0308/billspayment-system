CREATE TABLE IF NOT EXISTS mldb.trl_attachments (
    id INT NOT NULL AUTO_INCREMENT,
    trl_no INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(150) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    file_data LONGBLOB NOT NULL,
    created_by VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_trl_attachments_trl_no (trl_no),
    CONSTRAINT fk_trl_attachments_trl
        FOREIGN KEY (trl_no) REFERENCES mldb.trl (trl_no)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
