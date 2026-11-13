/*
One-time DBA script. Only Att2000_V4.adms metadata / outbox storage.
AT2 console collects original dbo.Attendance and app-only adms.attendance_logs
into one deduplicated outbox. No INSERT/UPDATE/DELETE against dbo.*.
No request to ZenHR is made by this SQL.
*/
USE [Att2000_V4];
GO
SET XACT_ABORT ON;
IF DB_NAME() <> N'Att2000_V4'
    THROW 51050, 'Unexpected database; abort', 1;
IF SCHEMA_ID(N'adms') IS NULL
    THROW 51051, 'Missing adms schema; abort', 1;

IF OBJECT_ID(N'adms.zenhr_attendance_outbox', N'U') IS NULL
BEGIN
    CREATE TABLE [adms].[zenhr_attendance_outbox] (
        [event_key] char(64) NOT NULL,
        [source_kind] varchar(8) NOT NULL,
        [source_id] bigint NOT NULL,
        [badge_number] nvarchar(80) NOT NULL,
        [device_serial] nvarchar(64) NOT NULL,
        [punched_at_local] datetime2(0) NOT NULL,
        [direction] nvarchar(24) NOT NULL,
        [verify_type] nvarchar(32) NULL,
        [delivery_state] varchar(24) NOT NULL
            CONSTRAINT [DF_adms_zenhr_outbox_state] DEFAULT ('pending'),
        [attempts] int NOT NULL CONSTRAINT [DF_adms_zenhr_outbox_attempts] DEFAULT (0),
        [last_http_status] int NULL,
        [last_error_code] nvarchar(80) NULL,
        [last_attempt_at] datetime2(0) NULL,
        [delivered_at] datetime2(0) NULL,
        [created_at] datetime2(0) NOT NULL
            CONSTRAINT [DF_adms_zenhr_outbox_created] DEFAULT SYSUTCDATETIME(),
        [updated_at] datetime2(0) NOT NULL
            CONSTRAINT [DF_adms_zenhr_outbox_updated] DEFAULT SYSUTCDATETIME(),
        CONSTRAINT [PK_adms_zenhr_attendance_outbox] PRIMARY KEY ([event_key]),
        CONSTRAINT [UQ_adms_zenhr_attendance_source] UNIQUE ([source_kind], [source_id]),
        CONSTRAINT [CK_adms_zenhr_attendance_source] CHECK ([source_kind] IN ('dbo', 'adms')),
        CONSTRAINT [CK_adms_zenhr_attendance_state]
            CHECK ([delivery_state] IN ('pending', 'needs_review', 'inflight', 'sent', 'failed', 'unknown'))
    );
    CREATE INDEX [IX_adms_zenhr_attendance_pending]
        ON [adms].[zenhr_attendance_outbox] ([delivery_state], [punched_at_local]);
END;

IF OBJECT_ID(N'adms.zenhr_attendance_cursors', N'U') IS NULL
BEGIN
    CREATE TABLE [adms].[zenhr_attendance_cursors] (
        [id] int NOT NULL CONSTRAINT [PK_adms_zenhr_attendance_cursors] PRIMARY KEY,
        [last_dbo_id] bigint NOT NULL CONSTRAINT [DF_adms_zenhr_cursor_dbo] DEFAULT (0),
        [last_adms_id] bigint NOT NULL CONSTRAINT [DF_adms_zenhr_cursor_adms] DEFAULT (0),
        [updated_at] datetime2(0) NOT NULL
            CONSTRAINT [DF_adms_zenhr_cursor_updated] DEFAULT SYSUTCDATETIME(),
        CONSTRAINT [CK_adms_zenhr_cursor_one] CHECK ([id] = 1)
    );
END;

IF NOT EXISTS (SELECT 1 FROM [adms].[zenhr_attendance_cursors] WHERE [id] = 1)
    INSERT INTO [adms].[zenhr_attendance_cursors] ([id], [last_dbo_id], [last_adms_id])
    VALUES (1, 0, 0);

-- Known AT2 app SQL login. No schema alteration or dbo write grants.
IF DATABASE_PRINCIPAL_ID(N'adms_v2_app') IS NOT NULL
BEGIN
    GRANT SELECT, INSERT, UPDATE ON [adms].[zenhr_attendance_outbox] TO [adms_v2_app];
    GRANT SELECT, UPDATE ON [adms].[zenhr_attendance_cursors] TO [adms_v2_app];
END;
SELECT OBJECT_ID(N'adms.zenhr_attendance_outbox',N'U') AS outbox_id,
       OBJECT_ID(N'adms.zenhr_attendance_cursors',N'U') AS cursor_id;
GO
