<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260929200653 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE filament_production (id UUID NOT NULL, batch_code VARCHAR(32) DEFAULT NULL, diameter_target DOUBLE PRECISION NOT NULL, diameter_actual DOUBLE PRECISION DEFAULT NULL, diameter_samples JSON DEFAULT NULL, weight_grams DOUBLE PRECISION DEFAULT NULL, length_meters DOUBLE PRECISION DEFAULT NULL, duration_minutes DOUBLE PRECISION DEFAULT NULL, material VARCHAR(32) NOT NULL, color_name VARCHAR(64) DEFAULT NULL, color_hex VARCHAR(7) DEFAULT NULL, quality VARCHAR(255) NOT NULL, notes TEXT DEFAULT NULL, produced_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, session_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_FC72E10EE771DA67 ON filament_production (batch_code)');
        $this->addSql('CREATE INDEX idx_production_session ON filament_production (session_id)');
        $this->addSql('CREATE INDEX idx_production_produced_at ON filament_production (produced_at)');
        $this->addSql('COMMENT ON COLUMN filament_production.weight_grams IS \'grams\'');
        $this->addSql('COMMENT ON COLUMN filament_production.length_meters IS \'meters\'');
        $this->addSql('CREATE TABLE machine (status VARCHAR(255) NOT NULL, id UUID NOT NULL, name VARCHAR(120) NOT NULL, identifier VARCHAR(64) NOT NULL, last_seen_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, owner_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_1505DF84772E836A ON machine (identifier)');
        $this->addSql('CREATE INDEX idx_machine_status ON machine (status)');
        $this->addSql('CREATE INDEX idx_machine_owner ON machine (owner_id)');
        $this->addSql('CREATE TABLE machine_session (id UUID NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, ended_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, status VARCHAR(255) NOT NULL, material_input DOUBLE PRECISION DEFAULT NULL, material_output DOUBLE PRECISION DEFAULT NULL, notes TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, machine_id UUID NOT NULL, operator_id UUID DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_session_machine_started ON machine_session (machine_id, started_at)');
        $this->addSql('CREATE INDEX idx_session_status ON machine_session (status)');
        $this->addSql('CREATE INDEX IDX_D63B4564F6B75B26 ON machine_session (machine_id)');
        $this->addSql('CREATE INDEX IDX_D63B4564584598A3 ON machine_session (operator_id)');
        $this->addSql('COMMENT ON COLUMN machine_session.material_input IS \'grams\'');
        $this->addSql('COMMENT ON COLUMN machine_session.material_output IS \'grams\'');
        $this->addSql('CREATE TABLE machine_telemetry (id UUID NOT NULL, temperature DOUBLE PRECISION DEFAULT NULL, target_temperature DOUBLE PRECISION DEFAULT NULL, heater_state BOOLEAN DEFAULT NULL, motor_state BOOLEAN DEFAULT NULL, motor_speed SMALLINT DEFAULT NULL, fan_state BOOLEAN DEFAULT NULL, filament_speed DOUBLE PRECISION DEFAULT NULL, filament_diameter DOUBLE PRECISION DEFAULT NULL, energy_consumption DOUBLE PRECISION DEFAULT NULL, extra JSON DEFAULT NULL, recorded_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, machine_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_telemetry_machine_recorded ON machine_telemetry (machine_id, recorded_at)');
        $this->addSql('CREATE INDEX idx_telemetry_recorded ON machine_telemetry (recorded_at)');
        $this->addSql('CREATE INDEX IDX_D9DA0DFEF6B75B26 ON machine_telemetry (machine_id)');
        $this->addSql('COMMENT ON TABLE machine_telemetry IS \'IoT telemetry samples (phase 2 ingest)\'');
        $this->addSql('COMMENT ON COLUMN machine_telemetry.temperature IS \'°C\'');
        $this->addSql('COMMENT ON COLUMN machine_telemetry.target_temperature IS \'°C\'');
        $this->addSql('COMMENT ON COLUMN machine_telemetry.motor_speed IS \'RPM\'');
        $this->addSql('COMMENT ON COLUMN machine_telemetry.filament_speed IS \'mm/s\'');
        $this->addSql('COMMENT ON COLUMN machine_telemetry.filament_diameter IS \'mm\'');
        $this->addSql('COMMENT ON COLUMN machine_telemetry.energy_consumption IS \'kWh (cumulative)\'');
        $this->addSql('CREATE TABLE recycling_session (id UUID NOT NULL, input_material VARCHAR(32) NOT NULL, input_mass_grams DOUBLE PRECISION DEFAULT NULL, output_material VARCHAR(32) DEFAULT NULL, output_mass_grams DOUBLE PRECISION DEFAULT NULL, duration_minutes DOUBLE PRECISION DEFAULT NULL, avg_temperature DOUBLE PRECISION DEFAULT NULL, notes TEXT DEFAULT NULL, recycled_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, session_id UUID NOT NULL, machine_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_recycling_session ON recycling_session (session_id)');
        $this->addSql('CREATE INDEX idx_recycling_recycled_at ON recycling_session (recycled_at)');
        $this->addSql('CREATE INDEX IDX_AEFF90CDF6B75B26 ON recycling_session (machine_id)');
        $this->addSql('COMMENT ON COLUMN recycling_session.input_mass_grams IS \'grams\'');
        $this->addSql('COMMENT ON COLUMN recycling_session.output_mass_grams IS \'grams\'');
        $this->addSql('COMMENT ON COLUMN recycling_session.avg_temperature IS \'°C\'');
        $this->addSql('CREATE TABLE "user" (id UUID NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, name VARCHAR(120) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_8D93D649E7927C74 ON "user" (email)');
        $this->addSql('CREATE INDEX idx_user_created_at ON "user" (created_at)');
        $this->addSql('CREATE TABLE messenger_messages (id BIGINT GENERATED BY DEFAULT AS IDENTITY NOT NULL, body TEXT NOT NULL, headers TEXT NOT NULL, queue_name VARCHAR(190) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, available_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, delivered_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX IDX_75EA56E0FB7336F0E3BD61CE16BA31DBBF396750 ON messenger_messages (queue_name, available_at, delivered_at, id)');
        $this->addSql('ALTER TABLE filament_production ADD CONSTRAINT FK_FC72E10E613FECDF FOREIGN KEY (session_id) REFERENCES machine_session (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE machine ADD CONSTRAINT FK_1505DF847E3C61F9 FOREIGN KEY (owner_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE machine_session ADD CONSTRAINT FK_D63B4564F6B75B26 FOREIGN KEY (machine_id) REFERENCES machine (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE machine_session ADD CONSTRAINT FK_D63B4564584598A3 FOREIGN KEY (operator_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE');
        $this->addSql('ALTER TABLE machine_telemetry ADD CONSTRAINT FK_D9DA0DFEF6B75B26 FOREIGN KEY (machine_id) REFERENCES machine (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recycling_session ADD CONSTRAINT FK_AEFF90CD613FECDF FOREIGN KEY (session_id) REFERENCES machine_session (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE recycling_session ADD CONSTRAINT FK_AEFF90CDF6B75B26 FOREIGN KEY (machine_id) REFERENCES machine (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE filament_production DROP CONSTRAINT FK_FC72E10E613FECDF');
        $this->addSql('ALTER TABLE machine DROP CONSTRAINT FK_1505DF847E3C61F9');
        $this->addSql('ALTER TABLE machine_session DROP CONSTRAINT FK_D63B4564F6B75B26');
        $this->addSql('ALTER TABLE machine_session DROP CONSTRAINT FK_D63B4564584598A3');
        $this->addSql('ALTER TABLE machine_telemetry DROP CONSTRAINT FK_D9DA0DFEF6B75B26');
        $this->addSql('ALTER TABLE recycling_session DROP CONSTRAINT FK_AEFF90CD613FECDF');
        $this->addSql('ALTER TABLE recycling_session DROP CONSTRAINT FK_AEFF90CDF6B75B26');
        $this->addSql('DROP TABLE filament_production');
        $this->addSql('DROP TABLE machine');
        $this->addSql('DROP TABLE machine_session');
        $this->addSql('DROP TABLE machine_telemetry');
        $this->addSql('DROP TABLE recycling_session');
        $this->addSql('DROP TABLE "user"');
        $this->addSql('DROP TABLE messenger_messages');
    }
}
