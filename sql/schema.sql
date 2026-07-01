CREATE SCHEMA IF NOT EXISTS polimi;

DROP TABLE IF EXISTS CustomerSession CASCADE;

CREATE TABLE
    CustomerSession (
        id SERIAL NOT NULL,
        imsi VARCHAR(15) NOT NULL,
        ue_id INT NOT NULL,
        amf_id INT NOT NULL,
        rnti VARCHAR(20) NOT NULL,
        ipv4 VARCHAR(15),
        "start" TIMESTAMP NOT NULL,
        "end" TIMESTAMP,
        PRIMARY KEY (id, imsi)
    );

DROP TABLE IF EXISTS CustomerPerformance CASCADE;

CREATE TABLE
    CustomerPerformance (
        imsi VARCHAR(15) NOT NULL,
        session_id INT NOT NULL,
        tx_kbytes INT NOT NULL,
        rx_kbytes INT NOT NULL,
        PRIMARY KEY (imsi, session_id),
        FOREIGN KEY (imsi, session_id) REFERENCES CustomerSession (imsi, id) ON UPDATE CASCADE ON DELETE CASCADE
    );


-- "User" is a reserved word in Postgres, so it requires to be expressed with commas
DROP TABLE IF EXISTS "User" CASCADE;
CREATE TABLE "User"
(
    id          VARCHAR(200)  NOT NULL PRIMARY KEY,
    name        VARCHAR(255)  NOT NULL,
    surname     VARCHAR(255)  NOT NULL,
    email       VARCHAR(255)  NOT NULL,
    salt        VARCHAR(60)   NOT NULL,
    SHAPassword VARCHAR(64)   NOT NULL
);

CREATE EXTENSION IF NOT EXISTS pgcrypto;

CREATE OR REPLACE PROCEDURE createUser(
    pEmail VARCHAR(255),
    pPassword VARCHAR(255),
    pSurname VARCHAR(255),
    pName VARCHAR(255)
)
LANGUAGE plpgsql
AS $$
DECLARE
    v_salt VARCHAR;
    v_hash VARCHAR;
    v_cod VARCHAR;
BEGIN
    v_salt := crypt(random()::text, gen_salt('bf'));
    v_hash := encode(digest(v_salt || pPassword, 'sha256'), 'hex');
    /*
     User Id is defined through a combination of 3 informations, on which a SHA256 textual-hash is calculated
     1) email
     2) number of "epoch" seconds from 01/01/1970
     3) a random number
     */
    v_cod := encode(digest(pEmail || extract(epoch from now())::text || random()::text, 'sha256'), 'hex');

    INSERT INTO "User"(id, name, surname, email, salt, SHAPassword)
    VALUES (v_cod, pName, pSurname, pEmail, v_salt, v_hash);

    COMMIT;
END;
$$;

DROP TABLE IF EXISTS Tenant CASCADE;
CREATE TABLE Tenant
(
    id     SERIAL PRIMARY KEY,
    PLMN   VARCHAR(6) NOT NULL UNIQUE,
    subnet VARCHAR(18) NOT NULL UNIQUE
);

DROP TABLE IF EXISTS UserPermissions CASCADE;
CREATE TABLE UserPermissions
(
    id        SERIAL,
    userId    VARCHAR(200) NOT NULL,
    tenant    VARCHAR(6) NOT NULL,
    PRIMARY KEY (id, userId, tenant),
    FOREIGN KEY (userId) REFERENCES "User" (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    FOREIGN KEY (tenant) REFERENCES Tenant (PLMN)
        ON UPDATE CASCADE ON DELETE CASCADE
);

DROP TABLE IF EXISTS TenantConsumption CASCADE;
CREATE TABLE TenantConsumption
(
    id              SERIAL,
    tenant          VARCHAR(6) NOT NULL,
    cpu_usage       INT NOT NULL,
    dynamic_watts   REAL NOT NULL,
    fixed_watts     REAL NOT NULL,
    start           TIMESTAMP NOT NULL,
    "end"           TIMESTAMP NOT NULL,
    PRIMARY KEY (id, tenant),
    FOREIGN KEY (tenant) REFERENCES Tenant (PLMN)
        ON UPDATE CASCADE ON DELETE CASCADE
);


CALL createUser('test@polimi.it', 'polimi', 'Surname', 'Name');
