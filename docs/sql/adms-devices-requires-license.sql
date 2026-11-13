/*
  One-time DBA-only, idempotent deployment of the ADMS device license flag.

  Execute with a SQL Server administrative login against Att2000_V4.
  NEVER run this on the old application server or on another database.
  This script changes ONLY Att2000_V4.adms.devices; dbo.Device stays untouched.
  Do not grant ALTER, db_owner, db_ddladmin or dbo rights to the ADMS app.

  After this succeeds, rerun the targeted Laravel migration, which will
  detect the existing column and record the migration as complete.
*/
USE [Att2000_V4];
GO

SET XACT_ABORT ON;
SET NOCOUNT ON;

IF DB_NAME() <> N'Att2000_V4'
    THROW 51000, 'Wrong database; expected Att2000_V4.', 1;

IF OBJECT_ID(N'adms.devices', N'U') IS NULL
    THROW 51001, 'Operational adms.devices table not found; no changes made.', 1;

IF COL_LENGTH(N'adms.devices', N'requires_license') IS NULL
BEGIN
    BEGIN TRANSACTION;
        ALTER TABLE [adms].[devices]
            ADD [requires_license] bit NOT NULL
                CONSTRAINT [DF_adms_devices_requires_license]
                DEFAULT (0) WITH VALUES;
    COMMIT TRANSACTION;
END;

SELECT
    DB_NAME() AS database_name,
    OBJECT_SCHEMA_NAME(OBJECT_ID(N'adms.devices', N'U')) AS schema_name,
    COL_LENGTH(N'adms.devices', N'requires_license') AS license_flag_bytes,
    (SELECT COUNT_BIG(*) FROM [adms].[devices]) AS operational_device_count;
GO
