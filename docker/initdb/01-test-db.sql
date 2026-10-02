-- Runs once on the FIRST start of an empty postgres data volume.
-- Creates the database the PHPUnit suite uses (see .env.example).
-- On an existing volume, create it by hand instead:
--   docker exec -it 3awedlou-postgres psql -U postgres -c 'CREATE DATABASE db_3awedlou_test'
CREATE DATABASE db_3awedlou_test;
