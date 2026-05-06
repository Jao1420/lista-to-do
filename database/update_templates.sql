-- Script para corrigir nomes dos templates caso o banco ja exista com dados antigos.
-- Execute este arquivo UMA vez no banco onde o schema.sql ja foi aplicado anteriormente.

USE `auditoria`;

UPDATE checklist_templates SET titulo = 'Atualização – Fluxo – FMA – PC'                             WHERE ordem = 10;
UPDATE checklist_templates SET titulo = 'Matriz Versatilidade'                                         WHERE ordem = 20;
UPDATE checklist_templates SET titulo = 'Check Setup'                                                  WHERE ordem = 30;
UPDATE checklist_templates SET titulo = 'Calibração'                                                   WHERE ordem = 40;
UPDATE checklist_templates SET titulo = 'Instrução de Trabalho'                                        WHERE ordem = 50;
UPDATE checklist_templates SET titulo = 'Relatório / Stencil / Solda / Ferro de Solda / Seletiva / Perfil / Raio-X' WHERE ordem = 60;
UPDATE checklist_templates SET titulo = '5S / Linhas / Sala de Stencil / Trouble / Datario / Limpeza caixas / Magazines' WHERE ordem = 70;
UPDATE checklist_templates SET titulo = 'Manutenção -> MP / MTTA / MTBF'                              WHERE ordem = 80;
UPDATE checklist_templates SET titulo = 'Treinamentos Operacionais -> IPC 610 - AOI'                  WHERE ordem = 90;
UPDATE checklist_templates SET titulo = 'MSA (CPK / RR / Strain gauge)'                               WHERE ordem = 100;
UPDATE checklist_templates SET titulo = 'Amostra Padrão'                                              WHERE ordem = 110;
UPDATE checklist_templates SET titulo = 'Identificação de Conforme e não Conforme'                    WHERE ordem = 120;
UPDATE checklist_templates SET titulo = 'KPM na Femea'                                                WHERE ordem = 130;
UPDATE checklist_templates SET titulo = 'TPPA'                                                         WHERE ordem = 140;
UPDATE checklist_templates SET titulo = 'Procedimentos MSL'                                            WHERE ordem = 150;
UPDATE checklist_templates SET titulo = 'Rota de Cimple'                                               WHERE ordem = 160;
UPDATE checklist_templates SET titulo = 'Procedimento de Pasta de Solda'                               WHERE ordem = 170;
UPDATE checklist_templates SET titulo = 'Relatório e Schedule'                                         WHERE ordem = 180;
UPDATE checklist_templates SET titulo = 'Reclamação de Cliente Externo'                                WHERE ordem = 190;
UPDATE checklist_templates SET titulo = 'Trouble -> ASTRO'                                             WHERE ordem = 200;
UPDATE checklist_templates SET titulo = 'DRY BOX'                                                      WHERE ordem = 210;
UPDATE checklist_templates SET titulo = 'PARAMETROS SPI (ALTURA, VOLUME, ÁREA)'                       WHERE ordem = 220;

-- Adiciona ESD se ainda nao existir
INSERT INTO checklist_templates (titulo, categoria, ordem)
SELECT 'ESD', 'Qualidade', 230
WHERE NOT EXISTS (SELECT 1 FROM checklist_templates WHERE ordem = 230);
