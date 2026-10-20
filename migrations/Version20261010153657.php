<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * AI agent phase 1 (docs/ai-agent-roadmap.md step B1):
 *   agent_conversation (owner-scoped, soft delete)
 *   agent_message      (per-conversation turns, metadata-only tool_calls)
 *   agent_token_usage  (daily per-user token cap accounting)
 */
final class Version20261010153657 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'AI agent phase 1: conversations, messages, daily token usage';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE agent_conversation (id UUID NOT NULL, title TEXT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, deleted_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_agent_conv_user_updated ON agent_conversation (user_id, updated_at)');
        $this->addSql('CREATE INDEX IDX_FCC2A922A76ED395 ON agent_conversation (user_id)');
        $this->addSql('CREATE TABLE agent_message (id UUID NOT NULL, role VARCHAR(16) NOT NULL, content TEXT NOT NULL, tool_calls JSON DEFAULT NULL, usage_input INT DEFAULT 0 NOT NULL, usage_output INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, conversation_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE INDEX idx_agent_msg_conv_created ON agent_message (conversation_id, created_at)');
        $this->addSql('CREATE INDEX IDX_64EE52D59AC0396 ON agent_message (conversation_id)');
        $this->addSql('CREATE TABLE agent_token_usage (id UUID NOT NULL, day VARCHAR(10) NOT NULL, tokens_used INT DEFAULT 0 NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, user_id UUID NOT NULL, PRIMARY KEY (id))');
        $this->addSql('CREATE UNIQUE INDEX uq_agent_usage_user_day ON agent_token_usage (user_id, day)');
        $this->addSql('CREATE INDEX IDX_E3FB0171A76ED395 ON agent_token_usage (user_id)');
        $this->addSql('ALTER TABLE agent_conversation ADD CONSTRAINT FK_FCC2A922A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_message ADD CONSTRAINT FK_64EE52D59AC0396 FOREIGN KEY (conversation_id) REFERENCES agent_conversation (id) ON DELETE CASCADE NOT DEFERRABLE');
        $this->addSql('ALTER TABLE agent_token_usage ADD CONSTRAINT FK_E3FB0171A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE agent_conversation DROP CONSTRAINT FK_FCC2A922A76ED395');
        $this->addSql('ALTER TABLE agent_message DROP CONSTRAINT FK_64EE52D59AC0396');
        $this->addSql('ALTER TABLE agent_token_usage DROP CONSTRAINT FK_E3FB0171A76ED395');
        $this->addSql('DROP TABLE agent_conversation');
        $this->addSql('DROP TABLE agent_message');
        $this->addSql('DROP TABLE agent_token_usage');
    }
}
