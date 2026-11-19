/* Run once as DBA in Att2000_V4. Only new adms metadata; no biometric bytes or dbo writes. */
USE [Att2000_V4];
GO
SET XACT_ABORT ON;
IF DB_NAME() <> N'Att2000_V4' OR SCHEMA_ID(N'adms') IS NULL
    THROW 51070, 'Expected Att2000_V4 with adms schema', 1;
IF OBJECT_ID(N'adms.fingerprint_sync_reviews', N'U') IS NULL
BEGIN
    CREATE TABLE [adms].[fingerprint_sync_reviews] (
        [id] bigint IDENTITY(1,1) NOT NULL PRIMARY KEY,
        [source_biometric_id] bigint NOT NULL,
        [version_hash] char(64) NOT NULL,
        [source_device_id] bigint NOT NULL,
        [target_device_id] bigint NOT NULL,
        [target_serial] nvarchar(80) NOT NULL,
        [employee_code] nvarchar(80) NOT NULL,
        [user_name] nvarchar(160) NOT NULL,
        [finger_slot] tinyint NOT NULL,
        [approved_by] bigint NOT NULL,
        [state] varchar(24) NOT NULL,
        [sent_at] datetime2(0) NULL,
        [last_result] nvarchar(80) NULL,
        [created_at] datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        [updated_at] datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT [UQ_adms_fp_review_version_target] UNIQUE ([source_biometric_id],[version_hash],[target_device_id]),
        CONSTRAINT [CK_adms_fp_review_id] CHECK ([id] BETWEEN 1 AND 49999999),
        CONSTRAINT [CK_adms_fp_review_slot] CHECK ([finger_slot] BETWEEN 0 AND 9),
        CONSTRAINT [CK_adms_fp_review_state] CHECK ([state] IN ('rejected','pending_user','sent_user','pending_fp','sent_fp','done','already_present','failed','unknown','conflict','stale'))
    );
    CREATE INDEX [IX_adms_fp_review_target] ON [adms].[fingerprint_sync_reviews] ([target_device_id],[state],[id]);
END;
IF DATABASE_PRINCIPAL_ID(N'adms_v2_app') IS NOT NULL
    GRANT SELECT, INSERT, UPDATE ON [adms].[fingerprint_sync_reviews] TO [adms_v2_app];
SELECT OBJECT_ID(N'adms.fingerprint_sync_reviews', N'U') AS fingerprint_review_table_id;
GO
