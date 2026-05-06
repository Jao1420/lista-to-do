CREATE DATABASE IF NOT EXISTS `auditoria` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `auditoria`;

CREATE TABLE IF NOT EXISTS audits (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    linha VARCHAR(50) NOT NULL,
    data_auditoria DATE NOT NULL,
    cliente VARCHAR(120) NOT NULL,
    responsavel VARCHAR(120) NOT NULL,
    data_limite DATE NOT NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_audits_deadline (data_limite)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS checklist_templates (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    titulo VARCHAR(255) NOT NULL,
    categoria VARCHAR(100) DEFAULT NULL,
    ordem INT NOT NULL DEFAULT 0,
    ativo TINYINT(1) NOT NULL DEFAULT 1,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    auditoria_id BIGINT UNSIGNED NOT NULL,
    template_id BIGINT UNSIGNED NULL,
    titulo VARCHAR(255) NOT NULL,
    categoria VARCHAR(100) DEFAULT NULL,
    responsavel VARCHAR(120) NOT NULL,
    data_prevista DATE NOT NULL,
    data_limite_auditoria DATE NOT NULL,
    status ENUM('nao_iniciado', 'em_andamento', 'em_revisao', 'concluido', 'bloqueado') NOT NULL DEFAULT 'nao_iniciado',
    prioridade ENUM('baixa', 'media', 'alta') NOT NULL DEFAULT 'media',
    ordem_card INT NOT NULL DEFAULT 0,
    observacao TEXT NULL,
    concluido_em DATETIME NULL,
    criado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    atualizado_em TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_items_audit FOREIGN KEY (auditoria_id) REFERENCES audits(id) ON DELETE CASCADE,
    CONSTRAINT fk_items_template FOREIGN KEY (template_id) REFERENCES checklist_templates(id) ON DELETE SET NULL,
    CONSTRAINT chk_item_date_limit CHECK (data_prevista <= data_limite_auditoria),
    INDEX idx_items_status (status),
    INDEX idx_items_plan_date (data_prevista),
    INDEX idx_items_audit_position (auditoria_id, ordem_card)
) ENGINE=InnoDB;

INSERT INTO checklist_templates (titulo, categoria, ordem)
SELECT * FROM (
    SELECT 'Atualização – Fluxo – FMA – PC', 'Processo', 10 UNION ALL
    SELECT 'Matriz Versatilidade', 'Gestao', 20 UNION ALL
    SELECT 'Check Setup', 'Processo', 30 UNION ALL
    SELECT 'Calibração', 'Qualidade', 40 UNION ALL
    SELECT 'Instrução de Trabalho', 'Qualidade', 50 UNION ALL
    SELECT 'Relatório / Stencil / Solda / Ferro de Solda / Seletiva / Perfil / Raio-X', 'Qualidade', 60 UNION ALL
    SELECT '5S / Linhas / Sala de Stencil / Trouble / Datario / Limpeza caixas / Magazines', 'Excelencia Operacional', 70 UNION ALL
    SELECT 'Manutenção -> MP / MTTA / MTBF', 'Manutencao', 80 UNION ALL
    SELECT 'Treinamentos Operacionais -> IPC 610 - AOI', 'Pessoas', 90 UNION ALL
    SELECT 'MSA (CPK / RR / Strain gauge)', 'Qualidade', 100 UNION ALL
    SELECT 'Amostra Padrão', 'Qualidade', 110 UNION ALL
    SELECT 'Identificação de Conforme e não Conforme', 'Qualidade', 120 UNION ALL
    SELECT 'KPM na Femea', 'Processo', 130 UNION ALL
    SELECT 'TPPA', 'Qualidade', 140 UNION ALL
    SELECT 'Procedimentos MSL', 'Qualidade', 150 UNION ALL
    SELECT 'Rota de Cimple', 'Logistica', 160 UNION ALL
    SELECT 'Procedimento de Pasta de Solda', 'Processo', 170 UNION ALL
    SELECT 'Relatório e Schedule', 'Gestao', 180 UNION ALL
    SELECT 'Reclamação de Cliente Externo', 'Cliente', 190 UNION ALL
    SELECT 'Trouble -> ASTRO', 'Sistemas', 200 UNION ALL
    SELECT 'DRY BOX', 'Logistica', 210 UNION ALL
    SELECT 'PARAMETROS SPI (ALTURA, VOLUME, ÁREA)', 'Processo', 220 UNION ALL
    SELECT 'ESD', 'Qualidade', 230
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM checklist_templates LIMIT 1);
