/* Run as DBA in Att2000_V4. No UPDATE/DELETE on dbo.Employee is granted. */
USE [Att2000_V4];
GO
SET XACT_ABORT ON;
IF DB_NAME() <> N'Att2000_V4' OR SCHEMA_ID(N'adms') IS NULL
    THROW 51071, 'Expected original database with adms schema', 1;
IF OBJECT_ID(N'adms.employee_registration_requests', N'U') IS NULL
BEGIN
    CREATE TABLE adms.employee_registration_requests (
        id bigint IDENTITY(1,1) PRIMARY KEY,
        device_id bigint NOT NULL,
        employee_code nvarchar(32) NOT NULL,
        incoming_name nvarchar(160) NOT NULL,
        existing_name nvarchar(160) NULL,
        state varchar(24) NOT NULL,
        verified_source bit NOT NULL DEFAULT 0,
        reviewed_by bigint NULL,
        created_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        updated_at datetime2(0) NOT NULL DEFAULT SYSUTCDATETIME(),
        CONSTRAINT UQ_adms_employee_registration UNIQUE(device_id,employee_code,incoming_name),
        CONSTRAINT CK_adms_employee_registration_state CHECK(state IN ('pending','duplicate','registered','existing','rejected'))
    );
    CREATE INDEX IX_adms_employee_registration_review ON adms.employee_registration_requests(state,employee_code,device_id);
END;
GO
CREATE OR ALTER PROCEDURE adms.register_device_employee
    @badge nvarchar(32), @name nvarchar(160), @serial nvarchar(80)
WITH EXECUTE AS 'dbo'
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @badge IS NULL OR LEN(@badge) NOT BETWEEN 1 AND 32 OR @badge COLLATE Latin1_General_100_BIN2 LIKE N'%[^0-9]%'
       OR @name IS NULL OR LEN(@name) NOT BETWEEN 1 AND 40
       OR @name COLLATE Latin1_General_100_BIN2 LIKE N'%[^A-Za-z0-9 ._-]%'
       OR NOT EXISTS(SELECT 1 FROM dbo.Device WHERE DeviceSerialNumber=@serial)
        THROW 51072, 'Invalid device employee registration', 1;
    DECLARE @existing varchar(40), @state varchar(24), @found bit=0;
    BEGIN TRANSACTION;
    SELECT @existing=NAME,@found=1 FROM dbo.Employee WITH(UPDLOCK,HOLDLOCK) WHERE BadgeNumber=@badge;
    IF @found=0
    BEGIN
        INSERT dbo.Employee(BadgeNumber,NAME) VALUES(@badge,CONVERT(varchar(40),@name));
        SET @state='registered';
        SET @existing=CONVERT(varchar(40),@name);
    END
    ELSE
        SET @state=CASE WHEN LOWER(TRIM(@existing))=LOWER(TRIM(@name)) THEN 'existing' ELSE 'duplicate' END;
    COMMIT TRANSACTION;
    SELECT @state AS state,@existing AS existing_name;
END;
GO
GRANT SELECT, INSERT, UPDATE ON adms.employee_registration_requests TO [adms_v2_app];
GRANT EXECUTE ON adms.register_device_employee TO [adms_attendance_writer];
GO
