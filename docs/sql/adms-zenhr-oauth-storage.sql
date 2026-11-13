/*
AT2 ZenHR OAuth secure token store; one administrative provisioning operation.

Only run this within SQL Server for the existing Att2000_V4 database.
Does NOT modify dbo.Employee, dbo.Attendance, dbo.Device or biometric templates.
The ADMS application SQL login must receive SELECT / INSERT / UPDATE on
adms.zenhr_connections only if those privileges are not already granted.
Do NOT grant schema-wide ALTER, db_owner, db_ddladmin or DELETE.
Never save plaintext tokens into SQL, GitHub, logs or messages.
*/
USE [Att2000_V4];
GO
SET XACT_ABORT ON;
IF DB_NAME() <> N'Att2000_V4'
    THROW 51030, 'Unexpected database', 1;
IF SCHEMA_ID(N'adms') IS NULL
    THROW 51031, 'ADMS schema missing', 1;

IF OBJECT_ID(N'adms.zenhr_connections', N'U') IS NULL
BEGIN
    CREATE TABLE [adms].[zenhr_connections] (
        [id] int NOT NULL,
        [access_token_encrypted] nvarchar(max) NOT NULL,
        [refresh_token_encrypted] nvarchar(max) NULL,
        [token_expires_at] datetime2(0) NULL,
        [company_id] bigint NULL,
        [authorized_scopes] nvarchar(max) NULL,
        [connected_by] bigint NULL,
        [last_verified_at] datetime2(0) NULL,
        [created_at] datetime2(0) NOT NULL
            CONSTRAINT [DF_adms_zenhr_created_at] DEFAULT (SYSUTCDATETIME()),
        [updated_at] datetime2(0) NOT NULL
            CONSTRAINT [DF_adms_zenhr_updated_at] DEFAULT (SYSUTCDATETIME()),
        CONSTRAINT [PK_adms_zenhr_connections] PRIMARY KEY ([id]),
        CONSTRAINT [CK_adms_zenhr_single] CHECK ([id] = 1)
    );
END;

SELECT
    DB_NAME() AS database_name,
    OBJECT_SCHEMA_NAME(OBJECT_ID(N'adms.zenhr_connections', N'U')) AS schema_name,
    OBJECT_ID(N'adms.zenhr_connections', N'U') AS table_object_id;
GO
