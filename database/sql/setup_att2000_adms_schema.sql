-- ONE DATABASE: Att2000_V4. Run with sqlcmd -b as SQL Server sa.
-- ADDITIVE ONLY: Does not modify or remove any original dbo tables.
USE [Att2000_V4];
GO
IF NOT EXISTS (SELECT 1 FROM sys.schemas WHERE name = N'adms')
    EXEC(N'CREATE SCHEMA [adms] AUTHORIZATION [dbo]');
GO
IF DATABASE_PRINCIPAL_ID(N'adms_v2_setup') IS NULL
    CREATE USER [adms_v2_setup] FOR LOGIN [adms_v2_setup] WITH DEFAULT_SCHEMA=[adms];
GO
IF DATABASE_PRINCIPAL_ID(N'adms_v2_app') IS NULL
    CREATE USER [adms_v2_app] FOR LOGIN [adms_v2_app] WITH DEFAULT_SCHEMA=[adms];
GO
ALTER USER [adms_v2_setup] WITH DEFAULT_SCHEMA=[adms];
ALTER USER [adms_v2_app] WITH DEFAULT_SCHEMA=[adms];
GO
-- Setup login can run Laravel migrations ONLY inside adms schema.
GRANT CREATE TABLE TO [adms_v2_setup];
GRANT CONTROL ON SCHEMA::[adms] TO [adms_v2_setup];

-- Runtime login can manage app data but not ALTER/DROP schema objects.
GRANT SELECT, INSERT, UPDATE, DELETE ON SCHEMA::[adms] TO [adms_v2_app];

-- Harden against writes to historical source by either Laravel login.
DENY INSERT, UPDATE, DELETE ON SCHEMA::[dbo] TO [adms_v2_setup];
DENY INSERT, UPDATE, DELETE ON SCHEMA::[dbo] TO [adms_v2_app];
GO
SELECT name AS PrincipalName, default_schema_name AS DefaultSchema
FROM sys.database_principals
WHERE name IN (N'adms_v2_setup', N'adms_v2_app');
SELECT s.name AS SchemaName, t.name AS TableName
FROM sys.tables t INNER JOIN sys.schemas s ON s.schema_id=t.schema_id
WHERE s.name=N'adms'
ORDER BY t.name;
GO
