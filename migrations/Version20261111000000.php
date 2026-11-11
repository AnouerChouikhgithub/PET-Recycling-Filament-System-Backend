<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Device acknowledgements: only a real command_ack received over MQTT can
 * set these columns. Nullable because older rows were never acknowledged.
 */
final class Version20261111000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Machine command audit: device acknowledgement timestamp/status';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE machine_command_audit ADD acknowledged_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE machine_command_audit ADD ack_status VARCHAR(16) DEFAULT NULL');
        $this
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE machine_command_audit DROP acknowledged_at');
        $this->addSql('ALTER TABLE machine_command_audit DROP ack_status');
    }
}
